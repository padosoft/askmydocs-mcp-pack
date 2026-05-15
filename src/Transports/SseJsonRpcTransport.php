<?php

namespace Padosoft\AskMyDocsMcpPack\Transports;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

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
final class SseJsonRpcTransport implements McpTransportContract
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config) {}

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        if (! $request->isRequest()) {
            throw new \InvalidArgumentException('SseJsonRpcTransport::request() requires a JSON-RPC request message.');
        }

        try {
            $response = Http::timeout($this->timeoutSeconds())
                ->withHeaders($this->headers() + [
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

        if ($response->failed()) {
            throw new McpTransportException("SSE MCP transport returned status {$response->status()}: {$response->body()}");
        }

        return $this->parseEventStream($response->body(), $request->id);
    }

    public function notify(JsonRpcMessage $notification): void
    {
        if (! $notification->isNotification()) {
            throw new \InvalidArgumentException('SseJsonRpcTransport::notify() requires a JSON-RPC notification.');
        }

        try {
            Http::timeout($this->timeoutSeconds())
                ->withHeaders($this->headers())
                ->asJson()
                ->post($this->endpoint(), $notification->toArray());
        } catch (\Throwable $e) {
            throw new McpTransportException("SSE MCP transport notify failed: {$e->getMessage()}", 0, $e);
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

    private function timeoutSeconds(): float
    {
        $ms = (int) ($this->config['timeout_ms'] ?? 30_000);
        return max(0.5, $ms / 1000);
    }
}
