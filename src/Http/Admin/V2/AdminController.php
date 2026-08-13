<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Padosoft\AskMyDocsMcpPack\Artifacts\Artifact;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\ArtifactManagerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\AuthenticationResolverContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\TaskManagerContract;
use Padosoft\AskMyDocsMcpPack\Fluent\McpManager;
use Padosoft\AskMyDocsMcpPack\Models\McpArtifact;
use Padosoft\AskMyDocsMcpPack\Models\McpTask;
use Padosoft\AskMyDocsMcpPack\Protocol\ProtocolVersion;

final readonly class AdminController
{
    public function __construct(
        private McpManager $servers,
        private TaskManagerContract $tasks,
        private ArtifactManagerContract $artifacts,
        private AuthenticationResolverContract $auth,
    ) {}

    public function capabilities(Request $request): JsonResponse
    {
        $identity = $this->auth->resolve($request);

        return new JsonResponse([
            'protocolVersion' => ProtocolVersion::V2,
            'servers' => array_map(function ($server) use ($identity): array {
                $snapshot = $server->forTenant($identity['tenant_id'], $identity['principal_id'])->snapshot();

                return ['id' => $server->server->id, 'serverInfo' => $server->server->serverInfo(), 'revision' => $snapshot['revision'], 'digest' => $snapshot['digest']];
            }, $this->servers->all()),
            'features' => ['tasks' => $this->tasks->operational() && (bool) config('mcp-pack.tasks.enabled'), 'apps' => (bool) config('mcp-pack.apps.enabled'), 'artifacts' => (bool) config('mcp-pack.artifacts.enabled')],
        ]);
    }

    public function apps(Request $request): JsonResponse
    {
        if (! (bool) config('mcp-pack.apps.enabled', true)) {
            return new JsonResponse(['data' => []]);
        }
        $identity = $this->auth->resolve($request);
        $apps = [];
        foreach ($this->servers->all() as $server) {
            foreach ($server->forTenant($identity['tenant_id'], $identity['principal_id'])->all('apps') as $app) {
                $apps[] = ['server' => $server->server->id] + $app->toArray();
            }
        }

        return new JsonResponse(['data' => $apps]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $identity = $this->auth->resolve($request);
        $query = $this->scope(McpTask::query(), $identity)->where('expires_at', '>', now());

        return new JsonResponse(['data' => $query->orderByDesc('created_at')->limit(min(max((int) $request->integer('limit', 50), 1), 200))->get()->map->toProtocolArray()->values()]);
    }

    public function task(Request $request, string $task): JsonResponse
    {
        $identity = $this->auth->resolve($request);

        return new JsonResponse(['data' => $this->tasks->get($task, $identity['tenant_id'], $identity['principal_id'])->toProtocolArray()]);
    }

    public function updateTask(Request $request, string $task): JsonResponse
    {
        $validated = $request->validate(['inputResponses' => ['required', 'array'], 'requestState' => ['nullable', 'string', 'max:16384']]);
        $identity = $this->auth->resolve($request);
        try {
            $model = $this->tasks->update($task, $validated['inputResponses'], $identity['tenant_id'], $identity['principal_id'], $validated['requestState'] ?? null);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return new JsonResponse(['error' => 'task_conflict', 'message' => $e->getMessage()], 409);
        }

        return new JsonResponse(['data' => $model->toProtocolArray()]);
    }

    public function cancelTask(Request $request, string $task): JsonResponse
    {
        $identity = $this->auth->resolve($request);

        return new JsonResponse(['data' => $this->tasks->cancel($task, $identity['tenant_id'], $identity['principal_id'])->toProtocolArray()]);
    }

    public function artifacts(Request $request): JsonResponse
    {
        $identity = $this->auth->resolve($request);
        $query = $this->scope(McpArtifact::query(), $identity)->where('expires_at', '>', now());

        return new JsonResponse(['data' => $query->orderByDesc('created_at')->limit(min(max((int) $request->integer('limit', 50), 1), 200))->get()->map(fn (McpArtifact $item): array => $this->artifactArray($item))->values()]);
    }

    public function storeArtifact(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'], 'mimeType' => ['required', 'string', 'max:191'],
            'content' => ['nullable', 'string'], 'contentBase64' => ['nullable', 'string'], 'file' => ['nullable', 'file'],
            'annotations' => ['nullable', 'array'], 'metadata' => ['nullable', 'array'], 'ttlSeconds' => ['nullable', 'integer', 'min:1', 'max:604800'],
        ]);
        try {
            $artifact = Artifact::make($validated['name'])->mimeType($validated['mimeType']);
            if ($request->hasFile('file')) {
                $artifact->file((string) $request->file('file')->getRealPath());
            } elseif (isset($validated['contentBase64'])) {
                $decoded = base64_decode($validated['contentBase64'], true);
                if ($decoded === false) {
                    throw new \InvalidArgumentException('contentBase64 is invalid.');
                }
                $artifact->contents($decoded);
            } elseif (array_key_exists('content', $validated)) {
                $artifact->contents((string) $validated['content']);
            } else {
                throw new \InvalidArgumentException('One of file, content or contentBase64 is required.');
            }
            if (isset($validated['annotations'])) {
                $artifact->annotations($validated['annotations']);
            }
            if (isset($validated['metadata'])) {
                $artifact->metadata($validated['metadata']);
            }
            if (isset($validated['ttlSeconds'])) {
                $artifact->ttl($validated['ttlSeconds']);
            }
            $identity = $this->auth->resolve($request);
            $model = $this->artifacts->create($artifact, $identity['tenant_id'], $identity['principal_id']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['artifact' => $e->getMessage()]);
        }

        return new JsonResponse(['data' => $this->artifactArray($model)], 201);
    }

    public function artifact(Request $request, string $artifact): JsonResponse
    {
        $identity = $this->auth->resolve($request);
        $model = $this->artifacts->read($artifact, $identity['tenant_id'], $identity['principal_id']);

        return new JsonResponse(['data' => $this->artifactArray($model)]);
    }

    public function artifactUrl(Request $request, string $artifact): JsonResponse
    {
        $identity = $this->auth->resolve($request);

        return new JsonResponse(['url' => $this->artifacts->temporaryUrl($artifact, $identity['tenant_id'], $identity['principal_id'])]);
    }

    public function deleteArtifact(Request $request, string $artifact): JsonResponse
    {
        $identity = $this->auth->resolve($request);
        $this->artifacts->delete($artifact, $identity['tenant_id'], $identity['principal_id']);

        return new JsonResponse(null, 204);
    }

    /** @param array{tenant_id:?string,principal_id:?string} $identity */
    private function scope(object $query, array $identity): object
    {
        $identity['tenant_id'] === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $identity['tenant_id']);
        $identity['principal_id'] === null ? $query->whereNull('actor_id') : $query->where('actor_id', $identity['principal_id']);

        return $query;
    }

    /** @return array<string,mixed> */
    private function artifactArray(McpArtifact $artifact): array
    {
        return ['id' => $artifact->getKey(), 'uri' => 'artifact://'.$artifact->getKey(), 'name' => $artifact->name, 'mimeType' => $artifact->mime_type, 'size' => $artifact->size_bytes, 'sha256' => $artifact->sha256, 'annotations' => $artifact->annotations, 'metadata' => $artifact->metadata, 'expiresAt' => $artifact->expires_at?->toAtomString(), 'createdAt' => $artifact->created_at?->toAtomString()];
    }
}
