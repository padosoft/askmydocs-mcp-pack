<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns;

use Illuminate\Http\JsonResponse;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\InvalidConfirmTokenException;
use Padosoft\AskMyDocsMcpPack\Support\McpAdminConfirmToken;

/**
 * v1.5.0 W1.C — shared mint + present logic for the destructive
 * admin operations that need R21 single-use confirm tokens (audit
 * replay, breaker reset, destructive tool invoke).
 *
 * ## Iter-1 (W1.C): division of responsibility — package vs host
 *
 *  - The package **mints** the cryptographic token value + scope +
 *    target id + tenant id + expiry, then hands the value object to
 *    the host via
 *    {@see McpHostBridgeIdentityContract::mintConfirmToken()}. The
 *    host chooses persistence — `Cache::put()` (trait default; works
 *    on single-node + atomic-cache stores), or a DB-backed table
 *    `mcp_admin_confirm_tokens` with `(hashed_token, scope, target_id,
 *    tenant_id, expires_at, used_at)` columns (recommended for
 *    multi-node production).
 *
 *  - The host **consumes** the token via
 *    {@see McpHostBridgeIdentityContract::consumeConfirmToken()}.
 *    Production hosts override this with the R21-correct pattern:
 *    `DB::transaction(fn() => $row->lockForUpdate()->first(); …; $row->update('used_at'))`,
 *    keeping the lock window OPEN until the `used_at` write commits.
 *    Releasing the lock between read and write is a TOCTOU bug that
 *    defeats single-use semantics.
 *
 *  - The host throws
 *    {@see \Padosoft\AskMyDocsMcpPack\Exceptions\InvalidConfirmTokenException}
 *    on any consume failure (forged, expired, already-used,
 *    scope/target/tenant mismatch). The controllers using this trait
 *    catch that exception and surface HTTP 422
 *    `confirmation_invalid` — never a 500.
 *
 *  - The controller-side business action (replay tool call, reset
 *    breaker, invoke destructive tool) ideally runs **inside the
 *    same `DB::transaction` as the consume** so a transaction
 *    rollback also rolls back the destructive effect. This is
 *    documented on each contract method; the package does not
 *    enforce the wrapping itself because it lives below the package
 *    abstraction layer.
 *
 * ## Pre-iter-1 design (now superseded)
 *
 * The previous design kept the token marker only in the package
 * cache and never gave the host visibility into the mint event;
 * Copilot flagged that "a conforming host has no row to consume" so
 * real DB-backed hosts could not enforce R21 single-use. Iter-1 fix
 * routes mint + consume through the bridge so the host owns the
 * source of truth.
 */
trait MintsConfirmTokens
{
    /**
     * Build a 202 response carrying a freshly-minted confirm token,
     * keyed by `(scope, targetId)` so a single client cannot reuse
     * one token across two targets.
     *
     * @param 'audit_replay'|'breaker_reset'|'tool_invoke' $scope
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

        $bridge = app(McpHostBridgeIdentityContract::class);
        $bridge->mintConfirmToken($token);

        return new JsonResponse(
            ['data' => $token->toMintResponse()],
            202,
        );
    }

    /**
     * Atomically consume a presented token via the host bridge.
     * Returns `null` when the consume succeeded (caller proceeds);
     * returns the 422 envelope JSON response when consume failed —
     * the caller can `return` it directly.
     *
     * The actual DB lock + write happens inside the host's
     * `consumeConfirmToken` implementation; the controller does NOT
     * introspect the host's persistence.
     */
    protected function consumeConfirmToken(
        string $scope,
        string $targetId,
        ?string $tenantId,
        string $token,
    ): ?JsonResponse {
        try {
            $bridge = app(McpHostBridgeIdentityContract::class);
            $bridge->consumeConfirmToken($token, $scope, $targetId, $tenantId);
            return null;
        } catch (InvalidConfirmTokenException $e) {
            return $this->confirmTokenInvalid($e->getMessage());
        }
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
