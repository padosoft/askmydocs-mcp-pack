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
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
