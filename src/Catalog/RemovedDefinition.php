<?php

namespace Padosoft\AskMyDocsMcpPack\Catalog;

use Padosoft\AskMyDocsMcpPack\Contracts\V2\DefinitionContract;

final readonly class RemovedDefinition implements DefinitionContract
{
    public function __construct(private string $definitionKind, private string $definitionKey) {}

    public function key(): string
    {
        return $this->definitionKey;
    }

    public function kind(): string
    {
        return $this->definitionKind;
    }

    public function toArray(): array
    {
        return ['removed' => true];
    }
}
