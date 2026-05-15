<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

/**
 * Per-tenant catalog of {@see McpServerContract} entries.
 *
 * Hosts implement this against their preferred storage (Eloquent
 * model, KV cache, hard-coded config). The pack ships an
 * {@see \Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry}
 * for tests and quick-starts.
 */
interface McpServerRegistryContract
{
    /**
     * Enabled servers visible to the given tenant. Hosts MUST honour
     * the tenant boundary — a `null` tenant means "platform-global".
     *
     * @return array<int, McpServerContract>
     */
    public function forTenant(?string $tenantId): array;

    /** Look up a specific server by id. Null when missing or disabled. */
    public function find(string $id): ?McpServerContract;
}
