<?php

namespace Padosoft\AskMyDocsMcpPack\Http\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\AuthenticationResolverContract;

final readonly class OAuthProtectedResourceController
{
    public function __construct(private AuthenticationResolverContract $auth) {}

    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse($this->auth->protectedResourceMetadata($request), 200, ['Cache-Control' => 'public, max-age=3600']);
    }
}
