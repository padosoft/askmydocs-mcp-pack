<?php

namespace Padosoft\AskMyDocsMcpPack\Auth;

use Padosoft\AskMyDocsMcpPack\Contracts\V2\OAuthAccessTokenValidatorContract;

final class NullOAuthAccessTokenValidator implements OAuthAccessTokenValidatorContract
{
    public function validate(string $accessToken): array
    {
        throw new \RuntimeException('OAuth resource-server mode requires a host binding for OAuthAccessTokenValidatorContract.');
    }
}
