<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts\V2;

use Padosoft\AskMyDocsMcpPack\Artifacts\Artifact;
use Padosoft\AskMyDocsMcpPack\Models\McpArtifact;

interface ArtifactManagerContract
{
    public function create(Artifact $artifact, ?string $tenantId, ?string $actorId): McpArtifact;

    public function read(string $uuid, ?string $tenantId, ?string $actorId): McpArtifact;

    public function contents(McpArtifact $artifact): string;

    public function delete(string $uuid, ?string $tenantId, ?string $actorId): bool;

    public function temporaryUrl(string $uuid, ?string $tenantId, ?string $actorId, ?int $ttlSeconds = null): string;

    public function prune(): int;
}
