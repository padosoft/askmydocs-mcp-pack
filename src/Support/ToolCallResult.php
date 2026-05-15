<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

/**
 * Outcome of a single tool invocation. Fed back into the next chat
 * turn as a `tool` role message.
 *
 * `error` is non-null when the tool threw or the authorizer rejected
 * the call; the orchestrator MUST still surface it to the model so
 * the model can recover (e.g. retry with different arguments,
 * apologise to the user, hand off to another tool).
 */
final class ToolCallResult
{
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $toolName,
        public readonly mixed $result,
        public readonly ?string $error = null,
        public readonly float $latencyMs = 0.0,
    ) {}

    public function isError(): bool
    {
        return $this->error !== null;
    }

    /**
     * JSON-serialisable payload to pass back into the next chat turn
     * as the `content` of a `tool` role message.
     */
    public function toMessagePayload(): string
    {
        if ($this->isError()) {
            return json_encode(['error' => $this->error], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return json_encode(['result' => $this->result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
