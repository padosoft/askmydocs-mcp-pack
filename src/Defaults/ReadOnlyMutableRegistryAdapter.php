<?php

namespace Padosoft\AskMyDocsMcpPack\Defaults;

use Padosoft\AskMyDocsMcpPack\Contracts\Concerns\HasMutableRegistry;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerMutableRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Support\McpServerPage;

/**
 * v1.5.0 (iter-1 fix) — read-only adapter wrapping a v1.4 host
 * registry that does NOT implement
 * {@see McpServerMutableRegistryContract}.
 *
 * The previous binding `app->make(InMemoryMcpServerRegistry::class)`
 * created a FRESH empty in-memory registry, which silently dropped
 * the host's actual server catalog on paginated reads — exactly the
 * Copilot finding the iter-1 commit fixes (PR #10).
 *
 * This adapter:
 *
 *  - Delegates `forTenant()` / `find()` to the host's read registry
 *    so paginated reads (`GET /servers?per_page=...`) see the host's
 *    real catalog;
 *  - Implements `paginate()` via in-PHP filter + slice over
 *    `forTenant()` so the SPA's table view works for free;
 *  - Throws {@see \Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException}
 *    on `create()` / `update()` / `delete()` so write attempts get a
 *    clean HTTP 501 envelope instead of silent no-ops.
 *
 * Hosts that want full CRUD bind their own
 * `McpServerMutableRegistryContract` implementation; this adapter is
 * the graceful-degradation fallback when they have not.
 */
final class ReadOnlyMutableRegistryAdapter implements McpServerMutableRegistryContract
{
    use HasMutableRegistry;

    public function __construct(
        private readonly McpServerRegistryContract $inner,
    ) {}

    public function forTenant(?string $tenantId): array
    {
        return $this->inner->forTenant($tenantId);
    }

    public function find(string $id): ?McpServerContract
    {
        return $this->inner->find($id);
    }

    /**
     * In-memory filter+slice over the inner read registry. Filters
     * applied: `q` (case-insensitive substring on name), `transport`
     * (exact), `enabled` (coerced bool). The `status` filter — which
     * the v1.5 contract documents — is intentionally a no-op here
     * because `McpServerContract` does not expose a `status()`
     * method; hosts backing the registry with real telemetry filter
     * on it server-side via their own
     * `McpServerMutableRegistryContract` implementation.
     *
     * The trait's `paginate()` would throw 501; this override gives
     * the SPA's admin table a working read path for free, even when
     * the host has only bound the v1.4 read-only registry.
     *
     * @param array<string,mixed> $filters
     */
    public function paginate(
        ?string $tenantId,
        array $filters = [],
        int $page = 1,
        int $perPage = 50,
    ): McpServerPage {
        $servers = $this->inner->forTenant($tenantId);

        $q = isset($filters['q']) && is_string($filters['q']) ? strtolower(trim((string) $filters['q'])) : '';
        $transport = isset($filters['transport']) && is_string($filters['transport']) ? trim((string) $filters['transport']) : '';
        $enabled = array_key_exists('enabled', $filters) && $filters['enabled'] !== null && $filters['enabled'] !== ''
            ? filter_var($filters['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $filtered = array_values(array_filter(
            $servers,
            static function (McpServerContract $s) use ($q, $transport, $enabled): bool {
                if ($q !== '' && stripos($s->name(), $q) === false) {
                    return false;
                }
                if ($transport !== '' && $s->transport() !== $transport) {
                    return false;
                }
                if ($enabled !== null && $s->isEnabled() !== $enabled) {
                    return false;
                }
                return true;
            },
        ));

        $total = count($filtered);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        if ($offset < 0 || $offset >= $total) {
            return McpServerPage::fromSlice([], $total, $perPage, $page);
        }

        $slice = array_slice($filtered, $offset, $perPage);

        return McpServerPage::fromSlice($slice, $total, $perPage, $page);
    }
}
