<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

use Padosoft\AskMyDocsMcpPack\Support\HostApiKey;
use Padosoft\AskMyDocsMcpPack\Support\HostTenant;
use Padosoft\AskMyDocsMcpPack\Support\HostUser;
use Padosoft\AskMyDocsMcpPack\Support\HostUserPreferences;

/**
 * v1.5.0 — admin REST extension surface.
 *
 * This sub-interface extends {@see McpHostBridgeContract} with the
 * methods the v1.5 admin controllers (`MeController`,
 * `TenantsController`, `ApiKeysController`) consume, plus the W1.C
 * audit-replay + breaker-reset hooks.
 *
 * Backwards compatibility: this contract is OPTIONAL. Hosts running
 * pre-v1.5 bridges keep working — controllers detect a missing
 * implementation at runtime and answer HTTP 501
 * `feature_not_implemented` via {@see \Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException}.
 *
 * Adopt one of three upgrade paths when bumping to v1.5:
 *
 *  1. Implement THIS interface in your existing bridge class — the
 *     `chat()` + `supportsToolCalling()` methods of the base contract
 *     are unchanged so no other refactor is required. Add the nine
 *     identity / replay / reset methods listed below.
 *  2. `use Padosoft\AskMyDocsMcpPack\Contracts\Concerns\HasIdentitySurface`
 *     in your bridge class — every method gets a safe default that
 *     throws
 *     {@see \Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException}.
 *     Override only the methods you actually expose.
 *  3. Do nothing — the package's default
 *     {@see \Padosoft\AskMyDocsMcpPack\Defaults\NullMcpHostBridge}
 *     implements this interface via the trait, so a fresh install
 *     boots without any host wiring. The admin SPA's identity routes
 *     simply answer HTTP 501.
 */
interface McpHostBridgeIdentityContract extends McpHostBridgeContract
{
    /**
     * Identity of the actor driving the current request, as resolved
     * by the host's auth middleware. Returns `null` when no actor is
     * bound (anonymous / platform-global view).
     *
     * Hosts produce `HostUser` from whatever auth backend they use
     * (Sanctum, Passport, Keycloak, custom JWT) — the package never
     * imports a host `User` model.
     */
    public function currentUser(): ?HostUser;

    /**
     * List of tenants the active user can see. The host decides the
     * visibility rules (single-tenant returns one row; multi-tenant
     * returns the user's allowed tenants; global admin returns every
     * tenant).
     *
     * @return array<int,HostTenant>
     */
    public function listTenants(): array;

    /**
     * List API keys.
     *
     * R30: when `$userId` is non-null, the host MUST filter to keys
     * owned by that user; cross-user enumeration is a contract
     * violation. The controller passes `currentUser()->id` so the
     * default surface stays tenant- and user-safe.
     *
     * @param  int|string|null  $userId  null = sweep ALL keys (admin
     *                                   view); concrete id = scoped
     * @return array<int,HostApiKey>
     */
    public function listApiKeys(int|string|null $userId = null): array;

    /**
     * Mint a new API key. The host returns the row with the plaintext
     * token surfaced exactly once via {@see HostApiKey::$plaintext}.
     * List + show calls MUST omit the plaintext.
     *
     * @param  array<string,mixed>  $attrs  validated by
     *                                      `CreateApiKeyRequest`
     */
    public function createApiKey(array $attrs): HostApiKey;

    /**
     * Revoke an API key. Returns `true` when revoked, `false` when
     * the key did not exist or was already revoked.
     *
     * R30: the host MUST verify the key belongs to the active user
     * (or that the actor has cross-user admin rights) BEFORE
     * revoking. The package controller does not enforce that — the
     * host's auth middleware does.
     */
    public function revokeApiKey(string $id): bool;

    /**
     * Persist per-user preferences. The bag is schema-less; hosts
     * decide what they store. Returns the persisted shape.
     *
     * @param  array<string,mixed>  $prefs
     */
    public function savePreferences(int|string $userId, array $prefs): HostUserPreferences;

    /**
     * v1.5.0 W1.C — single audit row + drilldown payload, scoped to
     * the active tenant. Returns `null` when the row does not exist
     * OR when it exists but belongs to a different tenant — the
     * caller MUST NOT distinguish the two cases (404 in both, per
     * R30: existence of cross-tenant rows must not leak).
     *
     * The controller passes the trusted tenant id (resolved from the
     * `mcp_pack.tenant_id` middleware attribute) so the host can scope
     * the SELECT without trusting wire input. `null` `$tenantId` means
     * platform-global view — the host's auth middleware decided the
     * actor is allowed to see every tenant's rows.
     *
     * Returned shape mirrors the SPA `AUDIT_DETAIL` fixture in
     * `data.js`:
     * `{id, ts, tenant, server, server_id, method, tool, status, dur,
     *   actor, request, response, headers, timeline, meta}`.
     *
     * @return array<string,mixed>|null
     */
    public function auditFor(int|string $id, ?string $tenantId = null): ?array;

    /**
     * v1.5.0 W1.C — re-fire the audited tool call.
     *
     * **R21 (security-invariants-atomic-or-absent)** — hosts MUST
     * consume the `$token` argument inside a `DB::transaction` closure
     * using `lockForUpdate()` on the confirm-token row, and the
     * `used_at` write MUST happen in the SAME transaction. A two-step
     * read-then-write that leaves the lock window before the write is
     * a contract violation: two concurrent replays would both pass the
     * "unused?" check and both fire, defeating the single-use
     * semantics.
     *
     * The controller mints the token on the first POST (no token
     * supplied) and returns it under a 202 envelope; the second POST
     * carries the token back and that's the call that reaches the
     * host. Tokens that are reused, forged, or expired MUST surface
     * via the host throwing an exception the controller can map to
     * 422 (the controller does not introspect the host's persistence
     * — it owns mint + present, the host owns lock + consume + write).
     *
     * Returned shape: `{new_audit_id, result, latency_ms}` — the
     * `new_audit_id` is the freshly-written `mcp_tool_call_audit` row
     * so the SPA can deep-link to the replay.
     *
     * @return array<string,mixed>
     */
    public function replayAudit(int|string $id, ?string $token = null): array;

    /**
     * v1.5.0 W1.C — reset the circuit breaker for `(serverId, toolName)`.
     *
     * **R21** — same atomicity contract as {@see replayAudit()}: the
     * host MUST consume the `$token` inside a `DB::transaction` +
     * `lockForUpdate()` closure on the confirm-token row, with the
     * `used_at` write in the same transaction. The breaker mutation
     * happens AFTER the token is consumed, but inside the same
     * transaction so a transaction rollback also rolls back the
     * breaker reset.
     *
     * Returns `true` when the breaker had non-closed state to reset,
     * `false` when it was already closed (idempotent miss).
     */
    public function resetBreaker(string $serverId, string $toolName, ?string $token = null): bool;
}
