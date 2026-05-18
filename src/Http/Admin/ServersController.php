<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerMutableRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpServerNotFoundException;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpToolNotAuthorizedException;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\ResolvesAdminContext;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Requests\InvokeToolRequest;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Requests\StoreServerRequest;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Requests\UpdateServerRequest;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;
use Padosoft\AskMyDocsMcpPack\Services\ToolInvoker;
use Padosoft\AskMyDocsMcpPack\Support\McpServerPage;
use Padosoft\AskMyDocsMcpPack\Support\ToolCallResult;

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
        private readonly ToolInvoker $invoker,
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

            // Iter-1 fix: Location header builds via the named route
            // so a host that configured `mcp-pack.admin.prefix` to a
            // non-default value gets a correct URL. `route()` returns
            // absolute by default, matching the Laravel convention.
            $location = route('mcp-pack.admin.servers.show', ['id' => $server->id()]);

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

        // Iter-1 fix: use the new tenant-scoped lookup that
        //  (a) walks `forTenant()` first so id reuse across tenants
        //      cannot return another tenant's row,
        //  (b) includes disabled servers so operators can flip
        //      `enabled=false` → `true` without hitting a stale 404.
        $tenantId = $this->resolveTenantId($request);
        $existing = $this->mutableRegistry->findForActiveTenant($tenantId, $id, includeDisabled: true);
        if ($existing === null) {
            return $this->notFound("Server [{$id}] not found.");
        }

        // R30 belt-and-braces: a host registry that ignored `tenantId`
        // in `findForActiveTenant` would still surface a foreign row.
        // The check below catches it before delegating to the host's
        // mutable registry.
        if ($existing->tenantId() !== null && $existing->tenantId() !== $tenantId) {
            return $this->forbidden(
                "Server [{$id}] belongs to a different tenant. Cross-tenant updates are forbidden.",
            );
        }

        return $this->withHostBridge(function () use ($request, $id, $tenantId): JsonResponse {
            try {
                $server = $this->mutableRegistry->update($id, $request->payload());
            } catch (McpServerNotFoundException $e) {
                // Race between the pre-check and the mutable write
                // (concurrent delete) — surface as the documented 404
                // envelope, never a 500.
                return $this->notFound($e->getMessage());
            }
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

        // Iter-1 fix: tenant-scoped lookup (same as `update`) so id
        // reuse + disabled rows behave correctly.
        $tenantId = $this->resolveTenantId($request);
        $existing = $this->mutableRegistry->findForActiveTenant($tenantId, $id, includeDisabled: true);
        if ($existing === null) {
            return $this->notFound("Server [{$id}] not found.");
        }

        if ($existing->tenantId() !== null && $existing->tenantId() !== $tenantId) {
            return $this->forbidden(
                "Server [{$id}] belongs to a different tenant. Cross-tenant deletes are forbidden.",
            );
        }

        return $this->withHostBridge(function () use ($id): JsonResponse {
            try {
                $deleted = DB::transaction(fn(): bool => $this->mutableRegistry->delete($id));
            } catch (McpServerNotFoundException $e) {
                return $this->notFound($e->getMessage());
            }
            if (! $deleted) {
                return $this->notFound("Server [{$id}] not found.");
            }
            return new JsonResponse(null, 204);
        });
    }

    /**
     * v1.5.0 W1.C — `POST /servers/{id}/tools/{name}/invoke`.
     *
     * Three orthogonal concerns folded into one endpoint:
     *
     *  1. R30 — the server must be visible to the active tenant via
     *     `findForActiveTenant(...)`; cross-tenant invokes 404.
     *  2. Destructive-tool guard — tools advertising `destructive: true`
     *     in their handshake metadata require `confirm: true` in the
     *     request body, or the controller answers 422
     *     `confirmation_required`. Read-only tools ignore the field.
     *     The destructive flag is HOST-DECLARED via
     *     {@see McpHandshakeService::refresh()} metadata, not inferred
     *     from the name (the conservative name-heuristic in
     *     {@see ToolsController} is a UI hint; here we need the
     *     declared metadata so an operator's explicit `destructive=false`
     *     override is honoured).
     *  3. Tool dispatch + error mapping — {@see McpToolNotAuthorizedException}
     *     → 403 `not_authorized`; {@see McpTransportException} → 502
     *     `transport_error`. Any other failure surfaces via the audit
     *     row's `status` + `error_excerpt` AND a 502 envelope
     *     mirroring the underlying error (R14: no 200-on-failure).
     */
    public function invoke(InvokeToolRequest $request, string $id, string $toolName): JsonResponse
    {
        $blocked = $this->featureGate('tool_invoke');
        if ($blocked !== null) {
            return $blocked;
        }

        $server = $this->findForActiveTenant($request, $id);
        if ($server === null) {
            return $this->notFound("Server [{$id}] not found.");
        }

        $payload = $request->payload();

        // Destructive-tool guard. The handshake's tool list carries
        // per-tool metadata; a tool advertising `destructive: true`
        // requires explicit `confirm: true` from the operator.
        try {
            $isDestructive = $this->isDestructive($server, $toolName);
        } catch (McpTransportException $e) {
            return new JsonResponse([
                'error' => [
                    'code' => 'transport_error',
                    'message' => $e->getMessage(),
                ],
            ], 502);
        }

        if ($isDestructive && ! $payload['confirm']) {
            return new JsonResponse([
                'error' => [
                    'code' => 'confirmation_required',
                    'message' => "Tool [{$toolName}] is destructive; resend with `confirm: true` to invoke.",
                ],
            ], 422);
        }

        $tenantId = $this->resolveTenantId($request);
        $context = [
            'tenant_id' => $tenantId,
            'actor' => $this->resolveActor($request),
        ];

        $start = microtime(true);
        try {
            $result = $this->invoker->invoke($server, $toolName, $payload['arguments'], $context);
        } catch (McpToolNotAuthorizedException $e) {
            return new JsonResponse([
                'error' => ['code' => 'not_authorized', 'message' => $e->getMessage()],
            ], 403);
        } catch (McpTransportException $e) {
            return new JsonResponse([
                'error' => ['code' => 'transport_error', 'message' => $e->getMessage()],
            ], 502);
        }

        // R14: ToolInvoker captures the error inside ToolCallResult
        // without re-throwing. We must NOT answer 200 on a failed
        // call — map the error class onto an HTTP status the SPA can
        // distinguish from a 200 success.
        if ($result->isError()) {
            return new JsonResponse([
                'error' => [
                    'code' => 'transport_error',
                    'message' => $result->error,
                ],
                'data' => [
                    'tool_call_id' => $result->toolCallId,
                    'latency_ms' => (int) round($result->latencyMs),
                ],
            ], 502);
        }

        return new JsonResponse([
            'data' => [
                'tool_call_id' => $result->toolCallId,
                'result' => $result->result,
                'latency_ms' => (int) round($result->latencyMs),
            ],
        ]);
    }

    /**
     * Look the tool up in the handshake-cached catalog and return
     * whether the upstream MCP server flagged it `destructive: true`.
     * `false` is the safe default — a tool the catalog has not heard
     * of is treated as read-only (the upstream `tools/call` will
     * reject it anyway, with a clearer error than a spurious
     * confirmation prompt).
     *
     * @throws McpTransportException when handshake refresh fails
     */
    private function isDestructive(McpServerContract $server, string $toolName): bool
    {
        $payload = $this->handshake->refresh($server, force: false);
        /** @var array<int,array<string,mixed>> $tools */
        $tools = $payload['tools'] ?? [];
        foreach ($tools as $tool) {
            if ((string) ($tool['name'] ?? '') !== $toolName) {
                continue;
            }
            return filter_var(
                $tool['destructive'] ?? false,
                FILTER_VALIDATE_BOOLEAN,
            );
        }
        return false;
    }

    /**
     * Best-effort actor extraction — the host's auth middleware sets
     * `mcp_pack.actor` on the request attributes alongside
     * `mcp_pack.tenant_id`. If absent, fall back to the authenticated
     * user's id / email. Returns `null` for anonymous requests.
     */
    private function resolveActor(Request $request): ?string
    {
        $trusted = $request->attributes->get('mcp_pack.actor');
        if (is_string($trusted) && $trusted !== '') {
            return $trusted;
        }
        $user = $request->user();
        if ($user === null) {
            return null;
        }
        $email = data_get($user, 'email');
        if (is_string($email) && $email !== '') {
            return $email;
        }
        $userId = data_get($user, 'id');
        return $userId !== null ? (string) $userId : null;
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
