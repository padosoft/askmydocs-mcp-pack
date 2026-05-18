<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

/**
 * v1.5.0 W1.C — single-use confirm token for destructive admin
 * operations (audit replay, breaker reset, future write-paths).
 *
 * The package's controllers mint the token on the first POST and
 * return it under a 202 envelope; the SPA POSTs it back on the
 * second call and the host's bridge consumes it inside a
 * `DB::transaction(fn() => lockForUpdate->first(); ...->update(used_at))`
 * closure. R21 (security-invariants-atomic-or-absent) — the lock
 * window MUST hold until the `used_at` write is recorded; releasing
 * the lock between read and write opens a TOCTOU window that
 * defeats single-use semantics.
 *
 * This value object is the wire shape between controller and bridge.
 * Persistence lives on the host side (the host owns the table); the
 * package only mints + presents.
 *
 * Fields:
 *  - `token`      — opaque bearer (`tok_` + 32 hex). The package
 *                   never logs the plaintext — only the `target_id`
 *                   + `scope` reach logs.
 *  - `scope`      — one of `audit_replay` / `breaker_reset` (future:
 *                   any single-use admin action). Lets the host
 *                   reject a token presented to the wrong endpoint.
 *  - `target_id`  — the resource the token authorises action against
 *                   (audit id for replay; `<server_id>:<tool_name>`
 *                   for breaker reset). Lets the host reject a token
 *                   minted for resource A presented to resource B.
 *  - `tenant_id`  — the active tenant at mint time. Lets the host
 *                   reject a token minted under tenant A presented
 *                   under tenant B (defence in depth against any
 *                   middleware bug that misroutes the active
 *                   tenant).
 *  - `expires_at` — unix epoch seconds. Tokens are short-lived
 *                   (default 120s — see `replays_in_seconds` in the
 *                   controller); a token past `expires_at` is
 *                   rejected as `token_consumed` (same surface as
 *                   reuse — both are "this token can no longer be
 *                   used").
 *  - `used_at`    — unix epoch seconds when the host consumed the
 *                   token. `null` on freshly-minted tokens; set
 *                   inside the host's transaction once the action
 *                   commits.
 */
final class McpAdminConfirmToken
{
    public const SCOPE_AUDIT_REPLAY = 'audit_replay';
    public const SCOPE_BREAKER_RESET = 'breaker_reset';

    /** @param 'audit_replay'|'breaker_reset' $scope */
    public function __construct(
        public readonly string $token,
        public readonly string $scope,
        public readonly string $targetId,
        public readonly ?string $tenantId,
        public readonly int $expiresAt,
        public readonly ?int $usedAt = null,
    ) {}

    /**
     * Mint a fresh token bound to `(scope, targetId, tenantId)` with
     * a `$ttlSeconds`-second lifetime. The plaintext is `tok_` +
     * 32 hex chars from `random_bytes(16)` — 128 bits of entropy is
     * overkill for a 120-second window but matches the
     * `padosoft/laravel-flow` token convention so the pack stays
     * consistent across the v4.x ecosystem.
     *
     * @param 'audit_replay'|'breaker_reset' $scope
     */
    public static function mint(
        string $scope,
        string $targetId,
        ?string $tenantId,
        int $ttlSeconds = 120,
    ): self {
        return new self(
            token: 'tok_' . bin2hex(random_bytes(16)),
            scope: $scope,
            targetId: $targetId,
            tenantId: $tenantId,
            expiresAt: time() + max(1, $ttlSeconds),
            usedAt: null,
        );
    }

    public function isExpired(int $now = 0): bool
    {
        return ($now === 0 ? time() : $now) >= $this->expiresAt;
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    /**
     * Wire shape returned in the 202 envelope on the mint POST.
     *
     * @return array{confirm_token:string, scope:string, target_id:string, replays_in_seconds:int}
     */
    public function toMintResponse(int $now = 0): array
    {
        $now = $now === 0 ? time() : $now;
        return [
            'confirm_token' => $this->token,
            'scope' => $this->scope,
            'target_id' => $this->targetId,
            'replays_in_seconds' => max(0, $this->expiresAt - $now),
        ];
    }
}
