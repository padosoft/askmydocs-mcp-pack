<?php

namespace Padosoft\AskMyDocsMcpPack\Fluent\Definitions;

use Padosoft\AskMyDocsMcpPack\Contracts\V2\DefinitionContract;

final readonly class AppDefinition implements DefinitionContract
{
    /**
     * @param  array<string,list<string>>  $csp
     * @param  array<string,mixed>  $permissions
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public string $name,
        public string $resourceUri,
        public mixed $source,
        public string $sourceType,
        public string $visibility = 'model',
        public array $csp = [],
        public array $permissions = [],
        public array $meta = [],
    ) {}

    public function key(): string
    {
        return $this->name;
    }

    public function kind(): string
    {
        return 'apps';
    }

    public function toArray(): array
    {
        $meta = array_replace_recursive([
            'ui' => [
                'visibility' => $this->visibility,
                'csp' => $this->csp,
                'permissions' => $this->permissions,
            ],
        ], $this->meta);

        return [
            'name' => $this->name,
            'resourceUri' => $this->resourceUri,
            'mimeType' => 'text/html;profile=mcp-app',
            'visibility' => $this->visibility,
            'csp' => $this->csp,
            'permissions' => $this->permissions,
            '_meta' => $meta,
        ];
    }
}
