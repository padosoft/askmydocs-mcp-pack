<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Contracts\McpPromptContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpResourceContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerExposureContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;

/**
 * Fully scriptable {@see McpServerExposureContract} for v1.2.0 tests.
 */
final class InMemoryServerExposure implements McpServerExposureContract
{
    /** @param array<int,McpToolContract>     $toolList */
    /** @param array<int,McpResourceContract> $resourceList */
    /** @param array<int,McpPromptContract>   $promptList */
    public function __construct(
        public array $toolList = [],
        public array $resourceList = [],
        public array $promptList = [],
        public array $serverInfoData = ['name' => 'test-server', 'version' => '0.0.1'],
        public array $capabilitiesData = ['tools' => []],
    ) {}

    public function serverInfo(): array { return $this->serverInfoData; }
    public function capabilities(): array { return $this->capabilitiesData; }
    public function tools(?string $tenantId): array { return $this->toolList; }
    public function resources(?string $tenantId): array { return $this->resourceList; }
    public function prompts(?string $tenantId): array { return $this->promptList; }
}
