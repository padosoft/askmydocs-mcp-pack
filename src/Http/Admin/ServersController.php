<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;

/**
 * v1.4.0 — admin REST surface for MCP server management.
 *
 * Read-only resource view + handshake trigger over the host-supplied
 * {@see McpServerRegistryContract}. Writes (create / update / delete)
 * are deferred to v1.5.0 once a writable registry contract is added.
 *
 * Auth is intentionally NOT enforced here — the package's admin route
 * group wraps this controller with whatever middleware stack the host
 * declares in `config('mcp-pack.admin.middleware')`. Hosts wire
 * Sanctum / RBAC / role gates there. The controller only resolves
 * the active tenant from `$request->attributes->get('mcp_pack.tenant_id')`
 * (set by the host's auth middleware) or `data_get($user, 'tenant_id')`
 * — never from client-set headers (R30).
 */
final class ServersController
{
    public function __construct(
        private readonly McpServerRegistryContract $registry,
        private readonly McpHandshakeService $handshake,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $servers = $this->registry->forTenant($tenantId);

        return new JsonResponse([
            'data' => array_map(
                fn(McpServerContract $s): array => $this->resourceShape($s),
                $servers,
            ),
            'meta' => [
                'tenant_id' => $tenantId,
                'count' => count($servers),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $server = $this->findForActiveTenant($request, $id);
        if ($server === null) {
            return $this->notFound("Server [{$id}] not found.");
        }

        return new JsonResponse(['data' => $this->resourceShape($server)]);
    }

    public function handshake(Request $request, string $id): JsonResponse
    {
        $server = $this->findForActiveTenant($request, $id);
        if ($server === null) {
            return $this->notFound("Server [{$id}] not found.");
        }

        $force = $request->boolean('force', false);

        // Distinguish "cache hit" (peek returned non-null + force=false)
        // from "cache miss / re-fetch" by probing the cache BEFORE the
        // refresh call. Previously `cached` was derived from `$force`
        // alone, which lied about first-time non-cached handshakes.
        $cacheHit = ! $force && $this->handshake->peek($server) !== null;

        try {
            $payload = $this->handshake->refresh($server, force: $force);
        } catch (McpTransportException $e) {
            return new JsonResponse([
                'error' => [
                    'code' => 'handshake_failed',
                    'message' => $e->getMessage(),
                ],
            ], 502);
        }

        return new JsonResponse([
            'data' => [
                'server_id' => $server->id(),
                'capabilities' => $payload['capabilities'],
                'tools' => $payload['tools'],
                'cached' => $cacheHit,
            ],
        ]);
    }

    public function tools(Request $request, string $id): JsonResponse
    {
        $server = $this->findForActiveTenant($request, $id);
        if ($server === null) {
            return $this->notFound("Server [{$id}] not found.");
        }

        try {
            $payload = $this->handshake->refresh($server, force: false);
        } catch (McpTransportException $e) {
            return new JsonResponse([
                'error' => [
                    'code' => 'handshake_failed',
                    'message' => $e->getMessage(),
                ],
            ], 502);
        }

        $tools = $payload['tools'];
        $allowed = $server->allowedTools();
        if ($allowed !== []) {
            $tools = array_values(array_filter(
                $tools,
                static fn(array $tool): bool => in_array((string) ($tool['name'] ?? ''), $allowed, true),
            ));
        }

        return new JsonResponse([
            'data' => $tools,
            'meta' => [
                'server_id' => $server->id(),
                'count' => count($tools),
                'filtered' => $allowed !== [],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function resourceShape(McpServerContract $server): array
    {
        return [
            'id' => $server->id(),
            'name' => $server->name(),
            'transport' => $server->transport(),
            'tenant_id' => $server->tenantId(),
            'allowed_tools' => $server->allowedTools(),
            'enabled' => $server->isEnabled(),
        ];
    }

    private function resolveTenantId(Request $request): ?string
    {
        // R30: trusted middleware attribute only, never a client header.
        $trustedAttribute = $request->attributes->get('mcp_pack.tenant_id');
        if (is_string($trustedAttribute) && $trustedAttribute !== '') {
            return $trustedAttribute;
        }

        $user = $request->user();
        if ($user === null) {
            return null;
        }
        $tenant = data_get($user, 'tenant_id');
        return is_string($tenant) && $tenant !== '' ? $tenant : null;
    }

    /**
     * Resolve a server by id ONLY within the active tenant's visible
     * catalog. `McpServerContract::id()` is documented as scoped per
     * tenant, so a bare `$registry->find($id)` can return another
     * tenant's entry when two tenants reuse the same id — followed
     * by a tenant-boundary 404 that masks the host's own matching
     * server. Selecting from `forTenant($tenantId)` makes the lookup
     * structurally tenant-correct.
     */
    private function findForActiveTenant(Request $request, string $id): ?McpServerContract
    {
        $tenantId = $this->resolveTenantId($request);
        foreach ($this->registry->forTenant($tenantId) as $server) {
            if ($server->id() === $id) {
                return $server;
            }
        }
        return null;
    }

    private function notFound(string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => ['code' => 'not_found', 'message' => $message],
        ], 404);
    }
}
