<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts\Concerns;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException;
use Padosoft\AskMyDocsMcpPack\Support\McpServerPage;

/**
 * v1.5.0 — default implementation of the 4 mutable-registry methods
 * added on top of
 * {@see \Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract}
 * by
 * {@see \Padosoft\AskMyDocsMcpPack\Contracts\McpServerMutableRegistryContract}.
 *
 * Same shape as
 * {@see \Padosoft\AskMyDocsMcpPack\Contracts\Concerns\HasIdentitySurface}:
 * every method throws {@see HostFeatureNotImplementedException} which
 * the admin controllers translate into HTTP 501 with a stable JSON
 * envelope. Hosts override the methods they actually want to
 * implement; everything else stays 501 — the SPA renders the
 * graceful "host did not wire this surface yet" state.
 *
 * The package's
 * {@see \Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry}
 * additionally overrides `paginate()` with a working in-memory impl
 * so tests can exercise the read-pagination path without binding a
 * real impl. `create()` / `update()` / `delete()` keep throwing on
 * the in-memory registry — writes need a host-backed impl.
 */
trait HasMutableRegistry
{
    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(
        ?string $tenantId,
        array $filters = [],
        int $page = 1,
        int $perPage = 50,
    ): McpServerPage {
        throw HostFeatureNotImplementedException::forFeature('paginate');
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function create(array $attributes): McpServerContract
    {
        throw HostFeatureNotImplementedException::forFeature('create');
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function update(string $id, array $attributes): McpServerContract
    {
        throw HostFeatureNotImplementedException::forFeature('update');
    }

    public function delete(string $id): bool
    {
        throw HostFeatureNotImplementedException::forFeature('delete');
    }
}
