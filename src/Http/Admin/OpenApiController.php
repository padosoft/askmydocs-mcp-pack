<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\ResolvesAdminContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * v1.5.0 W1.D — `GET /openapi.json` — serves the canonical OpenAPI
 * 3.1 specification for the v1.5 admin REST surface.
 *
 * The spec is a static file shipped at
 * `resources/openapi/v1.5.json`. The controller reads it once per
 * process (memoised in a static var) and serves it with
 * `Content-Type: application/json`. Subsequent requests within the
 * same worker reuse the cached bytes (O(1) after first hit).
 *
 * R14: a missing / unreadable / malformed spec surfaces as HTTP 500
 * with a stable JSON envelope — never a 200 with empty body.
 */
final class OpenApiController
{
    use ResolvesAdminContext;

    private static ?string $cachedSpec = null;

    public function __invoke(Request $request): Response
    {
        $blocked = $this->featureGate('openapi');
        if ($blocked !== null) {
            return $blocked;
        }

        $spec = $this->loadSpec();
        if ($spec === null) {
            return new JsonResponse([
                'error' => [
                    'code' => 'openapi_spec_unavailable',
                    'message' => 'OpenAPI specification file is missing or unreadable.',
                ],
            ], 500);
        }

        return new Response($spec, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function loadSpec(): ?string
    {
        if (self::$cachedSpec !== null) {
            return self::$cachedSpec;
        }
        $path = __DIR__ . '/../../../resources/openapi/v1.5.json';
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            return null;
        }
        // Validate JSON — a malformed spec is a packaging bug; let
        // the operator see 500 instead of shipping garbage to the
        // SPA's OpenAPI viewer.
        json_decode($contents);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        self::$cachedSpec = $contents;
        return $contents;
    }

    /**
     * Test helper — clears the static cache between tests. Not part
     * of the public API.
     */
    public static function flushCache(): void
    {
        self::$cachedSpec = null;
    }
}
