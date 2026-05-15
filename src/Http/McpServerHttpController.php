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
        // SECURITY (R30): tenant id is NEVER read from a client-set
        // request header — an authenticated tenant-A user could
        // otherwise send `X-MCP-Tenant: tenant-b` and pivot into
        // another tenant's catalog. Two trusted sources only:
        //
        //   1. `request->attributes->get('mcp_pack.tenant_id')` —
        //      set ONLY by the host's auth middleware after it has
        //      validated the actor's tenant binding (e.g. via API
        //      token scope, OAuth claim, or Sanctum ability).
        //
        //   2. `$user->tenant_id` accessor — `data_get()` so Eloquent
        //      attribute magic-get + plain object properties + array
        //      shapes all work. `property_exists()` was previously
        //      used here, which is false for Eloquent attributes
        //      exposed only via __get (the typical Laravel user
        //      shape), so the branch was effectively dead.
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

    private function resolveActor(Request $request): mixed
    {
        return $request->user();
    }
}
