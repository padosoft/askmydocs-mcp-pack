<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts\V2;

use Illuminate\Http\Request;

interface AuthenticationResolverContract
{
    /** @return array{tenant_id:?string,actor:mixed,principal_id:?string,scopes:list<string>} */
    public function resolve(Request $request): array;

    /** @return array<string,mixed> RFC 9728 Protected Resource Metadata. */
    public function protectedResourceMetadata(Request $request): array;
}
