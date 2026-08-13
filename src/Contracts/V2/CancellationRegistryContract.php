<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts\V2;

interface CancellationRegistryContract
{
    public function cancel(?string $tenantId, string $requestId, ?string $principalId = null, int $ttlSeconds = 300): void;

    public function isCancelled(?string $tenantId, string $requestId, ?string $principalId = null): bool;
}
