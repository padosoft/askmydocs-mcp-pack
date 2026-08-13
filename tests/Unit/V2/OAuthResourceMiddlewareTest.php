<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\V2;

use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Auth\HostAuthenticationResolver;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\OAuthAccessTokenValidatorContract;
use Padosoft\AskMyDocsMcpPack\Http\V2\Middleware\ValidateOAuthResourceRequest;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class OAuthResourceMiddlewareTest extends TestCase
{
    public function test_protected_resource_metadata_has_a_non_empty_route_derived_default(): void
    {
        config()->set('mcp-pack.oauth.resource', null);
        config()->set('mcp-pack.server_side.http.prefix', 'mcp/v2');

        $metadata = (new HostAuthenticationResolver)->protectedResourceMetadata(Request::create('https://api.example/.well-known/oauth-protected-resource'));

        $this->assertSame('https://api.example/mcp/v2', $metadata['resource']);
        $this->assertSame(['header'], $metadata['bearer_methods_supported']);
    }

    public function test_valid_host_verified_claims_are_bound_to_trusted_request_attributes(): void
    {
        config()->set('mcp-pack.oauth.authorization_servers', ['https://idp.example']);
        config()->set('mcp-pack.oauth.resource', 'https://api.example/mcp');
        config()->set('mcp-pack.oauth.required_scopes', ['mcp:read']);
        $middleware = new ValidateOAuthResourceRequest(new class implements OAuthAccessTokenValidatorContract
        {
            public function validate(string $accessToken): array
            {
                return [
                    'iss' => 'https://idp.example', 'aud' => ['https://api.example/mcp'],
                    'sub' => 'alice', 'tenant_id' => 'acme', 'scope' => 'mcp:read mcp:write',
                ];
            }
        });
        $request = Request::create('/mcp', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer valid']);

        $response = $middleware->handle($request, static fn (Request $request): Response => new Response(
            $request->attributes->get('mcp_pack.tenant_id').'|'.$request->attributes->get('mcp_pack.principal_id'),
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('acme|alice', $response->getContent());
        $this->assertSame(['mcp:read', 'mcp:write'], $request->attributes->get('mcp_pack.scopes'));
    }

    public function test_invalid_or_missing_token_returns_rfc_9728_resource_metadata_challenge(): void
    {
        $middleware = new ValidateOAuthResourceRequest(new class implements OAuthAccessTokenValidatorContract
        {
            public function validate(string $accessToken): array
            {
                throw new \RuntimeException('must not be disclosed');
            }
        });
        $request = Request::create('https://api.example/mcp', 'POST');

        $response = $middleware->handle($request, static fn (): Response => new Response('unexpected'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('resource_metadata="https://api.example/.well-known/oauth-protected-resource"', (string) $response->headers->get('WWW-Authenticate'));
        $this->assertStringNotContainsString('must not be disclosed', (string) $response->getContent());
    }
}
