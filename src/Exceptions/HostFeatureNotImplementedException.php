<?php

namespace Padosoft\AskMyDocsMcpPack\Exceptions;

/**
 * v1.5.0 — sentinel thrown by the default `HasIdentitySurface` trait
 * implementations when the host has not wired the corresponding
 * bridge method. Controllers translate this into HTTP 501
 * (`feature_not_implemented`) so the SPA can render a graceful
 * "this surface is not enabled on your host" state instead of a
 * generic 500.
 *
 * The exception is named after the FEATURE, not the bridge method,
 * so its message stays useful when logged out of context.
 */
class HostFeatureNotImplementedException extends McpException
{
    public static function forFeature(string $feature): self
    {
        return new self(
            "The host's McpHostBridgeContract implementation does not provide [{$feature}]. "
            . 'Implement the corresponding method on your bridge, or `use HasIdentitySurface` '
            . 'in your bridge class to inherit a host-driven default.',
        );
    }
}
