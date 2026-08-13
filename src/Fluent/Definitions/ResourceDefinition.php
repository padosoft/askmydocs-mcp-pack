<?php

namespace Padosoft\AskMyDocsMcpPack\Fluent\Definitions;

use Padosoft\AskMyDocsMcpPack\Contracts\V2\DefinitionContract;

final readonly class ResourceDefinition implements DefinitionContract
{
    /** @param array<string,mixed> $annotations @param array<string,mixed> $meta */
    public function __construct(
        public string $uri,
        public string $name,
        public ?string $description,
        public string $mimeType,
        public mixed $handler,
        public array $annotations = [],
        public array $meta = [],
    ) {}

    public function key(): string
    {
        return $this->uri;
    }

    public function kind(): string
    {
        return 'resources';
    }

    public function toArray(): array
    {
        $definition = ['uri' => $this->uri, 'name' => $this->name, 'mimeType' => $this->mimeType];
        if ($this->description !== null) {
            $definition['description'] = $this->description;
        }
        if ($this->annotations !== []) {
            $definition['annotations'] = $this->annotations;
        }
        if ($this->meta !== []) {
            $definition['_meta'] = $this->meta;
        }

        return $definition;
    }
}
