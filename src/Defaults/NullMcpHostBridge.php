<?php

namespace Padosoft\AskMyDocsMcpPack\Defaults;

use Padosoft\AskMyDocsMcpPack\Contracts\Concerns\HasIdentitySurface;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;

/**
 * Sentinel bridge used when the host has not wired its AI manager
 * yet. The `chat()` call throws — this guarantees that consumers
 * who forget to bind a real bridge get a loud, instantly-traceable
 * failure instead of silent no-ops.
 *
 * v1.5.0 — adopts {@see HasIdentitySurface}, so every identity /
 * audit / breaker method throws
 * {@see \Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException}.
 * The admin controllers map those into HTTP 501
 * (`feature_not_implemented`), keeping the SPA degraded but
 * deterministic until the host wires a real bridge.
 */
final class NullMcpHostBridge implements McpHostBridgeContract
{
    use HasIdentitySurface;

    public function chat(HostChatTurn $turn): HostChatResponse
    {
        throw new \LogicException(
            'NullMcpHostBridge::chat() invoked. '
            . 'Bind a real McpHostBridgeContract implementation in your service provider.',
        );
    }

    public function supportsToolCalling(): bool
    {
        return false;
    }
}
