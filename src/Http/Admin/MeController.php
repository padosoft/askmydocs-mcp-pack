<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\ResolvesAdminContext;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Requests\UpdatePreferencesRequest;

/**
 * v1.5.0 — `GET /me` + `POST /me/preferences`.
 *
 * The endpoint surfaces the actor's identity, tenant, and permissions
 * exactly as the host's bridge produced them — the package never
 * mints permissions on its own. The SPA reads `permissions[]` as
 * feature flags to hide / show admin sections.
 *
 * R30 (cross-tenant isolation): the preferences mutation MUST target
 * the active user. The controller refuses to write preferences for a
 * different user — the host bridge is given `currentUser()->id`
 * directly and does not accept an arbitrary id from the wire.
 */
final class MeController
{
    use ResolvesAdminContext;

    public function __construct(
        private readonly McpHostBridgeIdentityContract $bridge,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $blocked = $this->featureGate('me');
        if ($blocked !== null) {
            return $blocked;
        }

        return $this->withHostBridge(function () use ($request): JsonResponse {
            $user = $this->bridge->currentUser();
            if ($user === null) {
                // R14: explicit 401 envelope instead of `200` with
                // `data: null` — callers can branch on the status.
                return new JsonResponse([
                    'error' => [
                        'code' => 'unauthenticated',
                        'message' => 'No authenticated actor on this request.',
                    ],
                ], 401);
            }

            return new JsonResponse([
                'data' => $user->toArray(),
                'meta' => [
                    'tenant_id' => $this->resolveTenantId($request),
                ],
            ]);
        });
    }

    public function updatePreferences(UpdatePreferencesRequest $request): JsonResponse
    {
        $blocked = $this->featureGate('me');
        if ($blocked !== null) {
            return $blocked;
        }

        return $this->withHostBridge(function () use ($request): JsonResponse {
            $user = $this->bridge->currentUser();
            if ($user === null) {
                return new JsonResponse([
                    'error' => [
                        'code' => 'unauthenticated',
                        'message' => 'No authenticated actor on this request.',
                    ],
                ], 401);
            }

            // R30: the user_id is taken from `currentUser()`, never
            // from the request payload. There is no way to write
            // another user's preferences through this endpoint.
            $persisted = $this->bridge->savePreferences(
                userId: $user->id,
                prefs: $request->preferences(),
            );

            return new JsonResponse(['data' => $persisted->toArray()]);
        });
    }
}
