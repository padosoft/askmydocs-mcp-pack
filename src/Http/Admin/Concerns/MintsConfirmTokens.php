<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Padosoft\AskMyDocsMcpPack\Support\McpAdminConfirmToken;

/**
 * v1.5.0 W1.C — shared mint + present logic for the destructive
 * admin operations that need R21 single-use confirm tokens (audit
 * replay, breaker reset).
 *
 * **Division of responsibility between package and host:**
 *
 *  - The package owns **mint + present**. On the first POST it
 *    generates a fresh `tok_<hex>` token and parks the value-object
 *    in the configured cache store under
 *    `mcp-pack.confirm-token:{scope}:{target}:{token}` with a short
 *    TTL (default 120s). On the second POST it checks the cache
 *    presence — that's how forged tokens (random strings that never
 *    saw the mint side) get rejected with 422 `confirmation_invalid`.
 *
 *  - The host owns **atomic consume + business action**. The host's
 *    bridge implementation receives the token via
 *    {@see \Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract::replayAudit()}
 *    or {@see \Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract::resetBreaker()}
 *    and MUST consume it inside a `DB::transaction(fn() =>
 *    lockForUpdate->first(); ...->update('used_at')` closure on its
 *    own persistence (e.g. an `mcp_admin_confirm_tokens` table). R21
 *    requires the lock window to hold UNTIL the `used_at` write
 *    commits; releasing the lock between read and write opens a
 *    TOCTOU race that defeats single-use semantics.
 *
 *  - After the host returns success, the package deletes the cache
 *    entry so a reused token on the next POST also surfaces as
 *    `confirmation_invalid`. The cache delete is BEST-effort; the
 *    host's `used_at` flag is the source of truth for "this token
 *    has been spent". Hosts MUST treat a token with `used_at != null`
 *    as already-consumed even if the package re-presents it.
 *
 * Tenant scoping: the minted token records the active tenant id so
 * the host's persistence can include `WHERE tenant_id = ?` in its
 * lookup — defence in depth against any middleware bug that
 * misroutes the active tenant between mint and consume.
 */
trait MintsConfirmTokens
{
    /**
     * Build a 202 response carrying a freshly-minted confirm token,
     * keyed by `(scope, targetId)` so a single client cannot reuse
     * one token across two targets.
     *
     * @param 'audit_replay'|'breaker_reset' $scope
     */
    protected function mintConfirmToken(
        string $scope,
        string $targetId,
        ?string $tenantId,
        int $ttlSeconds = 120,
    ): JsonResponse {
        $token = McpAdminConfirmToken::mint(
            scope: $scope,
            targetId: $targetId,
            tenantId: $tenantId,
            ttlSeconds: $ttlSeconds,
        );

        Cache::put(
            $this->confirmTokenCacheKey($scope, $targetId, $token->token),
            [
                'scope' => $token->scope,
                'target_id' => $token->targetId,
                'tenant_id' => $token->tenantId,
                'expires_at' => $token->expiresAt,
            ],
            $ttlSeconds,
        );

        return new JsonResponse(
            ['data' => $token->toMintResponse()],
            202,
        );
    }

    /**
     * Validate a presented token against the cache + scope + target.
     * Returns `null` when valid (caller should proceed to host
     * bridge); otherwise returns the 422 envelope the caller can
     * `return` directly.
     */
    protected function validateConfirmToken(
        string $scope,
        string $targetId,
        ?string $tenantId,
        string $token,
    ): ?JsonResponse {
        $record = Cache::get($this->confirmTokenCacheKey($scope, $targetId, $token));
        if (! is_array($record)) {
            return $this->confirmTokenInvalid("Confirm token not recognised or already consumed.");
        }

        // Defence in depth: a cache record minted under a different
        // scope / target / tenant must not authorise this call. (The
        // cache key already encodes scope + target, so a same-token
        // mismatch is structurally impossible — but the tenant check
        // catches a middleware bug between mint and consume.)
        if (($record['scope'] ?? null) !== $scope) {
            return $this->confirmTokenInvalid("Confirm token scope mismatch.");
        }
        if (($record['target_id'] ?? null) !== $targetId) {
            return $this->confirmTokenInvalid("Confirm token target mismatch.");
        }
        if ($tenantId !== null && ($record['tenant_id'] ?? null) !== null
            && $record['tenant_id'] !== $tenantId) {
            return $this->confirmTokenInvalid("Confirm token tenant mismatch.");
        }
        if (isset($record['expires_at']) && time() >= (int) $record['expires_at']) {
            // The cache will TTL-evict this on its own, but a race
            // between TTL and our `time()` check is possible; reject
            // explicitly so the SPA shows a clean "token expired"
            // message.
            $this->forgetConfirmToken($scope, $targetId, $token);
            return $this->confirmTokenInvalid("Confirm token expired.");
        }

        return null;
    }

    /**
     * Best-effort delete on the confirm-token cache entry — invoked
     * AFTER the host bridge returns success so a re-presented token
     * cannot drive a second action. The host's `used_at` flag is the
     * authoritative single-use guard; this is the package-side belt
     * on top of the host-side braces.
     */
    protected function forgetConfirmToken(string $scope, string $targetId, string $token): void
    {
        Cache::forget($this->confirmTokenCacheKey($scope, $targetId, $token));
    }

    private function confirmTokenCacheKey(string $scope, string $targetId, string $token): string
    {
        return "mcp-pack.confirm-token:{$scope}:{$targetId}:{$token}";
    }

    private function confirmTokenInvalid(string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'confirmation_invalid',
                'message' => $message,
            ],
        ], 422);
    }
}
