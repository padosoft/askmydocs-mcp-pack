<?php

namespace Padosoft\AskMyDocsMcpPack\Exceptions;

/**
 * v1.5.0 W1.C iter-1 — thrown by
 * `McpHostBridgeIdentityContract::consumeConfirmToken()` when the
 * token presented at the admin endpoint cannot be honored:
 *
 *  - The token was never minted (forged / random string);
 *  - The token was minted but for a different scope / target / tenant;
 *  - The token has already been consumed (single-use, per R21);
 *  - The token has expired past its TTL.
 *
 * The admin controllers (`AuditController::replay`,
 * `CircuitBreakerController::reset`, the destructive branch of
 * `ServersController::invoke`) catch this exception and surface it
 * as HTTP 422 with the standard `confirmation_invalid` envelope —
 * never a 500. This is the bridge between the package's controller
 * layer and the host's R21 atomic consume implementation: the host
 * throws this type, the controller maps it to the documented JSON
 * envelope.
 *
 * Why a dedicated type: previously the bridge's
 * `HostFeatureNotImplementedException` was the only contract
 * exception, so a host's R21 consume failure (the most common
 * runtime case) would either crash the controller (500) or pretend
 * the call succeeded. Distinguishing "host did not implement" (501)
 * from "host implemented + token was invalid" (422) is essential to
 * R14 (surface failures loudly with the correct status code).
 */
final class InvalidConfirmTokenException extends McpException
{
    public static function forForged(string $token, string $scope): self
    {
        return new self("Confirm token [{$token}] for scope [{$scope}] was not minted or already consumed.");
    }

    public static function forExpired(string $token, string $scope): self
    {
        return new self("Confirm token [{$token}] for scope [{$scope}] has expired.");
    }

    public static function forMismatch(string $token, string $field): self
    {
        return new self("Confirm token [{$token}] {$field} mismatch.");
    }

    public static function forConsumed(string $token, string $scope): self
    {
        return new self("Confirm token [{$token}] for scope [{$scope}] has already been consumed (single-use per R21).");
    }
}
