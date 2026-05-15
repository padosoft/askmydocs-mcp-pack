<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

/**
 * Host-supplied catalog of tools (and optionally resources + prompts)
 * the SERVER-SIDE surface exposes to remote MCP clients.
 *
 * v1.2.0 — same package now operates in BOTH directions:
 *
 *   - CLIENT side (v1.0+): `McpToolCallingService` orchestrates calls
 *     OUT to upstream MCP servers. Driven by `McpServerRegistryContract`.
 *   - SERVER side (v1.2): the host EXPOSES its own catalog so remote
 *     MCP clients (Claude Desktop / Cursor / VS Code / any agent) can
 *     drive `initialize` / `tools/list` / `tools/call` / `resources/*`
 *     / `prompts/*` against it. Driven by THIS contract.
 *
 * Implementations should tenant-scope every list. The package's
 * `JsonRpcRequestHandler` passes the resolved tenant id (extracted
 * from the request's auth context) into every list method.
 */
interface McpServerExposureContract
{
    /**
     * Server descriptor handed back inside the `initialize` response.
     * Free-form per MCP spec; common keys: `name`, `version`.
     *
     * @return array<string,mixed>
     */
    public function serverInfo(): array;

    /**
     * Capabilities advertised at initialize. Per MCP spec this is a
     * map of `{tools: {}, resources: {}, prompts: {}}` keys whose
     * presence (regardless of value) signals "this server supports X".
     *
     * @return array<string,mixed>
     */
    public function capabilities(): array;

    /**
     * Tools the host publishes for the given tenant context. Empty
     * list = no tools published (the server still responds to
     * `tools/list` with an empty array, NOT an error).
     *
     * @return array<int,McpToolContract>
     */
    public function tools(?string $tenantId): array;

    /**
     * Resources the host publishes for the given tenant context.
     * Default-empty implementations are valid for tool-only servers.
     *
     * @return array<int,McpResourceContract>
     */
    public function resources(?string $tenantId): array;

    /**
     * Prompts the host publishes for the given tenant context.
     * Default-empty implementations are valid for tool-only servers.
     *
     * @return array<int,McpPromptContract>
     */
    public function prompts(?string $tenantId): array;
}
