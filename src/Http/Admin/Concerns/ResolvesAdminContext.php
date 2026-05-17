<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException;

/**
 * v1.5.0 — shared helpers for the W1.A identity controllers
 * (`MeController`, `TenantsController`, `ApiKeysController`).
 *
 * Three concerns are bundled because all three controllers need
 * exactly them, and lifting them into a base class would have
 * dragged the controllers into a single-purpose hierarchy when they
 * are otherwise independent:
 *
 *  1. resolve the active tenant from the trusted middleware attribute
 *     (R30 — never from a client header);
 *  2. gate the controller on a feature flag from
 *     `mcp-pack.admin.features.*`, returning HTTP 403 when disabled;
 *  3. catch {@see HostFeatureNotImplementedException} from the bridge
 *     and translate it into HTTP 501 with a stable error envelope so
 *     the SPA can render a "host did not wire this surface yet" state.
 */
trait ResolvesAdminContext
{
    /**
     * Resolve the active tenant from the trusted attribute or the
     * authenticated user. R30: never from a client header.
     */
    protected function resolveTenantId(Request $request): ?string
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

    /**
     * Return a 403 JsonResponse when the per-feature flag is disabled.
     * Returns `null` when the feature is enabled so the controller
     * can keep going.
     *
     * The flag is required to default to `true` (the package's
     * `config/mcp-pack.php` ships every feature defaulting to true
     * when the parent `admin.enabled` is true); a host that wants
     * to hide a section sets the flag to `false` explicitly.
     */
    protected function featureGate(string $featureKey): ?JsonResponse
    {
        $enabled = (bool) config("mcp-pack.admin.features.{$featureKey}", true);
        if ($enabled) {
            return null;
        }

        return new JsonResponse([
            'error' => [
                'code' => 'feature_disabled',
                'message' => "Admin feature [{$featureKey}] is disabled on this host.",
            ],
        ], 403);
    }

    /**
     * Run the closure and translate
     * {@see HostFeatureNotImplementedException} into a 501 envelope.
     *
     * @param  \Closure(): JsonResponse  $fn
     */
    protected function withHostBridge(\Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (HostFeatureNotImplementedException $e) {
            return new JsonResponse([
                'error' => [
                    'code' => 'feature_not_implemented',
                    'message' => $e->getMessage(),
                ],
            ], 501);
        }
    }
}
