<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts\Concerns;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpServerNotFoundException;
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
     * Default tenant-scoped lookup — walks `forTenant()` first
     * (correct under id reuse across tenants) and falls back to the
     * global `find()` ONLY when no tenant is active (CLI / cron paths
     * that operate on the platform-wide catalog).
     *
     * When `$includeDisabled` is `true` AND `forTenant()` hides
     * disabled servers, the disabled row is found via `find()` and
     * its tenant id is checked manually before returning — preserving
     * R30 even on registries that filter `forTenant()` by enabled
     * state.
     */
    public function findForActiveTenant(?string $tenantId, string $id, bool $includeDisabled = true): ?McpServerContract
    {
        if ($tenantId !== null) {
            foreach ($this->forTenant($tenantId) as $server) {
                if ($server->id() === $id) {
                    return $server;
                }
            }
        }

        if (! $includeDisabled) {
            return null;
        }

        $candidate = $this->find($id);
        if ($candidate === null) {
            return null;
        }
        if ($tenantId !== null && $candidate->tenantId() !== null && $candidate->tenantId() !== $tenantId) {
            // Found by id, but it belongs to another tenant. Do not
            // surface it to the caller — R30 means "the row does not
            // exist FROM THIS TENANT'S PERSPECTIVE".
            return null;
        }
        return $candidate;
    }

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
