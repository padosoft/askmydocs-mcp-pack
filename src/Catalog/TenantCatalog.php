<?php

namespace Padosoft\AskMyDocsMcpPack\Catalog;

use Padosoft\AskMyDocsMcpPack\Contracts\V2\DefinitionContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\TenantCatalogContract;

final class TenantCatalog implements TenantCatalogContract
{
    private readonly string $scope;

    public function __construct(
        private readonly ServerCatalog $catalog,
        private readonly ?string $tenantId,
        private readonly ?string $principalId,
    ) {
        $this->scope = hash('sha256', ($tenantId ?? '_public')."\0".($principalId ?? '_anonymous'));
    }

    public function upsert(DefinitionContract $definition): TenantCatalogContract
    {
        $this->catalog->scopedUpsert($this->scope, $this->tenantId, $this->principalId, $definition);

        return $this;
    }

    public function remove(string $kind, string $key): bool
    {
        return $this->catalog->scopedRemove($this->scope, $this->tenantId, $this->principalId, $kind, $key);
    }

    public function all(string $kind): array
    {
        return array_values(array_filter(
            $this->catalog->scopedAll($this->scope, $kind),
            static fn (DefinitionContract $item): bool => ! $item instanceof RemovedDefinition,
        ));
    }

    public function find(string $kind, string $key): ?DefinitionContract
    {
        foreach ($this->all($kind) as $definition) {
            if ($definition->key() === $key) {
                return $definition;
            }
        }

        return null;
    }

    public function snapshot(): array
    {
        return $this->catalog->scopedSnapshot($this->scope);
    }
}
