<?php

namespace Padosoft\AskMyDocsMcpPack\Transports;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

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
final class HttpJsonRpcTransport implements McpTransportContract
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config) {}

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        if (! $request->isRequest()) {
            throw new \InvalidArgumentException('HttpJsonRpcTransport::request() requires a JSON-RPC request message (with id + method).');
        }

        try {
            $response = Http::timeout($this->timeoutSeconds())
                ->withHeaders($this->headers())
                ->asJson()
                ->post($this->endpoint(), $request->toArray());
        } catch (ConnectionException $e) {
            throw new McpTransportException("HTTP MCP transport connection failed: {$e->getMessage()}", 0, $e);
        } catch (\Throwable $e) {
            throw new McpTransportException("HTTP MCP transport request failed: {$e->getMessage()}", 0, $e);
        }

        if ($response->failed()) {
            throw new McpTransportException("HTTP MCP transport returned status {$response->status()}: {$response->body()}");
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new McpTransportException('HTTP MCP transport returned non-JSON or invalid payload.');
        }

        return JsonRpcMessage::fromArray($payload);
    }

    public function notify(JsonRpcMessage $notification): void
    {
        if (! $notification->isNotification()) {
            throw new \InvalidArgumentException('HttpJsonRpcTransport::notify() requires a JSON-RPC notification message (method without id).');
        }

        try {
            Http::timeout($this->timeoutSeconds())
                ->withHeaders($this->headers())
                ->asJson()
                ->post($this->endpoint(), $notification->toArray());
        } catch (\Throwable $e) {
            throw new McpTransportException("HTTP MCP transport notify failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function isHealthy(): bool
    {
        try {
            $base = rtrim((string) $this->config['endpoint'], '/');
            $healthPath = (string) ($this->config['health_path'] ?? '/healthz');
            return Http::timeout($this->timeoutSeconds())
                ->withHeaders($this->headers())
                ->get($base . $healthPath)
                ->successful();
        } catch (\Throwable) {
            return false;
        }
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

    private function timeoutSeconds(): float
    {
        $ms = (int) ($this->config['timeout_ms'] ?? 5_000);
        return max(0.25, $ms / 1000);
    }
}
