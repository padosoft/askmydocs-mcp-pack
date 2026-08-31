<?php

namespace Padosoft\AskMyDocsMcpPack\Transports;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsMcpPack\Contracts\McpProtocolAwareTransportContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpAuthorizationException;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Support\McpProtocolEra;

/**
 * JSON-RPC over HTTPS — talks to a remote MCP gateway (or the Node
 * sidecar AskMyDocs ships in v5.0).
 *
 * Config keys (passed by {@see McpServerContract::transportConfig()}):
 *   - endpoint:   absolute URL of the MCP gateway
 *   - headers:    array<string,string> — bearer tokens, tenant hints
 *   - timeout_ms: int — defaults to 5_000
 *   - health_path: string — defaults to '/healthz' (relative to endpoint)
 */
final class HttpJsonRpcTransport implements McpProtocolAwareTransportContract
{
    private ?McpProtocolEra $protocolEra = null;

    private ?string $protocolVersion = null;

    private ?string $sessionId = null;

    private ?int $lastStatusCode = null;

    /** @var array<string,list<string>> */
    private array $lastResponseHeaders = [];

    /** @var list<array{method:string,endpoint_guard_ms:int,http_ms:int,decode_ms:int,status:?int}> */
    private array $requestMetrics = [];

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config) {}

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        if (! $request->isRequest()) {
            throw new \InvalidArgumentException('HttpJsonRpcTransport::request() requires a JSON-RPC request message (with id + method).');
        }

        $method = (string) $request->method;
        $guardStartedAt = microtime(true);
        $endpointGuardMs = 0;
        $httpStartedAt = null;
        try {
            $this->guardEndpoint();
            $endpointGuardMs = $this->elapsedMs($guardStartedAt);
            $httpStartedAt = microtime(true);
            $response = Http::timeout($this->timeoutSeconds())
                ->withOptions(['allow_redirects' => false])
                ->withHeaders($this->headersFor($request))
                ->asJson()
                ->post($this->endpoint(), $request->toArray());
            $httpMs = $this->elapsedMs($httpStartedAt);
        } catch (ConnectionException $e) {
            $this->recordMetric(
                $method,
                $endpointGuardMs ?: $this->elapsedMs($guardStartedAt),
                $httpStartedAt !== null ? $this->elapsedMs($httpStartedAt) : 0,
                0,
                null,
            );
            throw new McpTransportException("HTTP MCP transport connection failed: {$e->getMessage()}", 0, $e);
        } catch (\Throwable $e) {
            $this->recordMetric(
                $method,
                $endpointGuardMs ?: $this->elapsedMs($guardStartedAt),
                $httpStartedAt !== null ? $this->elapsedMs($httpStartedAt) : 0,
                0,
                null,
            );
            throw new McpTransportException("HTTP MCP transport request failed: {$e->getMessage()}", 0, $e);
        }

        $this->captureResponseState($response);
        $this->assertResponseSize($response->body());

        $decodeStartedAt = microtime(true);
        $payload = $response->json();
        $decodeMs = $this->elapsedMs($decodeStartedAt);
        $this->recordMetric($method, $endpointGuardMs, $httpMs, $decodeMs, $response->status());

        if (in_array($response->status(), [401, 403], true)) {
            throw $this->authorizationException($response, $payload);
        }
        if ($response->failed() && is_array($payload) && isset($payload['error'])) {
            $this->assertJsonRpcEnvelope($payload);

            return JsonRpcMessage::fromArray($payload);
        }

        if ($response->failed()) {
            throw new McpTransportException("HTTP MCP transport returned status {$response->status()}.");
        }

        if (! is_array($payload)) {
            throw new McpTransportException('HTTP MCP transport returned non-JSON or invalid payload.');
        }

        $this->assertJsonRpcEnvelope($payload);

        return JsonRpcMessage::fromArray($payload);
    }

    public function notify(JsonRpcMessage $notification): void
    {
        if (! $notification->isNotification()) {
            throw new \InvalidArgumentException('HttpJsonRpcTransport::notify() requires a JSON-RPC notification message (method without id).');
        }

        try {
            $this->guardEndpoint();
            $response = Http::timeout($this->timeoutSeconds())
                ->withOptions(['allow_redirects' => false])
                ->withHeaders($this->headersFor($notification))
                ->asJson()
                ->post($this->endpoint(), $notification->toArray());
        } catch (\Throwable $e) {
            throw new McpTransportException("HTTP MCP transport notify failed: {$e->getMessage()}", 0, $e);
        }

        // Notifications have no JSON-RPC reply, but the HTTP status still tells us
        // whether the server accepted them (2xx/202) or rejected them (e.g. 401/404).
        // Treating a rejected `notifications/initialized` as success would let legacy
        // negotiation continue against an uninitialized server.
        $this->captureResponseState($response);
        $this->assertResponseSize($response->body());
        if (in_array($response->status(), [401, 403], true)) {
            throw $this->authorizationException($response, $response->json());
        }
        if ($response->failed()) {
            throw new McpTransportException("HTTP MCP transport notify returned status {$response->status()}.");
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

    /** @return list<array{method:string,endpoint_guard_ms:int,http_ms:int,decode_ms:int,status:?int}> */
    public function requestMetrics(): array
    {
        return $this->requestMetrics;
    }

    private function endpoint(): string
    {
        $endpoint = (string) ($this->config['endpoint'] ?? '');
        if ($endpoint === '') {
            throw new McpTransportException('HTTP MCP transport: endpoint is missing from transport config.');
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
            $name = $message->params['name'] ?? $message->params['taskId'] ?? null;
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
        $ms = (int) ($this->config['timeout_ms'] ?? 5_000);

        return max(0.25, $ms / 1000);
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
            throw new McpTransportException('HTTP MCP transport response exceeded the configured size limit.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertJsonRpcEnvelope(array $payload): void
    {
        $hasResult = array_key_exists('result', $payload);
        $hasError = array_key_exists('error', $payload);
        if (($payload['jsonrpc'] ?? null) !== JsonRpcMessage::VERSION
            || ! array_key_exists('id', $payload)
            || $hasResult === $hasError
            || ($hasError && ! is_array($payload['error']))) {
            throw new McpTransportException('HTTP MCP transport returned an invalid JSON-RPC response envelope.');
        }
    }

    private function elapsedMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function recordMetric(string $method, int $guardMs, int $httpMs, int $decodeMs, ?int $status): void
    {
        $this->requestMetrics[] = [
            'method' => $method,
            'endpoint_guard_ms' => $guardMs,
            'http_ms' => $httpMs,
            'decode_ms' => $decodeMs,
            'status' => $status,
        ];
    }

    private function authorizationException(Response $response, mixed $payload): McpAuthorizationException
    {
        $challenge = $response->header('WWW-Authenticate');
        $challenge = is_string($challenge)
            ? mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $challenge), 0, 4096)
            : null;
        $error = is_array($payload) && is_string($payload['error'] ?? null)
            ? strtolower($payload['error'])
            : null;
        if ($error === null && $challenge !== null
            && preg_match('/(?:^|[,\s])error\s*=\s*"?([a-z0-9_.-]{1,64})/i', $challenge, $match) === 1) {
            $error = strtolower($match[1]);
        }

        return new McpAuthorizationException(
            httpStatus: $response->status(),
            oauthError: $error,
            wwwAuthenticate: $challenge,
        );
    }
}
