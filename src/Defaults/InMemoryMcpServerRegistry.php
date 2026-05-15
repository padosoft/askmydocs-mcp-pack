<?php

namespace Padosoft\AskMyDocsMcpPack\Defaults;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;

/**
 * Array-backed registry — useful in tests and for hosts that load
 * server definitions from `config/mcp.php` instead of a database.
 */
final class InMemoryMcpServerRegistry implements McpServerRegistryContract
{
    /** @param array<int,McpServerContract> $servers */
    public function __construct(private array $servers = []) {}

    public function add(McpServerContract $server): void
    {
        $this->servers[] = $server;
    }

    public function forTenant(?string $tenantId): array
    {
        return array_values(array_filter(
            $this->servers,
            static fn(McpServerContract $server): bool =>
                $server->isEnabled()
                && ($server->tenantId() === $tenantId || $server->tenantId() === null),
        ));
    }

    public function find(string $id): ?McpServerContract
    {
        foreach ($this->servers as $server) {
            if ($server->isEnabled() && $server->id() === $id) {
                return $server;
            }
        }

        return null;
    }
}
