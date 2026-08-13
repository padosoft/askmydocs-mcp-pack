<?php

namespace Padosoft\AskMyDocsMcpPack\Artifacts;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\ArtifactManagerContract;
use Padosoft\AskMyDocsMcpPack\Models\McpArtifact;

final class FlysystemArtifactManager implements ArtifactManagerContract
{
    /** @var list<string> */
    private const EXECUTABLE_MIMES = [
        'application/x-httpd-php', 'application/x-sh', 'application/x-csh', 'application/x-msdownload',
        'application/x-executable', 'application/vnd.microsoft.portable-executable', 'text/x-shellscript',
    ];

    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly ConnectionInterface $db,
        private readonly UrlGenerator $urls,
    ) {}

    public function create(Artifact $artifact, ?string $tenantId, ?string $actorId): McpArtifact
    {
        $limit = (int) config('mcp-pack.artifacts.max_bytes', 26_214_400);
        $bytes = $artifact->bytes($limit);
        if (strlen($bytes) > $limit) {
            throw new \InvalidArgumentException("Artifact exceeds the {$limit}-byte limit.");
        }
        $mime = $artifact->mime();
        if (in_array($mime, self::EXECUTABLE_MIMES, true)) {
            throw new \InvalidArgumentException("Executable artifact MIME [{$mime}] is not allowed.");
        }
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if (in_array($detected, self::EXECUTABLE_MIMES, true) || str_starts_with($bytes, "\x7FELF") || str_starts_with($bytes, 'MZ') || str_starts_with($bytes, '#!')) {
            throw new \InvalidArgumentException("Executable artifact content [{$detected}] is not allowed.");
        }
        $name = $this->sanitizeName($artifact->name());
        $uuid = (string) Str::uuid();
        $disk = (string) config('mcp-pack.artifacts.disk', config('filesystems.default'));
        $path = 'mcp-artifacts/'.hash('sha256', $tenantId ?? '_public').'/'.substr($uuid, 0, 2).'/'.$uuid;
        $storage = $this->filesystems->disk($disk);
        if (! $storage->put($path, $bytes, ['visibility' => 'private'])) {
            throw new \RuntimeException('Artifact storage write failed.');
        }
        try {
            return $this->db->transaction(fn (): McpArtifact => McpArtifact::query()->create([
                'id' => $uuid, 'tenant_id' => $tenantId, 'actor_id' => $actorId, 'name' => $name, 'mime_type' => $mime,
                'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'disk' => $disk, 'path' => $path,
                'annotations' => $artifact->annotationValues(), 'metadata' => $artifact->metadataValues(),
                'expires_at' => now()->addSeconds($artifact->ttlSeconds() ?? (int) config('mcp-pack.artifacts.ttl_seconds', 86_400)),
            ]));
        } catch (\Throwable $e) {
            try {
                $storage->delete($path);
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    public function read(string $uuid, ?string $tenantId, ?string $actorId): McpArtifact
    {
        $query = McpArtifact::query()->whereKey($uuid)->where('expires_at', '>', now());
        $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
        $actorId === null ? $query->whereNull('actor_id') : $query->where('actor_id', $actorId);

        return $query->firstOrFail();
    }

    public function contents(McpArtifact $artifact): string
    {
        $value = $this->filesystems->disk((string) $artifact->disk)->get((string) $artifact->path);
        if (! is_string($value) || ! hash_equals((string) $artifact->sha256, hash('sha256', $value))) {
            throw new \RuntimeException('Artifact integrity verification failed.');
        }

        return $value;
    }

    public function delete(string $uuid, ?string $tenantId, ?string $actorId): bool
    {
        return (bool) $this->read($uuid, $tenantId, $actorId)->delete();
    }

    public function temporaryUrl(string $uuid, ?string $tenantId, ?string $actorId, ?int $ttlSeconds = null): string
    {
        $artifact = $this->read($uuid, $tenantId, $actorId);
        $expires = now()->addSeconds(max(1, min($ttlSeconds ?? (int) config('mcp-pack.artifacts.signed_url_ttl_seconds', 300), 3600)));
        try {
            return $this->filesystems->disk((string) $artifact->disk)->temporaryUrl((string) $artifact->path, $expires, ['ResponseContentDisposition' => 'attachment; filename="'.addcslashes((string) $artifact->name, '"\\').'"']);
        } catch (\Throwable) {
            return $this->urls->temporarySignedRoute('mcp-pack.v2.artifacts.download', $expires, ['artifact' => $artifact->getKey()]);
        }
    }

    public function prune(): int
    {
        $count = 0;
        McpArtifact::withTrashed()->where(function ($query): void {
            $query->where('expires_at', '<=', now())->orWhereNotNull('deleted_at');
        })->orderBy('id')->chunkById(100, function ($artifacts) use (&$count): void {
            foreach ($artifacts as $artifact) {
                try {
                    $this->filesystems->disk((string) $artifact->disk)->delete((string) $artifact->path);
                } catch (\Throwable) {
                    continue;
                }
                $artifact->forceDelete();
                $count++;
            }
        }, 'id');

        return $count;
    }

    private function sanitizeName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $name))));
        if ($name === '' || in_array($name, ['.', '..'], true)) {
            $name = 'artifact';
        }

        return mb_substr($name, 0, 180);
    }
}
