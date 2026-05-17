<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\ResolvesAdminContext;
use Padosoft\AskMyDocsMcpPack\Support\HostTenant;

/**
 * v1.5.0 — `GET /tenants`.
 *
 * Returns the list of tenants the active user can switch to. The
 * host decides the visibility rules. The SPA renders the list as a
 * dropdown in the global header.
 *
 * R30: the controller does not enforce a tenant scope on the host's
 * answer — that is the host's job per `listTenants()` contract.
 * What the controller DOES do is surface the active tenant via
 * `meta.active_tenant_id` so the SPA can highlight it without
 * trusting a client-set value.
 */
final class TenantsController
{
    use ResolvesAdminContext;

    public function __construct(
        private readonly McpHostBridgeContract $bridge,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $blocked = $this->featureGate('tenants');
        if ($blocked !== null) {
            return $blocked;
        }

        return $this->withHostBridge(function () use ($request): JsonResponse {
            $tenants = $this->bridge->listTenants();
            return new JsonResponse([
                'data' => array_map(
                    static fn(HostTenant $t): array => $t->toArray(),
                    $tenants,
                ),
                'meta' => [
                    'active_tenant_id' => $this->resolveTenantId($request),
                    'count' => count($tenants),
                ],
            ]);
        });
    }
}
