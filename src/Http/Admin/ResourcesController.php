<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\ResolvesAdminContext;

/**
 * v1.5.0 W1.D — `GET /servers/{id}/resources` (tree) +
 * `GET /servers/{id}/resources/{uri}` (single content).
 *
 * Both endpoints delegate to the host bridge's `listResources()` and
 * `resourceContent()` methods. The package never walks the upstream
 * MCP server directly — hosts that want server-side cache + JSON-RPC
 * fallback wire it inside their bridge implementation.
 *
 * R30: every lookup is scoped to the trusted tenant id resolved from
 * the `mcp_pack.tenant_id` middleware attribute. A cross-tenant
 * `{id}` surfaces as an empty list (tree) or 404 (content) — no
 * partial leak.
 *
 * R19 (input-escape-complete): the `{uri}` segment is URL-decoded
 * EXACTLY ONCE by the controller (via `rawurldecode`); the decoded
 * string is then passed verbatim to the bridge. The host owns SQL /
 * filesystem escaping for the decoded URI.
 *
 * R14 (surface-failures-loudly): missing resource → 404
 * `not_found`, never a 200 with empty body.
 */
final class ResourcesController
{
    use ResolvesAdminContext;

    public function __construct(
        private readonly McpHostBridgeIdentityContract $identityBridge,
    ) {}

    /**
     * `GET /servers/{id}/resources` — list every resource entry in
     * the server's tree (mix of `type=dir` + `type=file`).
     */
    public function index(Request $request, string $id): JsonResponse
    {
        $blocked = $this->featureGate('resources');
        if ($blocked !== null) {
            return $blocked;
        }

        $tenantId = $this->resolveTenantId($request);

        return $this->withHostBridge(function () use ($id, $tenantId): JsonResponse {
            $rows = $this->identityBridge->listResources($id, $tenantId);

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

    /**
     * `GET /servers/{id}/resources/{uri}` — single resource content.
     * Returns 404 when missing or cross-tenant.
     */
    public function show(Request $request, string $id, string $uri): JsonResponse
    {
        $blocked = $this->featureGate('resources');
        if ($blocked !== null) {
            return $blocked;
        }

        // R19: Symfony's `UrlMatcher::matchRequest()` `rawurldecode`s
        // the path BEFORE applying the route regex (verified in
        // `vendor/symfony/routing/Matcher/UrlMatcher.php`). Calling
        // `rawurldecode()` here AGAIN would double-decode: a literal
        // `%2F` in the resource URI sent as `%252F` on the wire would
        // arrive as `%2F` (already decoded once by Symfony), then
        // become `/` (decoded twice) — corrupting the URI before the
        // bridge lookup. Decode-exactly-once is the contract, so we
        // pass the parameter through unchanged. The host's bridge
        // owns any further escaping inside its persistence layer.
        $tenantId = $this->resolveTenantId($request);

        return $this->withHostBridge(function () use ($id, $uri, $tenantId): JsonResponse {
            $row = $this->identityBridge->resourceContent($id, $uri, $tenantId);
            if ($row === null) {
                return new JsonResponse([
                    'error' => [
                        'code' => 'not_found',
                        'message' => "Resource [{$uri}] not found on server [{$id}].",
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
