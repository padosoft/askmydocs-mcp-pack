<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

/**
 * Provider-agnostic response shape returned by
 * {@see \Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract::chat()}.
 *
 * Bridges normalize provider responses into THIS shape so the
 * orchestrator can drive the multi-turn loop without knowing whether
 * the underlying call hit OpenAI, Anthropic, Gemini, or OpenRouter.
 *
 * Either `content` or `toolCalls` (or both) may be set:
 *   - `content` is the model's natural-language output (final answer
 *     or interleaved reasoning).
 *   - `toolCalls` is the structured list of tool invocations the model
 *     wants to make next.
 */
final class HostChatResponse
{
    /**
     * @param array<int,array{id:string,name:string,arguments:array<string,mixed>}> $toolCalls
     * @param array<string,mixed>                                                   $usage
     */
    public function __construct(
        public readonly ?string $content,
        public readonly array $toolCalls = [],
        public readonly ?string $finishReason = null,
        public readonly array $usage = [],
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
    ) {}

    public function hasToolCalls(): bool
    {
        return count($this->toolCalls) > 0;
    }
}
