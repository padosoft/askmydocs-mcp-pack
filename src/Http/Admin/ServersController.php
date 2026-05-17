<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerMutableRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\ResolvesAdminContext;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Requests\StoreServerRequest;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Requests\UpdateServerRequest;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;
use Padosoft\AskMyDocsMcpPack\Support\McpServerPage;

/**
 * v1.4.0 — admin REST surface for MCP server management.
 *
 * v1.5.0 — extended with `store()` / `update()` / `destroy()` write
 * paths consuming the new {@see McpServerMutableRegistryContract}
 * sub-interface. The read paths (`index` / `show` / `handshake` /
 * `tools`) keep accepting the base read-only contract so a host on
 * a pre-v1.5 registry still gets a working read surface — writes
 * answer HTTP 501 via the trait default.
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
    use ResolvesAdminContext;

    public function __construct(
        private readonly McpServerRegistryContract $registry,
        private readonly McpServerMutableRegistryContract $mutableRegistry,
        private readonly McpHandshakeService $handshake,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        // v1.5.0 — when `?page` / `?per_page` / `?q` / `?status` /
        // `?transport` / `?enabled` are present, route through the
        // mutable registry's `paginate()`. Otherwise fall back to the
        // v1.4 `forTenant()` read path so legacy clients keep their
        // unpaginated shape. The mutable registry's `paginate()`
        // throws `HostFeatureNotImplementedException` when the host
        // has not implemented it — we translate to 501 via
        // `withHostBridge()`.
        if ($this->hasPaginationFilters($request)) {
            return $this->withHostBridge(function () use ($request, $tenantId): JsonResponse {
                /** @var array<string,mixed> $filters */
                $filters = $this->parseFilters($request);
                $page = max(1, (int) $request->query('page', 1));
                $perPage = max(1, min(200, (int) $request->query('per_page', 50)));

                $result = $this->mutableRegistry->paginate(
                    tenantId: $tenantId,
                    filters: $filters,
                    page: $page,
                    perPage: $perPage,
                );

                return new JsonResponse([
                    'data' => array_map(
                        fn(McpServerContract $s): array => $this->resourceShape($s),
                        $result->data,
                    ),
                    'meta' => $result->meta() + ['tenant_id' => $tenantId],
                ]);
            });
        }

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

    /**
     * v1.5.0 — `POST /servers`. The trusted tenant attribute replaces
     * any wire-supplied `tenant_id` (R30); the host receives
     * `attributes['tenant_id']` already-bound to the active tenant.
     */
    public function store(StoreServerRequest $request): JsonResponse
    {
        $blocked = $this->featureGate('servers_write');
        if ($blocked !== null) {
            return $blocked;
        }

        return $this->withHostBridge(function () use ($request): JsonResponse {
            $tenantId = $this->resolveTenantId($request);

            $attrs = $request->payload();
            // R30: the controller binds `tenant_id` from the trusted
            // attribute, NEVER from the wire body. `StoreServerRequest::payload()`
            // strips wire `tenant_id` before we get here, so this line
            // is the SOLE source of truth.
            $attrs['tenant_id'] = $tenantId;

            $server = $this->mutableRegistry->create($attrs);

            $location = url('/api/admin/mcp-pack/servers/' . rawurlencode($server->id()));

            return (new JsonResponse(
                ['data' => $this->resourceShape($server)],
                201,
            ))->header('Location', $location);
        });
    }

    /**
     * v1.5.0 — `PATCH /servers/{id}`. Tenant guard (defence in depth):
     * the controller verifies the EXISTING row belongs to the active
     * tenant before delegating to the host; cross-tenant 403s never
     * reach the host.
     */
    public function update(UpdateServerRequest $request, string $id): JsonResponse
    {
        $blocked = $this->featureGate('servers_write');
        if ($blocked !== null) {
            return $blocked;
        }

        $existing = $this->registry->find($id);
        if ($existing === null) {
            return $this->notFound("Server [{$id}] not found.");
        }

        // R30: reject cross-tenant update attempts BEFORE calling the
        // host bridge. The host's own implementation MAY also enforce
        // this, but the controller layer is the single source of
        // truth for tenant boundary semantics.
        $tenantId = $this->resolveTenantId($request);
        if ($existing->tenantId() !== null && $existing->tenantId() !== $tenantId) {
            return $this->forbidden(
                "Server [{$id}] belongs to a different tenant. Cross-tenant updates are forbidden.",
            );
        }

        return $this->withHostBridge(function () use ($request, $id): JsonResponse {
            $server = $this->mutableRegistry->update($id, $request->payload());
            return new JsonResponse(['data' => $this->resourceShape($server)]);
        });
    }

    /**
     * v1.5.0 — `DELETE /servers/{id}`. Atomic per R21: the delete
     * fires inside a `DB::transaction` closure even though the
     * in-memory registry doesn't need it — sets the contract for
     * real-impl hosts (SQL stores get a single-statement commit /
     * rollback boundary).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $blocked = $this->featureGate('servers_write');
        if ($blocked !== null) {
            return $blocked;
        }

        $existing = $this->registry->find($id);
        if ($existing === null) {
            return $this->notFound("Server [{$id}] not found.");
        }

        $tenantId = $this->resolveTenantId($request);
        if ($existing->tenantId() !== null && $existing->tenantId() !== $tenantId) {
            return $this->forbidden(
                "Server [{$id}] belongs to a different tenant. Cross-tenant deletes are forbidden.",
            );
        }

        return $this->withHostBridge(function () use ($id): JsonResponse {
            $deleted = DB::transaction(fn(): bool => $this->mutableRegistry->delete($id));
            if (! $deleted) {
                return $this->notFound("Server [{$id}] not found.");
            }
            return new JsonResponse(null, 204);
        });
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

    private function hasPaginationFilters(Request $request): bool
    {
        foreach (['page', 'per_page', 'q', 'status', 'transport', 'enabled'] as $key) {
            if ($request->query($key) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function parseFilters(Request $request): array
    {
        return array_filter(
            [
                'q' => $request->query('q'),
                'status' => $request->query('status'),
                'transport' => $request->query('transport'),
                'enabled' => $request->query('enabled'),
            ],
            static fn($v): bool => $v !== null && $v !== '',
        );
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

    private function forbidden(string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => ['code' => 'tenant_forbidden', 'message' => $message],
        ], 403);
    }
}
