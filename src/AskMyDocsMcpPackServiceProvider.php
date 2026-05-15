<?php

namespace Padosoft\AskMyDocsMcpPack;

use Illuminate\Support\ServiceProvider;
use Padosoft\AskMyDocsMcpPack\Console\McpPingCommand;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpHostBridge;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpToolAuthorizer;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;
use Padosoft\AskMyDocsMcpPack\Services\McpToolCallingService;
use Padosoft\AskMyDocsMcpPack\Services\ToolInvoker;

class AskMyDocsMcpPackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mcp-pack.php', 'mcp-pack');

        $this->app->singleton(McpHostBridgeContract::class, NullMcpHostBridge::class);
        $this->app->singleton(McpServerRegistryContract::class, InMemoryMcpServerRegistry::class);
        $this->app->singleton(McpToolAuthorizerContract::class, NullMcpToolAuthorizer::class);

        $this->app->singleton(ToolInvoker::class, fn() => new ToolInvoker());

        $this->app->singleton(McpHandshakeService::class, function ($app) {
            return new McpHandshakeService(
                ttlSeconds: (int) config('mcp-pack.handshake.ttl_seconds', 300),
            );
        });

        $this->app->singleton(McpToolCallingService::class, function ($app) {
            return new McpToolCallingService(
                host: $app->make(McpHostBridgeContract::class),
                registry: $app->make(McpServerRegistryContract::class),
                authorizer: $app->make(McpToolAuthorizerContract::class),
                invoker: $app->make(ToolInvoker::class),
                handshake: $app->make(McpHandshakeService::class),
                maxIterations: max(1, (int) config('mcp-pack.tool_calling.max_iterations', 3)),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/mcp-pack.php' => config_path('mcp-pack.php'),
            ], 'mcp-pack-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'mcp-pack-migrations');

            $this->commands([McpPingCommand::class]);
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
