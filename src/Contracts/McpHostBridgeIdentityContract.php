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
     * (Signature reserved for W1.C) — single audit row + drilldown
     * payload. Returns `null` when the row is not visible to the
     * active tenant.
     *
     * @return array<string,mixed>|null
     */
    public function auditFor(int|string $id): ?array;

    /**
     * (Signature reserved for W1.C) — re-fire the audited tool call.
     * Hosts MUST honour R21 single-use semantics on the `$token`
     * argument inside a `DB::transaction` closure.
     *
     * @return array<string,mixed>
     */
    public function replayAudit(int|string $id, ?string $token = null): array;

    /**
     * (Signature reserved for W1.C) — reset the circuit breaker for
     * `(serverId, toolName)` under an R21 single-use token guard.
     * Returns `true` when the breaker had state to reset.
     */
    public function resetBreaker(string $serverId, string $toolName, ?string $token = null): bool;
}
