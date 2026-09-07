<?php

namespace Padosoft\AskMyDocsMcpPack\Auth;

use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\AuthenticationResolverContract;

final class HostAuthenticationResolver implements AuthenticationResolverContract
{
    public function resolve(Request $request): array
    {
        $actor = $request->user();
        $trustedTenant = $request->attributes->get('mcp_pack.tenant_id');
        $tenantId = is_string($trustedTenant) && $trustedTenant !== '' ? $trustedTenant : data_get($actor, 'tenant_id');
        $principal = $request->attributes->get('mcp_pack.principal_id') ?? ($actor?->getAuthIdentifier());
        $scopes = $request->attributes->get('mcp_pack.scopes', []);

        return [
            'tenant_id' => is_scalar($tenantId) ? (string) $tenantId : null,
            'actor' => $actor,
            'principal_id' => is_scalar($principal) ? (string) $principal : null,
            'scopes' => is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
        ];
    }

    public function protectedResourceMetadata(Request $request): array
    {
        $configured = config('mcp-pack.oauth.resource');
        $resource = is_string($configured) && $configured !== ''
            ? $configured
            : rtrim($request->root(), '/').'/'.trim((string) config('mcp-pack.server_side.http.prefix', 'mcp'), '/');
        $metadata = ['resource' => $resource];
        $servers = array_values(array_filter((array) config('mcp-pack.oauth.authorization_servers', []), 'is_string'));
        if ($servers !== []) {
            $metadata['authorization_servers'] = $servers;
        }
        $scopes = array_values(array_filter((array) config('mcp-pack.oauth.scopes_supported', []), 'is_string'));
        if ($scopes !== []) {
            $metadata['scopes_supported'] = $scopes;
        }
        $metadata['bearer_methods_supported'] = ['header'];

        return $metadata;
    }
}
