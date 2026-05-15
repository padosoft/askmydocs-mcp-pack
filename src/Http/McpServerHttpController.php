<?php

namespace Padosoft\AskMyDocsMcpPack\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\ServerSide\JsonRpcRequestHandler;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

/**
 * v1.2.0 — HTTP front-door to the server-side surface.
 *
 * One endpoint accepts POST JSON-RPC payloads and returns one JSON
 * response per request. Notifications return 204 No Content.
 *
 * Auth is intentionally NOT enforced here — the package's route
 * group wraps this controller with whatever middleware stack the
 * host declares in `config('mcp-pack.server_side.middleware')`.
 * Hosts wire Sanctum / RBAC / per-tenant guards there. The
 * controller only extracts the resolved tenant + actor from the
 * request and passes them down to the handler.
 */
final class McpServerHttpController
{
    public function __construct(
        private readonly JsonRpcRequestHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            return new JsonResponse(
                JsonRpcMessage::errorResponse(0, -32600, 'Invalid request: empty body.')->toArray(),
                400,
            );
        }

        $message = JsonRpcMessage::fromArray($payload);

        $context = [
            'tenant_id' => $this->resolveTenantId($request),
            'actor' => $this->resolveActor($request),
        ];

        $response = $this->handler->handle($message, $context);

        if ($response === null) {
            // Fire-and-forget notification — MCP HTTP profile returns
            // 202 Accepted with no body.
            return new JsonResponse(null, 202);
        }

        // JSON-RPC errors still use HTTP 200; the error payload is in
        // the body per the spec. Only invalid envelope above returns
        // a non-2xx code.
        return new JsonResponse($response->toArray(), 200);
    }

    private function resolveTenantId(Request $request): ?string
    {
        // Two opt-in sources, in priority order:
        //   1. `X-MCP-Tenant` header — set by middleware that
        //      resolves tenant from API token / OAuth scope.
        //   2. `request->user()->tenant_id` — common host pattern
        //      when Sanctum authenticated.
        $headerTenant = $request->header('X-MCP-Tenant');
        if (is_string($headerTenant) && $headerTenant !== '') {
            return $headerTenant;
        }

        $user = $request->user();
        if ($user !== null && property_exists($user, 'tenant_id') && is_string($user->tenant_id)) {
            return $user->tenant_id;
        }

        return null;
    }

    private function resolveActor(Request $request): mixed
    {
        return $request->user();
    }
}
