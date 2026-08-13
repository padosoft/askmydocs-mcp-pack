<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts\V2;

interface TenantCatalogContract
{
    public function upsert(DefinitionContract $definition): self;

    public function remove(string $kind, string $key): bool;

    /** @return list<DefinitionContract> */
    public function all(string $kind): array;

    public function find(string $kind, string $key): ?DefinitionContract;

    /** @return array{revision:int,digest:string,definitions:array<string,list<array<string,mixed>>>} */
    public function snapshot(): array;
}
