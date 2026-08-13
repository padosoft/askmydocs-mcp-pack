<?php

namespace Padosoft\AskMyDocsMcpPack\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\AskMyDocsMcpPack\AskMyDocsMcpPackServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AskMyDocsMcpPackServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('mcp-pack.tool_calling.enabled', true);
        $app['config']->set('mcp-pack.handshake.ttl_seconds', 0);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('m', 32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
