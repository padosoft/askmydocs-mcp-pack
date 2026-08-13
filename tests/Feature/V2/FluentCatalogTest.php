<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\V2;

use Padosoft\AskMyDocsMcpPack\Contracts\V2\SubscriptionBrokerContract;
use Padosoft\AskMyDocsMcpPack\Fluent\App;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ToolDefinition;
use Padosoft\AskMyDocsMcpPack\Fluent\McpManager;
use Padosoft\AskMyDocsMcpPack\Fluent\Tool;
use Padosoft\AskMyDocsMcpPack\Tests\Support\TestAppFactory;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

final class FluentCatalogTest extends TestCase
{
    public function test_compiles_deterministic_immutable_tenant_snapshots_and_dynamic_changes(): void
    {
        $manager = $this->app->make(McpManager::class);
        $manager->server('docs')->name('Docs')->tool(Tool::make('zeta')->handle(fn () => 'z'))->tool(Tool::make('alpha')->handle(fn () => 'a'))->register();

        $tenantA = $manager->require('docs')->forTenant('tenant-a', 'alice');
        $tenantB = $manager->require('docs')->forTenant('tenant-b', 'bob');
        $this->assertSame(['alpha', 'zeta'], array_column($tenantA->snapshot()['definitions']['tools'], 'name'));

        $tenantA->upsert(new ToolDefinition('beta', null, ['type' => 'object'], null, fn () => 'b'));
        $this->assertSame(['alpha', 'beta', 'zeta'], array_column($tenantA->snapshot()['definitions']['tools'], 'name'));
        $this->assertSame(['alpha', 'zeta'], array_column($tenantB->snapshot()['definitions']['tools'], 'name'));
        $subscriptions = $this->app->make(SubscriptionBrokerContract::class);
        $this->assertCount(1, $subscriptions->listen('tenant-a', principalId: 'alice'));
        $this->assertSame([], $subscriptions->listen('tenant-a', principalId: 'mallory'));

        $this->assertTrue($tenantA->remove('tools', 'alpha'));
        $this->assertSame(['beta', 'zeta'], array_column($tenantA->snapshot()['definitions']['tools'], 'name'));
        $this->assertFalse($tenantA->remove('tools', 'missing'));
    }

    public function test_async_closures_are_rejected_at_compile_time(): void
    {
        $this->expectException(\LogicException::class);
        Tool::make('async')->handle(fn () => 'no')->asTask()->compile();
    }

    public function test_async_non_class_callables_are_rejected_at_compile_time(): void
    {
        $this->expectException(\LogicException::class);
        Tool::make('async')->handle([new class
        {
            public function __invoke(): string
            {
                return 'no';
            }
        }, '__invoke'])->asTask()->compile();
    }

    public function test_apps_accept_builders_closure_factories_and_class_factories(): void
    {
        $manager = $this->app->make(McpManager::class);
        $manager->server('apps')
            ->app(App::make('direct')->resource('ui://apps/direct')->html('<main>direct</main>'))
            ->app(fn (): App => App::make('closure')->resource('ui://apps/closure')->html('<main>closure</main>'))
            ->app(TestAppFactory::class)
            ->register();

        $apps = $manager->require('apps')->forTenant('acme', 'alice')->snapshot()['definitions']['apps'];
        $this->assertSame(['class-factory', 'closure', 'direct'], array_column($apps, 'name'));
    }

    public function test_experimental_download_file_requires_an_explicit_flag(): void
    {
        config()->set('mcp-pack.apps.experimental_download_file', false);
        try {
            App::make('download')->resource('ui://apps/download')->html('<main/>')->downloadFile();
            $this->fail('Experimental download-file must be disabled by default.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('experimental and disabled', $e->getMessage());
        }

        config()->set('mcp-pack.apps.experimental_download_file', true);
        $app = App::make('download')->resource('ui://apps/download')->html('<main/>')->downloadFile()->compile();
        $this->assertInstanceOf(\stdClass::class, $app->toArray()['_meta']['ui']['downloadFile']);
    }
}
