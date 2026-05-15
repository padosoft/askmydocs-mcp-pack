<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

/**
 * In-memory MCP transport that scripts canned JSON-RPC responses
 * per (method, params['name']?) key. Used by handshake + orchestrator
 * feature tests to avoid spawning real processes / HTTP calls.
 */
final class StubMcpTransport implements McpTransportContract
{
    /** @var array<string,mixed> */
    public array $responses = [];

    public bool $healthy = true;

    /** @var list<JsonRpcMessage> */
    public array $sentRequests = [];

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        $this->sentRequests[] = $request;
        $key = $this->keyFor($request);

        if (! array_key_exists($key, $this->responses)) {
            return JsonRpcMessage::errorResponse($request->id, -32601, "No stub for [{$key}]");
        }

        $payload = $this->responses[$key];
        if ($payload instanceof JsonRpcMessage) {
            return $payload;
        }

        return JsonRpcMessage::response($request->id, $payload);
    }

    public function notify(JsonRpcMessage $notification): void
    {
        $this->sentRequests[] = $notification;
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    private function keyFor(JsonRpcMessage $request): string
    {
        if ($request->method === 'tools/call') {
            return 'tools/call:' . (string) ($request->params['name'] ?? '');
        }

        return (string) $request->method;
    }

    public function scriptInitialize(array $capabilities = ['tools' => []]): self
    {
        $this->responses['initialize'] = $capabilities;
        return $this;
    }

    public function scriptListTools(array $tools): self
    {
        $this->responses['tools/list'] = ['tools' => $tools];
        return $this;
    }

    public function scriptToolCall(string $toolName, mixed $result): self
    {
        $this->responses["tools/call:{$toolName}"] = is_array($result) ? $result : ['content' => $result];
        return $this;
    }
}
