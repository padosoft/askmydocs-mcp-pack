<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Resilience\CircuitBreaker;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;

/**
 * v1.4.0 — read-only inspection of the per-(server, tool) circuit
 * breaker state. Uses {@see CircuitBreaker::peekState()} so dashboards
 * NEVER consume the half-open probe slot just by polling.
 *
 * Two modes:
 *
 *   - Targeted: `GET .../circuit-breaker?server={id}&tool={name}`
 *     returns the state for one (server, tool) pair.
 *   - Sweep:    `GET .../circuit-breaker?server={id}` returns the
 *     state for every tool the server's handshake-cached catalog
 *     advertises (or every entry in the configured `allowedTools()`
 *     list, whichever is non-empty).
 */
final class CircuitBreakerController
{
    public function __construct(
        private readonly CircuitBreaker $breaker,
        private readonly McpServerRegistryContract $registry,
        private readonly McpHandshakeService $handshake,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $serverId = $request->string('server')->toString();
        if ($serverId === '') {
            return new JsonResponse([
                'error' => [
                    'code' => 'missing_parameter',
                    'message' => 'Query parameter `server` is required.',
                ],
            ], 400);
        }

        // Resolve from the active tenant's visible catalog so reused
        // ids across tenants can't surface another tenant's entry.
        $server = $this->findForActiveTenant($request, $serverId);
        if ($server === null) {
            return new JsonResponse([
                'error' => ['code' => 'not_found', 'message' => "Server [{$serverId}] not found."],
            ], 404);
        }

        $toolFilter = $request->string('tool')->toString();
        $tools = $toolFilter !== '' ? [$toolFilter] : $this->sweepToolNames($server);

        $data = [];
        foreach ($tools as $toolName) {
            $state = $this->breaker->peekState($serverId, $toolName);
            $data[] = [
                'server_id' => $serverId,
                'tool_name' => $toolName,
                'state' => $state->value,
                'retry_after_seconds' => $this->breaker->retryAfter($serverId, $toolName),
            ];
        }

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'server_id' => $serverId,
                'tool_filter' => $toolFilter !== '' ? $toolFilter : null,
                'count' => count($data),
            ],
        ]);
    }

    /**
     * In sweep mode (no explicit `tool`) the controller must list
     * EVERY tool the breaker tracks for this server. When
     * `allowedTools()` is non-empty that list is authoritative; when
     * it's empty (the "all advertised tools" mode), fall back to the
     * handshake-cached catalog so the sweep isn't silently empty.
     * The handshake is only PEEKed — we never trigger a fresh
     * upstream call from a read-only inspection endpoint.
     *
     * @return array<int,string>
     */
    private function sweepToolNames(McpServerContract $server): array
    {
        $allowed = $server->allowedTools();
        if ($allowed !== []) {
            return $allowed;
        }

        $cached = $this->handshake->peek($server);
        if ($cached === null) {
            return [];
        }
        return array_values(array_filter(
            array_map(
                static fn(array $tool): string => (string) ($tool['name'] ?? ''),
                $cached['tools'] ?? [],
            ),
            static fn(string $name): bool => $name !== '',
        ));
    }

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

    private function resolveTenantId(Request $request): ?string
    {
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
}
