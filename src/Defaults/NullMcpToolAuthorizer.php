<?php

namespace Padosoft\AskMyDocsMcpPack\Defaults;

use Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;

/**
 * Default authorizer that allows everything. Production hosts MUST
 * replace it via container binding — the orchestrator's RBAC gate is
 * a no-op until the host wires its own implementation.
 */
final class NullMcpToolAuthorizer implements McpToolAuthorizerContract
{
    public function authorize(mixed $actor, ?string $tenantId, McpToolContract $tool): bool
    {
        return true;
    }
}
