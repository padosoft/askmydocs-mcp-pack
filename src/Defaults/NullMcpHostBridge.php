<?php

namespace Padosoft\AskMyDocsMcpPack\Defaults;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;

/**
 * Sentinel bridge used when the host has not wired its AI manager
 * yet. Every call throws — this guarantees that consumers
 * who forget to bind a real bridge get a loud, instantly-traceable
 * failure instead of silent no-ops.
 */
final class NullMcpHostBridge implements McpHostBridgeContract
{
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
