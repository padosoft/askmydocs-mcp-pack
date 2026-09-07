<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

/**
 * Lossless, stable view of an MCP tools/call result.
 *
 * Unknown fields remain available through $raw so newer protocol
 * revisions can pass through this package without data loss.
 */
final readonly class McpToolResult
{
    /**
     * @param  list<array<string,mixed>>  $content
     * @param  array<string,mixed>|null  $structuredContent
     * @param  array<string,mixed>  $meta
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public array $content,
        public ?array $structuredContent,
        public bool $isError,
        public array $meta,
        public ?string $resultType,
        public mixed $requestState,
        public mixed $inputRequests,
        public mixed $task,
        public array $raw,
    ) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $content = $payload['content'] ?? [];
        $structured = $payload['structuredContent'] ?? null;
        $meta = $payload['_meta'] ?? [];

        return new self(
            content: is_array($content) ? array_values(array_filter($content, 'is_array')) : [],
            structuredContent: is_array($structured) ? $structured : null,
            isError: (bool) ($payload['isError'] ?? false),
            meta: is_array($meta) ? $meta : [],
            resultType: is_string($payload['resultType'] ?? null) ? $payload['resultType'] : null,
            requestState: $payload['requestState'] ?? null,
            inputRequests: $payload['requests'] ?? $payload['inputRequests'] ?? $payload['inputRequest'] ?? null,
            task: $payload['task'] ?? null,
            raw: $payload,
        );
    }

    public function isInputRequired(): bool
    {
        return $this->resultType === 'input_required' || $this->inputRequests !== null;
    }

    public function isTask(): bool
    {
        return $this->resultType === 'task' || $this->task !== null;
    }

    public function remoteTask(): ?McpRemoteTask
    {
        if (! $this->isTask()) {
            return null;
        }

        return McpRemoteTask::fromEnvelope($this->raw);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
