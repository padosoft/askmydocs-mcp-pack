<?php

namespace Padosoft\AskMyDocsMcpPack\Defaults;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerExposureContract;

/**
 * Sentinel exposure that publishes NOTHING. Lets the package's
 * server-side machinery boot in test / scaffold environments
 * without forcing the host to wire a real exposure straight away.
 *
 * Production hosts override this binding in their AppServiceProvider.
 */
final class NullMcpServerExposure implements McpServerExposureContract
{
    public function serverInfo(): array
    {
        return [
            'name' => 'padosoft/askmydocs-mcp-pack',
            'version' => '1.2.0',
        ];
    }

    public function capabilities(): array
    {
        return ['tools' => new \stdClass()];
    }

    public function tools(?string $tenantId): array
    {
        return [];
    }

    public function resources(?string $tenantId): array
    {
        return [];
    }

    public function prompts(?string $tenantId): array
    {
        return [];
    }
}
