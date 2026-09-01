<?php

namespace Padosoft\AskMyDocsMcpPack\Http\V2\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\AuthenticationResolverContract;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireAdminIdentity
{
    public function __construct(private AuthenticationResolverContract $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $identity = $this->auth->resolve($request);
        if ($identity['principal_id'] === null) {
            return new JsonResponse([
                'error' => 'admin_authentication_required',
                'message' => 'The MCP Pack v2 admin surface requires an authenticated principal.',
            ], 403);
        }

        return $next($request);
    }
}
