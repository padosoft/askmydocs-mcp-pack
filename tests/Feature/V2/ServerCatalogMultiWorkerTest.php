<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\V2;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Padosoft\AskMyDocsMcpPack\Catalog\ServerCatalog;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\SubscriptionBrokerContract;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ToolDefinition;
use Padosoft\AskMyDocsMcpPack\Fluent\McpManager;
use Padosoft\AskMyDocsMcpPack\Fluent\Tool;
use Padosoft\AskMyDocsMcpPack\Protocol\CacheScope;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

final class ServerCatalogMultiWorkerTest extends TestCase
{
    public function test_workers_on_different_catalog_revisions_do_not_overwrite_each_others_cached_snapshot(): void
    {
        $manager = $this->app->make(McpManager::class);
        $manager->server('docs')->cache(60_000, CacheScope::Public)->tool(Tool::make('alpha')->handle(fn () => 'a'))->register();
        $definition = $manager->require('docs')->server;
        $store = new class extends ArrayStore
        {
            public int $puts = 0;

            public function put($key, $value, $seconds)
            {
                $this->puts++;

                return parent::put($key, $value, $seconds);
            }
        };
        $shared = new Repository($store);
        $broker = $this->app->make(SubscriptionBrokerContract::class);

        // Two stateless workers sharing one cache store. Worker A applied a dynamic
        // upsert (revision 2); worker B did not (revision 1).
        $workerA = new ServerCatalog($definition, $shared, $broker);
        $workerB = new ServerCatalog($definition, $shared, $broker);
        $workerA->forTenant('acme')->upsert(new ToolDefinition('beta', null, ['type' => 'object'], null, fn () => 'b'));

        $snapshotA = $workerA->forTenant('acme')->snapshot();
        $this->assertSame(2, $snapshotA['revision']);
        $this->assertSame(['alpha', 'beta'], array_column($snapshotA['definitions']['tools'], 'name'));

        $snapshotB = $workerB->forTenant('acme')->snapshot();
        $this->assertSame(1, $snapshotB['revision']);
        $this->assertSame(['alpha'], array_column($snapshotB['definitions']['tools'], 'name'), 'worker B must not be served worker A\'s snapshot');

        // Worker A's cached revision-2 snapshot survived worker B's write: A is served
        // from cache again (no recompute / re-put), and vice versa.
        $puts = $store->puts;
        $this->assertSame($snapshotA, $workerA->forTenant('acme')->snapshot());
        $this->assertSame($snapshotB, $workerB->forTenant('acme')->snapshot());
        $this->assertSame($puts, $store->puts, 'both workers must hit their own cached snapshot');
    }
}
