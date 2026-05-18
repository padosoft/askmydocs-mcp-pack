<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\ResolvesAdminContext;

/**
 * v1.5.0 W1.D — `GET /servers/{id}/prompts` (list) +
 * `GET /servers/{id}/prompts/{name}` (single detail).
 *
 * Same architectural shape as {@see ResourcesController}: delegate to
 * the host bridge, R30 tenant-scope every lookup, 404 on missing.
 */
final class PromptsController
{
    use ResolvesAdminContext;

    public function __construct(
        private readonly McpHostBridgeIdentityContract $identityBridge,
    ) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $blocked = $this->featureGate('prompts');
        if ($blocked !== null) {
            return $blocked;
        }

        $tenantId = $this->resolveTenantId($request);

        return $this->withHostBridge(function () use ($id, $tenantId): JsonResponse {
            $rows = $this->identityBridge->listPrompts($id, $tenantId);

            return new JsonResponse([
                'data' => array_values($rows),
                'meta' => [
                    'tenant_id' => $tenantId,
                    'server_id' => $id,
                    'total' => count($rows),
                ],
            ]);
        });
    }

    public function show(Request $request, string $id, string $name): JsonResponse
    {
        $blocked = $this->featureGate('prompts');
        if ($blocked !== null) {
            return $blocked;
        }

        $tenantId = $this->resolveTenantId($request);

        return $this->withHostBridge(function () use ($id, $name, $tenantId): JsonResponse {
            $row = $this->identityBridge->promptDetail($id, $name, $tenantId);
            if ($row === null) {
                return new JsonResponse([
                    'error' => [
                        'code' => 'not_found',
                        'message' => "Prompt [{$name}] not found on server [{$id}].",
                    ],
                ], 404);
            }

            return new JsonResponse([
                'data' => $row,
                'meta' => [
                    'tenant_id' => $tenantId,
                    'server_id' => $id,
                ],
            ]);
        });
    }
}
