<?php

namespace Padosoft\AskMyDocsMcpPack\Http\V2\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\OAuthAccessTokenValidatorContract;
use Symfony\Component\HttpFoundation\Response;

final readonly class ValidateOAuthResourceRequest
{
    public function __construct(private OAuthAccessTokenValidatorContract $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        try {
            if (! is_string($token) || $token === '') {
                throw new \RuntimeException('Missing bearer access token.');
            }
            $claims = $this->tokens->validate($token);
            $this->validateClaims($claims);
            $request->attributes->set('mcp_pack.principal_id', (string) ($claims['sub'] ?? ''));
            if (is_scalar($claims['tenant_id'] ?? null)) {
                $request->attributes->set('mcp_pack.tenant_id', (string) $claims['tenant_id']);
            }
            $request->attributes->set('mcp_pack.scopes', $this->scopes($claims));
        } catch (\Throwable) {
            $metadata = rtrim($request->root(), '/').'/.well-known/oauth-protected-resource';

            return new JsonResponse(['error' => 'invalid_token'], 401, [
                'WWW-Authenticate' => 'Bearer resource_metadata="'.$metadata.'", error="invalid_token"',
                'Cache-Control' => 'no-store',
            ]);
        }

        return $next($request);
    }

    /** @param array<string,mixed> $claims */
    private function validateClaims(array $claims): void
    {
        $issuers = array_values(array_filter((array) config('mcp-pack.oauth.authorization_servers', []), 'is_string'));
        if ($issuers !== [] && (! is_string($claims['iss'] ?? null) || ! in_array($claims['iss'], $issuers, true))) {
            throw new \RuntimeException('Token issuer is not allowed.');
        }
        $resource = (string) config('mcp-pack.oauth.resource', '');
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        if ($resource !== '' && ! in_array($resource, $audiences, true)) {
            throw new \RuntimeException('Token audience does not include the MCP resource.');
        }
        $required = array_values(array_filter((array) config('mcp-pack.oauth.required_scopes', []), 'is_string'));
        if (array_diff($required, $this->scopes($claims)) !== []) {
            throw new \RuntimeException('Token lacks required MCP scopes.');
        }
        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new \RuntimeException('Token subject is required.');
        }
    }

    /** @param array<string,mixed> $claims @return list<string> */
    private function scopes(array $claims): array
    {
        $scope = $claims['scope'] ?? $claims['scp'] ?? [];
        if (is_string($scope)) {
            $scope = preg_split('/\s+/', trim($scope)) ?: [];
        }

        return is_array($scope) ? array_values(array_filter($scope, 'is_string')) : [];
    }
}
