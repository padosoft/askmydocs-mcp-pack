<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

/**
 * Stable, protocol-era-neutral view of a remote MCP Task.
 *
 * MCP 2026-07-28 returns task fields at the result root. Pre-release and
 * legacy implementations may still wrap them in a `task` object or use the
 * old `ttl` / `pollInterval` names. The raw envelope remains available so
 * future extension fields are never discarded.
 */
final readonly class McpRemoteTask
{
    /**
     * @param  array<string,mixed>  $inputRequests
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public string $taskId,
        public string $status,
        public ?string $statusMessage,
        public ?string $createdAt,
        public ?string $lastUpdatedAt,
        public ?int $ttlMs,
        public ?int $pollIntervalMs,
        public array $inputRequests,
        public mixed $result,
        public mixed $error,
        public array $raw,
    ) {}

    /** @param array<string,mixed> $envelope */
    public static function fromEnvelope(array $envelope): self
    {
        $task = is_array($envelope['task'] ?? null) ? $envelope['task'] : $envelope;
        $taskId = $task['taskId'] ?? null;
        $status = $task['status'] ?? null;
        if (! is_string($taskId) || $taskId === '' || ! is_string($status) || $status === '') {
            throw new \InvalidArgumentException('MCP Task response is missing taskId or status.');
        }

        $ttl = $task['ttlMs'] ?? $task['ttl'] ?? null;
        $pollInterval = $task['pollIntervalMs'] ?? $task['pollInterval'] ?? null;
        $inputRequests = $task['inputRequests'] ?? [];

        return new self(
            taskId: $taskId,
            status: $status,
            statusMessage: is_string($task['statusMessage'] ?? null) ? $task['statusMessage'] : null,
            createdAt: is_string($task['createdAt'] ?? null) ? $task['createdAt'] : null,
            lastUpdatedAt: is_string($task['lastUpdatedAt'] ?? null) ? $task['lastUpdatedAt'] : null,
            ttlMs: is_int($ttl) ? max(0, $ttl) : null,
            pollIntervalMs: is_int($pollInterval) ? max(0, $pollInterval) : null,
            inputRequests: is_array($inputRequests) ? $inputRequests : [],
            result: $task['result'] ?? null,
            error: $task['error'] ?? null,
            raw: $envelope,
        );
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled'], true);
    }

    public function requiresInput(): bool
    {
        return $this->status === 'input_required';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'taskId' => $this->taskId,
            'status' => $this->status,
            'statusMessage' => $this->statusMessage,
            'createdAt' => $this->createdAt,
            'lastUpdatedAt' => $this->lastUpdatedAt,
            'ttlMs' => $this->ttlMs,
            'pollIntervalMs' => $this->pollIntervalMs,
            'inputRequests' => $this->inputRequests === [] ? null : $this->inputRequests,
            'result' => $this->result,
            'error' => $this->error,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
