<?php

namespace Padosoft\AskMyDocsMcpPack\Fluent\Definitions;

use Padosoft\AskMyDocsMcpPack\Contracts\V2\DefinitionContract;

final readonly class ToolDefinition implements DefinitionContract
{
    /**
     * @param  array<string,mixed>  $inputSchema
     * @param  array<string,mixed>|null  $outputSchema
     * @param  array<string,mixed>  $annotations
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $inputSchema,
        public ?array $outputSchema,
        public mixed $handler,
        public array $annotations = [],
        public array $meta = [],
        public bool $asynchronous = false,
    ) {}

    public function key(): string
    {
        return $this->name;
    }

    public function kind(): string
    {
        return 'tools';
    }

    public function toArray(): array
    {
        $definition = ['name' => $this->name, 'inputSchema' => $this->inputSchema];
        if ($this->description !== null) {
            $definition['description'] = $this->description;
        }
        if ($this->outputSchema !== null) {
            $definition['outputSchema'] = $this->outputSchema;
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
