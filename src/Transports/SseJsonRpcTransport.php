<?php

namespace Padosoft\AskMyDocsMcpPack\Transports;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsMcpPack\Contracts\McpProtocolAwareTransportContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Support\McpProtocolEra;

/**
 * JSON-RPC over HTTPS with Server-Sent Events (SSE) responses — the
 * MCP transport profile preferred by remote gateways that stream
 * partial tool-call results back to the client in real time.
 *
 * Wire model (per MCP spec):
 *
 *   - The client POSTs a JSON-RPC request to the gateway endpoint.
 *   - The gateway responds with `Content-Type: text/event-stream` and
 *     emits one or more `data: <json-rpc-message>\n\n` frames before
 *     closing the stream. The terminal frame carries the matching
 *     response envelope for the request id; intermediate frames are
 *     notifications (progress updates, partial results) — the
 *     orchestrator may not care about them yet, but we parse and
 *     return the final response cleanly.
 *
 * Config keys (passed by {@see McpServerContract::transportConfig()}):
 *   - endpoint:   absolute URL of the MCP SSE gateway
 *   - headers:    array<string,string> — bearer tokens, tenant hints
 *   - timeout_ms: int — overall stream timeout (default 30_000)
 *   - health_path: string — defaults to '/healthz'
 *
 * The transport is implemented on top of Laravel's HTTP client so
 * `Http::fake()` works in tests exactly as it does for the
 * {@see HttpJsonRpcTransport}. SSE framing is parsed in-process from
 * the raw response body.
 */
final class SseJsonRpcTransport implements McpProtocolAwareTransportContract
{
    private ?McpProtocolEra $protocolEra = null;

    private ?string $protocolVersion = null;

    private ?string $sessionId = null;

    private ?int $lastStatusCode = null;

    /** @var array<string,list<string>> */
    private array $lastResponseHeaders = [];

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config) {}

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        if (! $request->isRequest()) {
            throw new \InvalidArgumentException('SseJsonRpcTransport::request() requires a JSON-RPC request message.');
        }

        try {
            $this->guardEndpoint();
            $response = Http::timeout($this->timeoutSeconds())
                ->withOptions(['allow_redirects' => false])
                ->withHeaders($this->headersFor($request) + [
                    'Accept' => 'text/event-stream',
                    'Cache-Control' => 'no-cache',
                ])
                ->asJson()
                ->post($this->endpoint(), $request->toArray());
        } catch (ConnectionException $e) {
            throw new McpTransportException("SSE MCP transport connection failed: {$e->getMessage()}", 0, $e);
        } catch (\Throwable $e) {
            throw new McpTransportException("SSE MCP transport request failed: {$e->getMessage()}", 0, $e);
        }

        $this->captureResponseState($response);
        $this->assertResponseSize($response->body());
        if ($response->failed()) {
            $payload = $response->json();
            if (is_array($payload) && isset($payload['error'])) {
                return JsonRpcMessage::fromArray($payload);
            }
            throw new McpTransportException("SSE MCP transport returned status {$response->status()}.");
        }

        return $this->parseEventStream($response->body(), $request->id);
    }

    public function notify(JsonRpcMessage $notification): void
    {
        if (! $notification->isNotification()) {
            throw new \InvalidArgumentException('SseJsonRpcTransport::notify() requires a JSON-RPC notification.');
        }

        try {
            $this->guardEndpoint();
            $response = Http::timeout($this->timeoutSeconds())
                ->withOptions(['allow_redirects' => false])
                ->withHeaders($this->headersFor($notification))
                ->asJson()
                ->post($this->endpoint(), $notification->toArray());
        } catch (\Throwable $e) {
            throw new McpTransportException("SSE MCP transport notify failed: {$e->getMessage()}", 0, $e);
        }

        // Notifications have no JSON-RPC reply, but the HTTP status still tells us
        // whether the server accepted them (2xx/202) or rejected them (e.g. 401/404).
        // Treating a rejected `notifications/initialized` as success would let legacy
        // negotiation continue against an uninitialized server.
        $this->captureResponseState($response);
        $this->assertResponseSize($response->body());
        if ($response->failed()) {
            throw new McpTransportException("SSE MCP transport notify returned status {$response->status()}.");
        }
    }

    public function isHealthy(): bool
    {
        try {
            $this->guardEndpoint();
            $base = rtrim((string) $this->config['endpoint'], '/');
            $healthPath = (string) ($this->config['health_path'] ?? '/healthz');

            return Http::timeout($this->timeoutSeconds())
                ->withHeaders($this->headers())
                ->get($base.$healthPath)
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function useProtocol(McpProtocolEra $era, string $version): void
    {
        $this->protocolEra = $era;
        $this->protocolVersion = $version;
        if ($era === McpProtocolEra::Modern) {
            $this->sessionId = null;
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

    /**
     * Parse an SSE-framed body and return the JSON-RPC response
     * whose `id` matches `$expectedId`. SSE framing per RFC:
     *
     *   data: {"jsonrpc":"2.0","id":1,"result":{...}}\n
     *   \n
     *   data: {"jsonrpc":"2.0","method":"progress",...}\n   ← notification
     *   \n
     *   data: {"jsonrpc":"2.0","id":1,"result":{...}}\n     ← final
     *   \n
     *
     * Multi-line `data:` fields are concatenated per the spec.
     */
    private function parseEventStream(string $body, string|int|null $expectedId): JsonRpcMessage
    {
        $frames = preg_split('/\r?\n\r?\n/', $body) ?: [];
        $candidate = null;

        foreach ($frames as $frame) {
            $frame = trim($frame);
            if ($frame === '') {
                continue;
            }
            $payload = $this->dataLineOf($frame);
            if ($payload === null) {
                continue;
            }
            $decoded = json_decode($payload, true);
            if (! is_array($decoded)) {
                continue;
            }
            if (($decoded['id'] ?? null) === $expectedId) {
                return JsonRpcMessage::fromArray($decoded);
            }
            // Fallback candidate: only consider response-shaped
            // frames (those carrying `result` or `error`). A frame
            // with `method` set and no `id` is a NOTIFICATION
            // (progress, log, …) — never a substitute for the
            // request's response, and returning it would mask a
            // missing response with telemetry.
            $isResponseShaped = isset($decoded['jsonrpc'])
                && ! isset($decoded['method'])
                && (array_key_exists('result', $decoded) || array_key_exists('error', $decoded));
            if ($isResponseShaped) {
                $candidate = $decoded;
            }
        }

        if ($candidate !== null) {
            return JsonRpcMessage::fromArray($candidate);
        }

        throw new McpTransportException('SSE MCP transport: no matching JSON-RPC response frame in event stream.');
    }

    private function dataLineOf(string $frame): ?string
    {
        $lines = explode("\n", $frame);
        $data = [];
        foreach ($lines as $line) {
            $line = ltrim($line, "\r");
            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5), ' ');
            }
        }

        return $data === [] ? null : implode("\n", $data);
    }

    private function endpoint(): string
    {
        $endpoint = (string) ($this->config['endpoint'] ?? '');
        if ($endpoint === '') {
            throw new McpTransportException('SSE MCP transport: endpoint is missing from transport config.');
        }

        return $endpoint;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        $headers = $this->config['headers'] ?? [];

        return is_array($headers) ? $headers : [];
    }

    /** @return array<string,string> */
    private function headersFor(JsonRpcMessage $message): array
    {
        $headers = $this->headers();
        if ($this->protocolVersion !== null) {
            $headers['MCP-Protocol-Version'] = $this->protocolVersion;
        }
        if ($this->protocolEra === McpProtocolEra::Modern && $message->method !== null) {
            $headers['Mcp-Method'] = $message->method;
            $name = $message->params['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $headers['Mcp-Name'] = $name;
            }
        }
        if ($this->protocolEra === McpProtocolEra::Legacy && $this->sessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        return $headers;
    }

    private function captureResponseState(Response $response): void
    {
        $this->lastStatusCode = $response->status();
        $this->lastResponseHeaders = $response->headers();
        if ($this->protocolEra !== McpProtocolEra::Legacy) {
            return;
        }

        $session = $response->header('Mcp-Session-Id');
        if (is_string($session) && $session !== '') {
            $this->sessionId = $session;
        }
    }

    private function timeoutSeconds(): float
    {
        $ms = (int) ($this->config['timeout_ms'] ?? 30_000);

        return max(0.5, $ms / 1000);
    }

    private function guardEndpoint(): void
    {
        $guard = $this->config['before_request'] ?? null;
        if (is_callable($guard)) {
            $guard($this->endpoint());
        }
    }

    private function assertResponseSize(string $body): void
    {
        $limit = max(1, (int) ($this->config['max_response_bytes'] ?? 2_000_000));
        if (strlen($body) > $limit) {
            throw new McpTransportException('SSE MCP transport response exceeded the configured size limit.');
        }
    }
}
