<?php

namespace Padosoft\AskMyDocsMcpPack\Fluent\Definitions;

use Padosoft\AskMyDocsMcpPack\Protocol\CacheScope;

final readonly class ServerDefinition
{
    /**
     * @param  list<ToolDefinition>  $tools
     * @param  list<ResourceDefinition>  $resources
     * @param  list<ResourceTemplateDefinition>  $resourceTemplates
     * @param  list<PromptDefinition>  $prompts
     * @param  list<AppDefinition>  $apps
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public ?string $instructions,
        public int $ttlMs,
        public CacheScope $cacheScope,
        public array $tools,
        public array $resources,
        public array $resourceTemplates,
        public array $prompts,
        public array $apps,
    ) {}

    /** @return array{name:string,version:string,instructions?:string} */
    public function serverInfo(): array
    {
        $info = ['name' => $this->name, 'version' => $this->version];
        if ($this->instructions !== null) {
            $info['instructions'] = $this->instructions;
        }

        return $info;
    }
}
