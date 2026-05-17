<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Contracts\Concerns\HasIdentitySurface;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;

/**
 * Replays a pre-scripted queue of {@see HostChatResponse} objects.
 * Records every {@see HostChatTurn} it sees so tests can assert
 * what the orchestrator handed to the host.
 *
 * v1.5.0 — pulls in `HasIdentitySurface` so the orchestrator tests
 * compile against the extended contract surface; identity methods
 * throw 501 unless a test overrides them.
 */
final class FakeHostBridge implements McpHostBridgeContract
{
    use HasIdentitySurface;

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
