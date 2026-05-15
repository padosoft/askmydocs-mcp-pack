<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;

/**
 * Immutable per-turn payload handed by the orchestrator to the host
 * bridge ({@see \Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract::chat()}).
 *
 * Carries the conversation buffer, the (already-authorized) tool
 * catalog, and provider-agnostic extras (temperature, seed, system
 * preamble). Bridges translate this into provider-native shapes
 * (OpenAI ChatCompletion, Anthropic Messages, Gemini GenerateContent).
 */
final class HostChatTurn
{
    /**
     * @param array<int,array{role:string,content:string,tool_call_id?:string,tool_calls?:array<int,mixed>,name?:string}> $messages
     * @param array<int,McpToolContract>                                                                                  $tools
     * @param array<string,mixed>                                                                                         $extras
     */
    public function __construct(
        public readonly array $messages,
        public readonly array $tools,
        public readonly ?string $tenantId = null,
        public readonly array $extras = [],
    ) {}
}
