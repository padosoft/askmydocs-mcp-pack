<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts\Concerns;

use Illuminate\Support\Facades\Cache;
use Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException;
use Padosoft\AskMyDocsMcpPack\Exceptions\InvalidConfirmTokenException;
use Padosoft\AskMyDocsMcpPack\Support\HostApiKey;
use Padosoft\AskMyDocsMcpPack\Support\HostTenant;
use Padosoft\AskMyDocsMcpPack\Support\HostUser;
use Padosoft\AskMyDocsMcpPack\Support\HostUserPreferences;
use Padosoft\AskMyDocsMcpPack\Support\McpAdminConfirmToken;

/**
 * v1.5.0 — default implementation of the 9 identity / audit-replay /
 * breaker-reset surface methods added to
 * {@see \Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract}.
 *
 * Existing host bridges that pre-date v1.5 can adopt the new contract
 * surface by declaring `use HasIdentitySurface;` — every method then
 * throws {@see HostFeatureNotImplementedException}, which the admin
 * controllers translate into HTTP 501 with a stable JSON envelope.
 * Hosts override the methods they ACTUALLY want to expose; the
 * rest stay 501.
 *
 * The trait deliberately does NOT touch `chat()` or
 * `supportsToolCalling()` — those remain required of every bridge
 * (they were the v1.0 contract surface, and a host that does not
 * implement them simply cannot do tool calling).
 *
 * Backward-compat note: hosts shipping their own bridge BEFORE v1.5
 * must add `use HasIdentitySurface;` (or implement the new methods
 * themselves) when they bump the package. The shipped
 * `NullMcpHostBridge` adopts the trait so the package's own service
 * provider keeps booting on a fresh install.
 */
trait HasIdentitySurface
{
    public function currentUser(): ?HostUser
    {
        throw HostFeatureNotImplementedException::forFeature('currentUser');
    }

    /** @return array<int,HostTenant> */
    public function listTenants(): array
    {
        throw HostFeatureNotImplementedException::forFeature('listTenants');
    }

    /**
     * @param  int|string|null  $userId  null = all keys for the active
     *                                   tenant; concrete id = scoped to
     *                                   that user
     * @return array<int,HostApiKey>
     */
    public function listApiKeys(int|string|null $userId = null): array
    {
        throw HostFeatureNotImplementedException::forFeature('listApiKeys');
    }

    /**
     * @param  array<string,mixed>  $attrs  validated by CreateApiKeyRequest
     */
    public function createApiKey(array $attrs): HostApiKey
    {
        throw HostFeatureNotImplementedException::forFeature('createApiKey');
    }

    public function revokeApiKey(string $id): bool
    {
        throw HostFeatureNotImplementedException::forFeature('revokeApiKey');
    }

    /**
     * @param  array<string,mixed>  $prefs  free-form bag — the host
     *                                      decides what it persists
     */
    public function savePreferences(int|string $userId, array $prefs): HostUserPreferences
    {
        throw HostFeatureNotImplementedException::forFeature('savePreferences');
    }

    /**
     * Returns the audit row + drilldown payload for a single
     * `mcp_tool_call_audit` id scoped to the active tenant, or `null`
     * when not visible. Used by W1.C `AuditController::show()`. The
     * controller passes the trusted tenant id resolved from the
     * `mcp_pack.tenant_id` middleware attribute.
     *
     * @return array<string,mixed>|null
     */
    public function auditFor(int|string $id, ?string $tenantId = null): ?array
    {
        throw HostFeatureNotImplementedException::forFeature('auditFor');
    }

    /**
     * Re-fires the audited tool call. Hosts MUST honour R21 atomic
     * single-use semantics when implementing this — the package
     * provides the replay-log table in W1.C; the bridge wires the
     * actual `ToolInvoker` dispatch.
     *
     * @return array<string,mixed>
     */
    public function replayAudit(int|string $id, ?string $token = null): array
    {
        throw HostFeatureNotImplementedException::forFeature('replayAudit');
    }

    /**
     * Resets the circuit breaker for `(serverId, toolName)`. Returns
     * `true` when the breaker had state to reset, `false` when it was
     * already closed.
     */
    public function resetBreaker(string $serverId, string $toolName, ?string $token = null): bool
    {
        throw HostFeatureNotImplementedException::forFeature('resetBreaker');
    }

    /**
     * v1.5.0 W1.C iter-1 — default confirm-token persistence via the
     * configured cache store. Production hosts SHOULD override with
     * the DB-backed pattern documented on
     * {@see \Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract::mintConfirmToken()}
     * so R21 atomic single-use semantics actually hold across
     * concurrent presents.
     *
     * The trait default keeps the package self-contained for hosts
     * that have not yet wired their own confirm-token storage — the
     * SPA's destructive-action UX works for free.
     */
    public function mintConfirmToken(McpAdminConfirmToken $token): void
    {
        $ttl = max(1, $token->expiresAt - time());
        Cache::put(
            self::confirmTokenCacheKey($token->scope, $token->targetId, $token->token),
            [
                'scope' => $token->scope,
                'target_id' => $token->targetId,
                'tenant_id' => $token->tenantId,
                'expires_at' => $token->expiresAt,
            ],
            $ttl,
        );
    }

    /**
     * v1.5.0 W1.C iter-1 — default consume via `Cache::pull` (atomic
     * remove + fetch on Redis; race-windowed on file/array). Throws
     * {@see InvalidConfirmTokenException} on any failure mode so the
     * admin controllers can map to HTTP 422 `confirmation_invalid`.
     *
     * Tenant binding is STRICT: a token minted with a concrete tenant
     * MUST be presented under that same tenant; `null` minted MUST be
     * `null` presented. No `null ↔ non-null` transitions.
     */
    public function consumeConfirmToken(string $token, string $scope, string $targetId, ?string $tenantId): void
    {
        $key = self::confirmTokenCacheKey($scope, $targetId, $token);
        $record = Cache::pull($key);
        if (! is_array($record)) {
            throw InvalidConfirmTokenException::forForged($token, $scope);
        }
        if (($record['scope'] ?? null) !== $scope) {
            throw InvalidConfirmTokenException::forMismatch($token, 'scope');
        }
        if (($record['target_id'] ?? null) !== $targetId) {
            throw InvalidConfirmTokenException::forMismatch($token, 'target_id');
        }
        // R30 strict tenant binding — null ↔ non-null mismatch is
        // ALSO rejected, not silently tolerated.
        $recordTenant = $record['tenant_id'] ?? null;
        if ($recordTenant !== $tenantId) {
            throw InvalidConfirmTokenException::forMismatch($token, 'tenant_id');
        }
        if (isset($record['expires_at']) && time() >= (int) $record['expires_at']) {
            throw InvalidConfirmTokenException::forExpired($token, $scope);
        }
    }

    private static function confirmTokenCacheKey(string $scope, string $targetId, string $token): string
    {
        return "mcp-pack.confirm-token:{$scope}:{$targetId}:{$token}";
    }

    // ----- v1.5.0 W1.D — resources / prompts / SSE defaults -----------

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listResources(string $serverId, ?string $tenantId = null): array
    {
        throw HostFeatureNotImplementedException::forFeature('listResources');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resourceContent(string $serverId, string $uri, ?string $tenantId = null): ?array
    {
        throw HostFeatureNotImplementedException::forFeature('resourceContent');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listPrompts(string $serverId, ?string $tenantId = null): array
    {
        throw HostFeatureNotImplementedException::forFeature('listPrompts');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function promptDetail(string $serverId, string $name, ?string $tenantId = null): ?array
    {
        throw HostFeatureNotImplementedException::forFeature('promptDetail');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recentAudit(int|string|null $sinceId = null, ?string $tenantId = null): array
    {
        throw HostFeatureNotImplementedException::forFeature('recentAudit');
    }
}
