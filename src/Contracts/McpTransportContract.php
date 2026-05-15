<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

/**
 * Single-shot RPC transport against an upstream MCP server. The two
 * default implementations are:
 *
 *   - {@see \Padosoft\AskMyDocsMcpPack\Transports\HttpJsonRpcTransport}
 *     — talks to a remote MCP gateway over HTTP(S).
 *   - {@see \Padosoft\AskMyDocsMcpPack\Transports\StdioJsonRpcTransport}
 *     — spawns a child process and pipes JSON-RPC over stdin/stdout
 *     (the canonical MCP transport — Claude Desktop / Cursor / VS Code
 *     ship this).
 *
 * Hosts who already operate a Node.js MCP sidecar can keep using their
 * own HTTP bridge by implementing this contract once.
 */
interface McpTransportContract
{
    /**
     * Send a request and block until the matching response arrives.
     * Notifications (no id) MUST NOT be sent through this method —
     * use {@see notify()} instead.
     *
     * @throws \Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException
     */
    public function request(JsonRpcMessage $request): JsonRpcMessage;

    /** Fire-and-forget notification (no response expected). */
    public function notify(JsonRpcMessage $notification): void;

    /** Whether the transport is currently reachable (cheap probe). */
    public function isHealthy(): bool;
}
