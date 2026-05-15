<?php

namespace Padosoft\AskMyDocsMcpPack\Defaults;

use Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;

/**
 * Default authorizer that allows everything. Production hosts MUST
 * replace it via container binding — the service provider logs a
 * warning when the null authorizer is resolved in `production`.
 */
final class NullMcpToolAuthorizer implements McpToolAuthorizerContract
{
    public function authorize(mixed $actor, ?string $tenantId, McpToolContract $tool): bool
    {
        return true;
    }
}
