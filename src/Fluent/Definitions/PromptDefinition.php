<?php

namespace Padosoft\AskMyDocsMcpPack\Fluent\Definitions;

use Padosoft\AskMyDocsMcpPack\Contracts\V2\DefinitionContract;

final readonly class PromptDefinition implements DefinitionContract
{
    /** @param list<array<string,mixed>> $arguments @param array<string,mixed> $meta */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $arguments,
        public mixed $handler,
        public array $meta = [],
    ) {}

    public function key(): string
    {
        return $this->name;
    }

    public function kind(): string
    {
        return 'prompts';
    }

    public function toArray(): array
    {
        $definition = ['name' => $this->name, 'arguments' => $this->arguments];
        if ($this->description !== null) {
            $definition['description'] = $this->description;
        }
        if ($this->meta !== []) {
            $definition['_meta'] = $this->meta;
        }

        return $definition;
    }
}
