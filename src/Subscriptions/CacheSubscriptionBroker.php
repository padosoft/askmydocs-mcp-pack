<?php

namespace Padosoft\AskMyDocsMcpPack\Subscriptions;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\SubscriptionBrokerContract;

final class CacheSubscriptionBroker implements SubscriptionBrokerContract
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $ttlSeconds = 3600,
        private readonly int $lockSeconds = 5,
        private readonly int $lockWaitSeconds = 3,
    ) {}

    public function publish(?string $tenantId, string $topic, array $payload, ?string $principalId = null): void
    {
        try {
            $key = $this->key($tenantId, $principalId);
            $event = ['id' => (string) Str::uuid(), 'topic' => $topic, 'payload' => $payload, 'createdAt' => now()->toAtomString()];
            $append = function () use ($key, $event): void {
                $events = $this->cache->get($key, []);
                if (! is_array($events)) {
                    $events = [];
                }
                $events[] = $event;
                $this->cache->put($key, array_slice($events, -1000), $this->ttlSeconds);
            };

            // The append is a read-modify-write on a shared list: two workers publishing
            // to the same tenant/principal at once would otherwise overwrite each other
            // and silently drop progress / list_changed notifications. Serialise it
            // through the store's atomic lock whenever the driver provides one.
            $store = $this->cache->getStore();
            if ($store instanceof LockProvider) {
                try {
                    $store->lock($key.':lock', $this->lockSeconds)->block($this->lockWaitSeconds, $append);

                    return;
                } catch (LockTimeoutException) {
                    // Fall through: a best-effort unlocked append beats dropping the
                    // event, and notifications must never block the user path.
                }
            }
            $append();
        } catch (\Throwable) {
            // Notifications are advisory and never block the user path.
        }
    }

    public function listen(?string $tenantId, ?string $after = null, int $limit = 100, ?string $principalId = null): array
    {
        $events = $this->cache->get($this->key($tenantId, $principalId), []);
        if (! is_array($events)) {
            return [];
        }
        if ($after !== null) {
            $seen = false;
            $events = array_values(array_filter($events, static function (array $event) use ($after, &$seen): bool {
                if ($seen) {
                    return true;
                }
                if (($event['id'] ?? null) === $after) {
                    $seen = true;
                }

                return false;
            }));
        }

        return array_slice(array_values(array_filter($events, 'is_array')), 0, max(1, min($limit, 500)));
    }

    private function key(?string $tenantId, ?string $principalId): string
    {
        return 'mcp-pack:v2:subscriptions:'.hash('sha256', ($tenantId ?? '_public')."\0".($principalId ?? '_anonymous'));
    }
}
