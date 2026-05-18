<?php

namespace Padosoft\AskMyDocsMcpPack\Defaults;

use Padosoft\AskMyDocsMcpPack\Contracts\Concerns\HasMutableRegistry;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerMutableRegistryContract;
use Padosoft\AskMyDocsMcpPack\Support\McpServerPage;

/**
 * Array-backed registry — useful in tests and for hosts that load
 * server definitions from `config/mcp.php` instead of a database.
 *
 * v1.5.0 — implements {@see McpServerMutableRegistryContract} via the
 * {@see HasMutableRegistry} trait. `paginate()` is overridden with a
 * working in-memory filter+slice impl so tests can exercise the read
 * pagination path. `create()` / `update()` / `delete()` keep
 * throwing {@see \Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException}
 * — writes need a host-backed impl, and the package-provided
 * in-memory store deliberately stays read-mostly so tests of the
 * mutable surface require the host to wire one explicitly.
 */
final class InMemoryMcpServerRegistry implements McpServerMutableRegistryContract
{
    use HasMutableRegistry;

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

    /**
     * v1.5.0 — in-memory filter+slice for the admin index page.
     *
     * Filters honoured: `q` (case-insensitive substring on the server
     * name), `transport` (exact match), `enabled` (coerced to bool).
     * `status` is accepted but ignored here — the in-memory store
     * does not synthesise per-server status; hosts backing the
     * registry with real telemetry (e.g. Eloquent + a stats column)
     * filter on it server-side.
     *
     * The tenant filter ignores the enabled-only invariant of
     * `forTenant()` so the admin can list disabled rows too. Hosts
     * that want disabled rows hidden by default pass
     * `enabled=true` from the SPA.
     *
     * @param array<string,mixed> $filters
     */
    public function paginate(
        ?string $tenantId,
        array $filters = [],
        int $page = 1,
        int $perPage = 50,
    ): McpServerPage {
        $perPage = max(1, $perPage);

        $rows = array_values(array_filter(
            $this->servers,
            static fn(McpServerContract $s): bool =>
                $s->tenantId() === $tenantId || $s->tenantId() === null,
        ));

        $q = isset($filters['q']) && is_string($filters['q']) ? strtolower(trim($filters['q'])) : '';
        if ($q !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn(McpServerContract $s): bool => str_contains(strtolower($s->name()), $q),
            ));
        }

        if (isset($filters['transport']) && is_string($filters['transport']) && $filters['transport'] !== '') {
            $transport = $filters['transport'];
            $rows = array_values(array_filter(
                $rows,
                static fn(McpServerContract $s): bool => $s->transport() === $transport,
            ));
        }

        if (array_key_exists('enabled', $filters) && $filters['enabled'] !== null && $filters['enabled'] !== '') {
            $wantEnabled = filter_var($filters['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($wantEnabled !== null) {
                $rows = array_values(array_filter(
                    $rows,
                    static fn(McpServerContract $s): bool => $s->isEnabled() === $wantEnabled,
                ));
            }
        }

        $total = count($rows);
        $offset = ($page - 1) * $perPage;
        // Out-of-range pages return an empty slice but preserve
        // `currentPage` so the SPA can render "page N of M" without
        // re-snapping. Page-1 always exists, even when `$total` is 0.
        if ($offset < 0 || $offset >= $total) {
            return McpServerPage::fromSlice([], $total, $perPage, $page);
        }

        $slice = array_slice($rows, $offset, $perPage);

        return McpServerPage::fromSlice($slice, $total, $perPage, $page);
    }
}
