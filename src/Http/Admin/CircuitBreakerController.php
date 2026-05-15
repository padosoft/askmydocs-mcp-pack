<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Resilience\CircuitBreaker;

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

        $server = $this->registry->find($serverId);
        if ($server === null) {
            return new JsonResponse([
                'error' => [
                    'code' => 'not_found',
                    'message' => "Server [{$serverId}] not found.",
                ],
            ], 404);
        }
        // R30: enforce tenant boundary mirroring ServersController.
        $tenantId = $this->resolveTenantId($request);
        if ($server->tenantId() !== null && $server->tenantId() !== $tenantId) {
            return new JsonResponse([
                'error' => ['code' => 'not_found', 'message' => "Server [{$serverId}] not found."],
            ], 404);
        }

        $toolFilter = $request->string('tool')->toString();
        $tools = $toolFilter !== '' ? [$toolFilter] : $server->allowedTools();

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
