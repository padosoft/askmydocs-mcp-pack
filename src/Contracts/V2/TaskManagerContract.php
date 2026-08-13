<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts\V2;

use Padosoft\AskMyDocsMcpPack\Models\McpTask;
use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;

interface TaskManagerContract
{
    public function operational(): bool;

    /** @param class-string $handler */
    /** @param array<string,mixed>|null $outputSchema */
    public function create(string $handler, McpRequest $request, ?int $ttlSeconds = null, ?array $outputSchema = null): McpTask;

    public function get(string $uuid, ?string $tenantId, ?string $actorId): McpTask;

    /** @param array<string,mixed> $inputResponses */
    public function update(string $uuid, array $inputResponses, ?string $tenantId, ?string $actorId, ?string $requestState = null): McpTask;

    public function cancel(string $uuid, ?string $tenantId, ?string $actorId): McpTask;

    public function prune(): int;

    public function recover(): int;
}
