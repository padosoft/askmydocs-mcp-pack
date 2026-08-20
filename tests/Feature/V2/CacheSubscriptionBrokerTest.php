<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\V2;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Padosoft\AskMyDocsMcpPack\Subscriptions\CacheSubscriptionBroker;
use Padosoft\AskMyDocsMcpPack\Tests\Support\NonLockingStore;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

final class CacheSubscriptionBrokerTest extends TestCase
{
    public function test_publish_appends_under_a_cache_lock_when_the_store_supports_locks(): void
    {
        $store = new class extends ArrayStore
        {
            /** @var list<string> */
            public array $lockCalls = [];

            public function lock($name, $seconds = 0, $owner = null): Lock
            {
                $this->lockCalls[] = $name;

                return parent::lock($name, $seconds, $owner);
            }
        };
        $broker = new CacheSubscriptionBroker(new Repository($store));

        $broker->publish('acme', 'notifications/tools/list_changed', ['revision' => 2], 'alice');
        $broker->publish('acme', 'notifications/progress', ['pct' => 50], 'alice');

        $events = $broker->listen('acme', principalId: 'alice');
        $this->assertSame(['notifications/tools/list_changed', 'notifications/progress'], array_column($events, 'topic'));
        $this->assertCount(2, $store->lockCalls, 'every publish serialises its read-modify-write through the store lock');
        $this->assertCount(1, array_unique($store->lockCalls), 'the same tenant/principal list is guarded by the same lock key');
        $this->assertNotSame($store->lockCalls[0], 'mcp-pack:v2:subscriptions:'.hash('sha256', "acme\0alice"), 'lock key differs from the list key');
    }

    public function test_publish_never_blocks_the_user_path_when_the_lock_is_held(): void
    {
        $store = new ArrayStore;
        $broker = new CacheSubscriptionBroker(new Repository($store), lockWaitSeconds: 0);
        $broker->publish('acme', 'notifications/progress', ['pct' => 1], 'alice');
        $listKey = 'mcp-pack:v2:subscriptions:'.hash('sha256', "acme\0alice");

        // Another worker holds the lock: publish waits at most lockWaitSeconds, then
        // falls back to a best-effort append instead of dropping the event or throwing.
        $held = $store->lock($listKey.':lock', 30, 'other-worker');
        $this->assertTrue($held->acquire());
        try {
            $broker->publish('acme', 'notifications/progress', ['pct' => 2], 'alice');
        } finally {
            $held->release();
        }

        $this->assertSame([['pct' => 1], ['pct' => 2]], array_column($broker->listen('acme', principalId: 'alice'), 'payload'));
    }

    public function test_publish_works_without_lock_support(): void
    {
        // Emulate a driver without atomic lock support (no LockProvider).
        $repository = new Repository(new NonLockingStore(new ArrayStore));
        $broker = new CacheSubscriptionBroker($repository);

        $broker->publish(null, 'notifications/resources/list_changed', ['revision' => 3]);

        $this->assertSame(['notifications/resources/list_changed'], array_column($broker->listen(null), 'topic'));
    }
}
