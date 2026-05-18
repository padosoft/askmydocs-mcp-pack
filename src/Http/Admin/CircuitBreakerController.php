<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\MintsConfirmTokens;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\ResolvesAdminContext;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Requests\ResetBreakerRequest;
use Padosoft\AskMyDocsMcpPack\Resilience\CircuitBreaker;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;
use Padosoft\AskMyDocsMcpPack\Support\McpAdminConfirmToken;

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
 *
 * v1.5.0 W1.C adds `reset(string $key)` —
 * `POST /circuit-breaker/{key}/reset` where `key` is the URL-encoded
 * `<server_id>:<tool_name>` compound. Two-call confirm-token protocol
 * (R21 atomic single-use); host bridge owns the lock+consume+reset
 * inside a `DB::transaction` closure.
 */
final class CircuitBreakerController
{
    use ResolvesAdminContext;
    use MintsConfirmTokens;

    public function __construct(
        private readonly CircuitBreaker $breaker,
        private readonly McpServerRegistryContract $registry,
        private readonly McpHandshakeService $handshake,
        private readonly McpHostBridgeIdentityContract $identityBridge,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->__invoke($request);
    }

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
     * v1.5.0 W1.C — `POST /circuit-breaker/{key}/reset`.
     *
     * `$key` is the URL-encoded `<server_id>:<tool_name>` compound.
     * Two-call protocol mirroring `AuditController::replay()`:
     *  1. First POST mints + parks a single-use confirm token.
     *  2. Second POST presents the token; controller validates
     *     mint-side, host atomically consumes + resets inside a
     *     `DB::transaction`.
     *
     * Cross-tenant safety: the server must be visible to the active
     * tenant; foreign-tenant ids 404 at mint time so an attacker
     * can't even harvest a token for another tenant's breaker.
     */
    public function reset(ResetBreakerRequest $request, string $key): JsonResponse
    {
        $blocked = $this->featureGate('breaker_reset');
        if ($blocked !== null) {
            return $blocked;
        }

        $decoded = $this->decodeKey($key);
        if ($decoded === null) {
            return new JsonResponse([
                'error' => [
                    'code' => 'invalid_key',
                    'message' => "Breaker key must be `<server_id>:<tool_name>`; got [{$key}].",
                ],
            ], 422);
        }
        [$serverId, $toolName] = $decoded;

        $server = $this->findForActiveTenant($request, $serverId);
        if ($server === null) {
            return new JsonResponse([
                'error' => ['code' => 'not_found', 'message' => "Server [{$serverId}] not found."],
            ], 404);
        }

        $tenantId = $this->resolveTenantId($request);
        $confirmToken = $request->confirmToken();
        $targetId = $serverId . ':' . $toolName;

        if ($confirmToken === null) {
            return $this->mintConfirmToken(
                scope: McpAdminConfirmToken::SCOPE_BREAKER_RESET,
                targetId: $targetId,
                tenantId: $tenantId,
            );
        }

        $invalid = $this->validateConfirmToken(
            scope: McpAdminConfirmToken::SCOPE_BREAKER_RESET,
            targetId: $targetId,
            tenantId: $tenantId,
            token: $confirmToken,
        );
        if ($invalid !== null) {
            return $invalid;
        }

        return $this->withHostBridge(function () use ($serverId, $toolName, $confirmToken, $targetId): JsonResponse {
            $changed = $this->identityBridge->resetBreaker($serverId, $toolName, $confirmToken);
            $this->forgetConfirmToken(
                scope: McpAdminConfirmToken::SCOPE_BREAKER_RESET,
                targetId: $targetId,
                token: $confirmToken,
            );
            return new JsonResponse([
                'data' => [
                    'server_id' => $serverId,
                    'tool_name' => $toolName,
                    'changed' => $changed,
                ],
            ]);
        });
    }

    /**
     * Decode the `<server_id>:<tool_name>` compound. We URL-decode
     * once (Laravel does its own decoding for path segments, but a
     * double-URL-encoded `%3A` is sometimes seen from over-cautious
     * SPAs) and then split on the FIRST colon — server ids may
     * contain `.` `_` `-` but never `:` (constrained by the route
     * regex on the package's register-routes), so the first colon is
     * the unambiguous separator.
     *
     * @return array{0:string,1:string}|null
     */
    private function decodeKey(string $key): ?array
    {
        $decoded = rawurldecode($key);
        $pos = strpos($decoded, ':');
        if ($pos === false || $pos === 0 || $pos === strlen($decoded) - 1) {
            return null;
        }
        $serverId = substr($decoded, 0, $pos);
        $toolName = substr($decoded, $pos + 1);
        if ($serverId === '' || $toolName === '') {
            return null;
        }
        return [$serverId, $toolName];
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
}
