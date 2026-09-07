<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class InjectMcpIdentityMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('mcp_pack.tenant_id', 'acme');
        $request->attributes->set('mcp_pack.principal_id', 'admin-1');

        return $next($request);
    }
}
