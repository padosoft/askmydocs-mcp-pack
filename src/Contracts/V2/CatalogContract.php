<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts\V2;

interface CatalogContract
{
    /** The sole entry point for reading or mutating a tenant catalog. */
    public function forTenant(?string $tenantId, ?string $principalId = null): TenantCatalogContract;
}
