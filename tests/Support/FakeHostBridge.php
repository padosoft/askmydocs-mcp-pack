<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;

/**
 * Replays a pre-scripted queue of {@see HostChatResponse} objects.
 * Records every {@see HostChatTurn} it sees so tests can assert
 * what the orchestrator handed to the host.
 */
final class FakeHostBridge implements McpHostBridgeContract
{
    /** @var list<HostChatResponse> */
    public array $script;

    /** @var list<HostChatTurn> */
    public array $seenTurns = [];

    public bool $supportsTools = true;

    /** @param list<HostChatResponse> $script */
    public function __construct(array $script = [])
    {
        $this->script = $script;
    }

    public function chat(HostChatTurn $turn): HostChatResponse
    {
        $this->seenTurns[] = $turn;

        if ($this->script === []) {
            return new HostChatResponse(content: 'final', toolCalls: []);
        }

        return array_shift($this->script);
    }

    public function supportsToolCalling(): bool
    {
        return $this->supportsTools;
    }
}
