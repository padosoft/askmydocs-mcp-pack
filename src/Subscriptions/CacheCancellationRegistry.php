<?php

namespace Padosoft\AskMyDocsMcpPack\Subscriptions;

use Illuminate\Contracts\Cache\Repository;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\CancellationRegistryContract;

final readonly class CacheCancellationRegistry implements CancellationRegistryContract
{
    public function __construct(private Repository $cache) {}

    public function cancel(?string $tenantId, string $requestId, ?string $principalId = null, int $ttlSeconds = 300): void
    {
        $this->cache->put($this->key($tenantId, $requestId, $principalId), true, $ttlSeconds);
    }

    public function isCancelled(?string $tenantId, string $requestId, ?string $principalId = null): bool
    {
        return $this->cache->get($this->key($tenantId, $requestId, $principalId), false) === true;
    }

    private function key(?string $tenantId, string $requestId, ?string $principalId): string
    {
        return 'mcp-pack:v2:cancel:'.hash('sha256', ($tenantId ?? '_public')."\0".($principalId ?? '_anonymous')."\0".$requestId);
    }
}
