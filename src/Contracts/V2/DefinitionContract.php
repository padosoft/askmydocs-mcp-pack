<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts\V2;

interface DefinitionContract
{
    public function key(): string;

    public function kind(): string;

    /** @return array<string,mixed> */
    public function toArray(): array;
}
