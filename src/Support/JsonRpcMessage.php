<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

/**
 * Minimal JSON-RPC 2.0 envelope used by the stdio + HTTP client
 * bridges to talk to upstream MCP servers.
 *
 * Per MCP spec, every message is one of:
 *   - Request:      { jsonrpc:"2.0", id, method, params? }
 *   - Response OK:  { jsonrpc:"2.0", id, result }
 *   - Response Err: { jsonrpc:"2.0", id, error: { code, message, data? } }
 *   - Notification: { jsonrpc:"2.0", method, params? }   (no `id`)
 *
 * This is a value object — it does NOT do I/O. Transport bridges
 * serialise/deserialise it through their channel of choice.
 */
final class JsonRpcMessage
{
    public const VERSION = '2.0';

    /**
     * @param array<string,mixed>|null $params
     * @param array<string,mixed>|null $error
     */
    public function __construct(
        public readonly string|int|null $id,
        public readonly ?string $method,
        public readonly ?array $params = null,
        public readonly mixed $result = null,
        public readonly ?array $error = null,
    ) {}

    public static function request(string|int $id, string $method, ?array $params = null): self
    {
        return new self($id, $method, $params);
    }

    public static function notification(string $method, ?array $params = null): self
    {
        return new self(null, $method, $params);
    }

    public static function response(string|int $id, mixed $result): self
    {
        return new self($id, null, null, $result, null);
    }

    public static function errorResponse(string|int $id, int $code, string $message, mixed $data = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return new self($id, null, null, null, $error);
    }

    public function isRequest(): bool
    {
        return $this->method !== null && $this->id !== null;
    }

    public function isNotification(): bool
    {
        return $this->method !== null && $this->id === null;
    }

    public function isResponse(): bool
    {
        return $this->method === null && $this->id !== null;
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $base = ['jsonrpc' => self::VERSION];

        if ($this->id !== null) {
            $base['id'] = $this->id;
        }
        if ($this->method !== null) {
            $base['method'] = $this->method;
        }
        if ($this->params !== null) {
            $base['params'] = $this->params;
        }
        if ($this->error !== null) {
            $base['error'] = $this->error;
        } elseif ($this->isResponse()) {
            $base['result'] = $this->result;
        }

        return $base;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: $payload['id'] ?? null,
            method: $payload['method'] ?? null,
            params: $payload['params'] ?? null,
            result: $payload['result'] ?? null,
            error: $payload['error'] ?? null,
        );
    }
}
