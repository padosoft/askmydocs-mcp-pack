<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts\V2;

interface OAuthAccessTokenValidatorContract
{
    /**
     * Verify signature, expiry and token type using the host's IdP integration.
     * Return normalized claims; package middleware additionally enforces issuer,
     * audience and required scopes.
     *
     * @return array<string,mixed>
     */
    public function validate(string $accessToken): array;
}
