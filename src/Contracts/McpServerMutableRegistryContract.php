<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

use Padosoft\AskMyDocsMcpPack\Support\McpServerPage;

/**
 * v1.5.0 — admin REST extension surface (W1.B).
 *
 * Sub-interface of {@see McpServerRegistryContract} that adds the
 * paginate + CRUD methods the v1.5 admin controllers (`ServersController::store/update/destroy`,
 * `ToolsController::index`) consume. Existing hosts on a pre-v1.5
 * bridge keep compiling — the base contract is unchanged and the
 * package's own
 * {@see \Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry}
 * adopts the new methods via the
 * {@see \Padosoft\AskMyDocsMcpPack\Contracts\Concerns\HasMutableRegistry}
 * trait.
 *
 * Adoption paths (same shape as the identity sub-interface — see
 * {@see McpHostBridgeIdentityContract}):
 *
 *  1. Implement THIS interface in your existing registry class — only
 *     `paginate()` / `create()` / `update()` / `delete()` need new
 *     bodies; `forTenant()` + `find()` stay unchanged.
 *  2. `use HasMutableRegistry` in the existing class to inherit
 *     501-throwing defaults; override the methods you actually want
 *     to expose. The package's `InMemoryMcpServerRegistry` ships an
 *     ACTUAL `paginate()` impl on top of the trait so tests can
 *     exercise the read path without binding a real impl —
 *     `create()` / `update()` / `delete()` still throw on the
 *     in-memory registry by design (you need a host to bind a real
 *     impl for writes).
 *  3. Do nothing — the service provider resolves this contract by
 *     checking whether the bound `McpServerRegistryContract` already
 *     implements the sub-interface, otherwise falling back to the
 *     in-memory registry. Admin controllers therefore get a working
 *     registry no matter what; write attempts on the fallback throw
 *     {@see \Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException}
 *     which the controller translates into HTTP 501.
 */
interface McpServerMutableRegistryContract extends McpServerRegistryContract
{
    /**
     * Tenant-scoped lookup that ALSO returns disabled rows.
     *
     * Why a dedicated method (added in iter-1 of W1.B):
     *
     *  - `forTenant()` may filter by enabled status (the in-memory
     *    registry does);
     *  - `find()` is "global" — it walks the registry in iteration
     *    order and returns the first match. If two tenants legitimately
     *    share `srv-a`, `find()` can return the other tenant's row
     *    first, which a controller would incorrectly 403 as
     *    cross-tenant;
     *  - admin write paths (`PATCH /servers/{id}` + `DELETE`) MUST be
     *    able to find rows by id within the active tenant AND include
     *    disabled rows (operators do PATCH disabled-true → false).
     *
     * Returns `null` when no row matches BOTH the active tenant and
     * the id. Default impl in {@see Concerns\HasMutableRegistry} walks
     * `forTenant()` + falls back to `find()` so existing hosts get the
     * narrowing without subclassing.
     */
    public function findForActiveTenant(?string $tenantId, string $id, bool $includeDisabled = true): ?McpServerContract;

    /**
     * Paginated registry view scoped to the active tenant.
     *
     * Filters keys (all optional, all string-based):
     *  - `q`         — substring match on `name` (host-side LIKE
     *                  escaping is the implementation's job; the
     *                  controller never builds a raw LIKE pattern)
     *  - `status`    — `ok | warn | err` (admin tab filter)
     *  - `transport` — `http | sse | stdio`
     *  - `enabled`   — coercible to bool (`true|false|1|0`)
     *
     * @param array<string,mixed> $filters
     */
    public function paginate(
        ?string $tenantId,
        array $filters = [],
        int $page = 1,
        int $perPage = 50,
    ): McpServerPage;

    /**
     * Create a new server in the registry. The host owns id minting,
     * uniqueness validation, audit-trail wiring, and tenant
     * placement. The package guarantees:
     *
     *  - `attributes['tenant_id']` carries the TRUSTED tenant id the
     *    controller resolved (never the wire body) — hosts MUST honour
     *    it for R30;
     *  - the controller validates the wire shape via
     *    `StoreServerRequest` before calling this method;
     *  - the host throws
     *    {@see \Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException}
     *    when the surface is not implemented, NEVER returns a null
     *    contract.
     *
     * @param array<string,mixed> $attributes
     */
    public function create(array $attributes): McpServerContract;

    /**
     * Update an existing server in place. Tenant guarding (the host
     * MAY reject cross-tenant updates) is the implementation's
     * responsibility; the controller also enforces a tenant check
     * BEFORE calling this method (defence in depth).
     *
     * Returns the updated row. The host MUST throw
     * {@see \Padosoft\AskMyDocsMcpPack\Exceptions\McpServerNotFoundException}
     * when `$id` does not exist — the controller catches that
     * exception and surfaces HTTP 404. Any other exception bubbles up
     * to the framework's handler (and answers 500).
     *
     * @param array<string,mixed> $attributes
     */
    public function update(string $id, array $attributes): McpServerContract;

    /**
     * Remove a server from the registry. Returns `true` when the row
     * existed and was removed, `false` when the row was not present
     * (idempotent delete). The package runs this inside a
     * `DB::transaction` closure to commit / rollback atomically per
     * R21 — the host MAY rely on that wrapping when persisting to
     * SQL but MUST not assume it on non-SQL stores.
     */
    public function delete(string $id): bool;
}
