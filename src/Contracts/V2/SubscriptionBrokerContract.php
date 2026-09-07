<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts\V2;

interface SubscriptionBrokerContract
{
    /** @param array<string,mixed> $payload */
    public function publish(?string $tenantId, string $topic, array $payload, ?string $principalId = null): void;

    /** @return list<array{id:string,topic:string,payload:array<string,mixed>,createdAt:string}> */
    public function listen(?string $tenantId, ?string $after = null, int $limit = 100, ?string $principalId = null): array;
}
