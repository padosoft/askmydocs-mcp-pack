<?php

namespace Padosoft\AskMyDocsMcpPack\Transports;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Padosoft\AskMyDocsMcpPack\Contracts\McpProtocolAwareTransportContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Support\McpProtocolEra;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Historical MCP HTTP+SSE transport.
 *
 * It keeps a GET event stream open, reads the server-advertised POST
 * endpoint, sends JSON-RPC messages there and receives responses on the
 * original stream. The POST endpoint must remain on the stream origin.
 */
final class LegacySseJsonRpcTransport implements McpProtocolAwareTransportContract
{
    private readonly ClientInterface $client;

    private ?McpProtocolEra $protocolEra = null;

    private ?string $protocolVersion = null;

    private ?string $sessionId = null;

    private ?int $lastStatusCode = null;

    /** @var array<string,list<string>> */
    private array $lastResponseHeaders = [];

    private ?StreamInterface $eventStream = null;

    private ?string $messageEndpoint = null;

    private string $eventBuffer = '';

    private int $bytesRead = 0;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        $configuredClient = $config['client'] ?? null;
        $this->client = $configuredClient instanceof ClientInterface
            ? $configuredClient
            : new Client;
    }

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        if (! $request->isRequest()) {
            throw new \InvalidArgumentException('LegacySseJsonRpcTransport::request() requires a JSON-RPC request message.');
        }

        $this->connect();
        $post = $this->post($request);
        $inline = $this->inlineResponse($post, $request->id);
        if ($inline !== null) {
            return $inline;
        }

        return $this->readResponse($request->id);
    }

    public function notify(JsonRpcMessage $notification): void
    {
        if (! $notification->isNotification()) {
            throw new \InvalidArgumentException('LegacySseJsonRpcTransport::notify() requires a JSON-RPC notification.');
        }

        $this->connect();
        $this->post($notification);
    }

    public function isHealthy(): bool
    {
        try {
            $this->guardEndpoint($this->endpoint());
            $response = $this->client->request('GET', $this->endpoint(), [
                'allow_redirects' => false,
                'headers' => $this->headers(),
                'http_errors' => false,
                'timeout' => $this->timeoutSeconds(),
            ]);

            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    public function useProtocol(McpProtocolEra $era, string $version): void
    {
        $this->protocolEra = $era;
        $this->protocolVersion = $version;
        if ($era === McpProtocolEra::Modern) {
            $this->clearSession();
        }
    }

    public function protocolEra(): ?McpProtocolEra
    {
        return $this->protocolEra;
    }

    public function protocolVersion(): ?string
    {
        return $this->protocolVersion;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function clearSession(): void
    {
        $this->sessionId = null;
        $this->eventStream?->close();
        $this->eventStream = null;
        $this->messageEndpoint = null;
        $this->eventBuffer = '';
        $this->bytesRead = 0;
    }

    public function lastStatusCode(): ?int
    {
        return $this->lastStatusCode;
    }

    /** @return array<string,list<string>> */
    public function lastResponseHeaders(): array
    {
        return $this->lastResponseHeaders;
    }

    private function connect(): void
    {
        if ($this->eventStream !== null && $this->messageEndpoint !== null) {
            return;
        }

        try {
            $this->guardEndpoint($this->endpoint());
            $response = $this->client->request('GET', $this->endpoint(), [
                'allow_redirects' => false,
                'headers' => $this->headers() + ['Accept' => 'text/event-stream'],
                'http_errors' => false,
                'stream' => true,
                'timeout' => $this->timeoutSeconds(),
            ]);
            $this->captureResponseState($response);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new McpTransportException("Legacy SSE MCP transport returned status {$response->getStatusCode()}.");
            }

            $this->eventStream = $response->getBody();
            while (($event = $this->nextEvent()) !== null) {
                if ($event['event'] !== 'endpoint') {
                    continue;
                }
                $this->messageEndpoint = $this->resolveMessageEndpoint($event['data']);

                return;
            }
        } catch (McpTransportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new McpTransportException("Legacy SSE MCP transport connection failed: {$e->getMessage()}", 0, $e);
        }

        throw new McpTransportException('Legacy SSE MCP transport did not advertise a message endpoint.');
    }

    private function post(JsonRpcMessage $message): ResponseInterface
    {
        if ($this->messageEndpoint === null) {
            throw new McpTransportException('Legacy SSE MCP transport is not connected.');
        }

        try {
            $this->guardEndpoint($this->messageEndpoint);
            $response = $this->client->request('POST', $this->messageEndpoint, [
                'allow_redirects' => false,
                'headers' => $this->headers() + ['Accept' => 'application/json, text/event-stream'],
                'http_errors' => false,
                'json' => $message->toArray(),
                'timeout' => $this->timeoutSeconds(),
            ]);
            $this->captureResponseState($response);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new McpTransportException("Legacy SSE MCP message endpoint returned status {$response->getStatusCode()}.");
            }

            return $response;
        } catch (McpTransportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new McpTransportException("Legacy SSE MCP message request failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function inlineResponse(ResponseInterface $response, string|int|null $expectedId): ?JsonRpcMessage
    {
        $body = (string) $response->getBody();
        $this->assertResponseSize($body);
        if (trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) && ($decoded['id'] ?? null) === $expectedId) {
            return JsonRpcMessage::fromArray($decoded);
        }

        return null;
    }

    private function readResponse(string|int|null $expectedId): JsonRpcMessage
    {
        while (($event = $this->nextEvent()) !== null) {
            if ($event['event'] === 'endpoint') {
                continue;
            }
            $decoded = json_decode($event['data'], true);
            if (is_array($decoded) && ($decoded['id'] ?? null) === $expectedId) {
                return JsonRpcMessage::fromArray($decoded);
            }
        }

        throw new McpTransportException('Legacy SSE MCP transport closed before the matching JSON-RPC response arrived.');
    }

    /** @return array{event:string,data:string}|null */
    private function nextEvent(): ?array
    {
        while (true) {
            if (preg_match('/^(.*?)\r?\n\r?\n/s', $this->eventBuffer, $matches) === 1) {
                $this->eventBuffer = substr($this->eventBuffer, strlen($matches[0]));

                return $this->parseEvent($matches[1]);
            }
            if ($this->eventStream === null || $this->eventStream->eof()) {
                if (trim($this->eventBuffer) === '') {
                    return null;
                }
                $frame = $this->eventBuffer;
                $this->eventBuffer = '';

                return $this->parseEvent($frame);
            }

            $chunk = $this->eventStream->read(8192);
            $this->bytesRead += strlen($chunk);
            if ($this->bytesRead > $this->maxResponseBytes()) {
                throw new McpTransportException('Legacy SSE MCP event stream exceeded the configured size limit.');
            }
            $this->eventBuffer .= $chunk;
        }
    }

    /** @return array{event:string,data:string} */
    private function parseEvent(string $frame): array
    {
        $event = 'message';
        $data = [];
        foreach (preg_split('/\r?\n/', $frame) ?: [] as $line) {
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5), ' ');
            }
        }

        return ['event' => $event, 'data' => implode("\n", $data)];
    }

    private function resolveMessageEndpoint(string $advertised): string
    {
        $resolved = (string) UriResolver::resolve(new Uri($this->endpoint()), new Uri(trim($advertised)));
        if (! $this->sameOrigin($this->endpoint(), $resolved)) {
            throw new McpTransportException('Legacy SSE MCP message endpoint must remain on the stream origin.');
        }

        return $resolved;
    }

    private function sameOrigin(string $left, string $right): bool
    {
        $a = parse_url($left);
        $b = parse_url($right);
        if (! is_array($a) || ! is_array($b)) {
            return false;
        }
        $schemeA = strtolower((string) ($a['scheme'] ?? ''));
        $schemeB = strtolower((string) ($b['scheme'] ?? ''));
        $portA = (int) ($a['port'] ?? ($schemeA === 'https' ? 443 : 80));
        $portB = (int) ($b['port'] ?? ($schemeB === 'https' ? 443 : 80));

        return $schemeA === $schemeB
            && strtolower((string) ($a['host'] ?? '')) === strtolower((string) ($b['host'] ?? ''))
            && $portA === $portB;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        $headers = $this->config['headers'] ?? [];
        $headers = is_array($headers) ? array_filter($headers, 'is_string') : [];
        if ($this->protocolVersion !== null) {
            $headers['MCP-Protocol-Version'] = $this->protocolVersion;
        }
        if ($this->protocolEra === McpProtocolEra::Legacy && $this->sessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        return $headers;
    }

    private function captureResponseState(ResponseInterface $response): void
    {
        $this->lastStatusCode = $response->getStatusCode();
        $this->lastResponseHeaders = $response->getHeaders();
        if ($this->protocolEra !== McpProtocolEra::Legacy) {
            return;
        }
        $session = $response->getHeaderLine('Mcp-Session-Id');
        if ($session !== '') {
            $this->sessionId = $session;
        }
    }

    private function endpoint(): string
    {
        $endpoint = (string) ($this->config['endpoint'] ?? '');
        if ($endpoint === '') {
            throw new McpTransportException('Legacy SSE MCP transport endpoint is missing.');
        }

        return $endpoint;
    }

    private function guardEndpoint(string $endpoint): void
    {
        $guard = $this->config['before_request'] ?? null;
        if (is_callable($guard)) {
            $guard($endpoint);
        }
    }

    private function timeoutSeconds(): float
    {
        return max(0.5, (int) ($this->config['timeout_ms'] ?? 30_000) / 1000);
    }

    private function maxResponseBytes(): int
    {
        return max(1, (int) ($this->config['max_response_bytes'] ?? 2_000_000));
    }

    private function assertResponseSize(string $body): void
    {
        if (strlen($body) > $this->maxResponseBytes()) {
            throw new McpTransportException('Legacy SSE MCP response exceeded the configured size limit.');
        }
    }
}
