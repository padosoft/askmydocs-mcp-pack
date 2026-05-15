<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

/**
 * Configuration of an external MCP server the orchestrator can
 * connect to via stdio (Claude Desktop / Cursor / VS Code) or HTTP
 * (cloud MCP gateway).
 *
 * The package ships an in-memory default ({@see InMemoryMcpServerRegistry});
 * production hosts typically back it with a per-tenant Eloquent
 * model so DPO operators can rotate credentials without re-deploying.
 */
interface McpServerContract
{
    /** Stable opaque identifier scoped per tenant. */
    public function id(): string;

    /** Human label for the admin UI / audit log. */
    public function name(): string;

    /** `stdio` (process-based) or `http` (remote MCP gateway). */
    public function transport(): string;

    /**
     * Tenant boundary. Null means "platform-global"; the orchestrator
     * respects this in concert with the host's tenant context.
     */
    public function tenantId(): ?string;

    /**
     * Transport-specific configuration. For `stdio`: keys `command`,
     * `args`, `cwd`, `env`. For `http`: keys `endpoint`, `headers`.
     * The orchestrator passes this verbatim to {@see McpClientBridge}.
     *
     * @return array<string,mixed>
     */
    public function transportConfig(): array;

    /**
     * Allowed tool names exposed by this server. Empty array means
     * "all tools the server advertises during handshake".
     *
     * @return array<int,string>
     */
    public function allowedTools(): array;

    /** Whether the server is currently enabled. */
    public function isEnabled(): bool;
}
