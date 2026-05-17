<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\ResolvesAdminContext;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Requests\CreateApiKeyRequest;
use Padosoft\AskMyDocsMcpPack\Support\HostApiKey;

/**
 * v1.5.0 — `GET /api-keys`, `POST /api-keys`, `DELETE /api-keys/{id}`.
 *
 * R30 (cross-tenant + cross-user isolation): list + create + revoke
 * are scoped to `currentUser()->id`. The package does not surface an
 * "all users on this tenant" view here — hosts that need it bind a
 * separate admin controller. This refusal-by-default keeps a
 * regular user from listing or revoking another user's keys, even
 * if the host's middleware momentarily fails open.
 */
final class ApiKeysController
{
    use ResolvesAdminContext;

    public function __construct(
        private readonly McpHostBridgeIdentityContract $bridge,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $blocked = $this->featureGate('api_keys');
        if ($blocked !== null) {
            return $blocked;
        }

        return $this->withHostBridge(function () use ($request): JsonResponse {
            $user = $this->bridge->currentUser();
            if ($user === null) {
                return new JsonResponse([
                    'error' => [
                        'code' => 'unauthenticated',
                        'message' => 'No authenticated actor on this request.',
                    ],
                ], 401);
            }

            $keys = $this->bridge->listApiKeys($user->id);

            return new JsonResponse([
                'data' => array_map(
                    static fn(HostApiKey $k): array => $k->toArray(),
                    $keys,
                ),
                'meta' => [
                    'count' => count($keys),
                    'user_id' => $user->id,
                    'tenant_id' => $this->resolveTenantId($request),
                ],
            ]);
        });
    }

    public function store(CreateApiKeyRequest $request): JsonResponse
    {
        $blocked = $this->featureGate('api_keys');
        if ($blocked !== null) {
            return $blocked;
        }

        return $this->withHostBridge(function () use ($request): JsonResponse {
            $user = $this->bridge->currentUser();
            if ($user === null) {
                return new JsonResponse([
                    'error' => [
                        'code' => 'unauthenticated',
                        'message' => 'No authenticated actor on this request.',
                    ],
                ], 401);
            }

            $attrs = $request->payload() + ['user_id' => $user->id];

            $key = $this->bridge->createApiKey($attrs);

            // 201 with the plaintext surfaced exactly once. The SPA
            // displays it + warns the operator it will not be shown
            // again.
            return new JsonResponse(
                ['data' => $key->toCreateArray()],
                201,
            );
        });
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $blocked = $this->featureGate('api_keys');
        if ($blocked !== null) {
            return $blocked;
        }

        return $this->withHostBridge(function () use ($id): JsonResponse {
            // R30 — require an authenticated actor before reaching the
            // host's revoke hook. Without this, a host whose middleware
            // momentarily fails open (or runs the package outside the
            // intended auth stack) would have a key revoked by
            // anonymous callers. Mirrors the index/store guards above.
            $user = $this->bridge->currentUser();
            if ($user === null) {
                return new JsonResponse([
                    'error' => [
                        'code' => 'unauthenticated',
                        'message' => 'No authenticated actor on this request.',
                    ],
                ], 401);
            }

            $revoked = $this->bridge->revokeApiKey($id);
            if (! $revoked) {
                // R14: explicit 404 — never 200 with `success=false`.
                return new JsonResponse([
                    'error' => [
                        'code' => 'not_found',
                        'message' => "API key [{$id}] not found or already revoked.",
                    ],
                ], 404);
            }

            return new JsonResponse([
                'data' => ['id' => $id, 'revoked' => true],
            ]);
        });
    }
}
