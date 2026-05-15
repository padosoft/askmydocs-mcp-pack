<?php

namespace Padosoft\AskMyDocsMcpPack;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Padosoft\AskMyDocsMcpPack\Console\McpPingCommand;
use Padosoft\AskMyDocsMcpPack\Console\McpServeCommand;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerExposureContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpHostBridge;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpServerExposure;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpToolAuthorizer;
use Padosoft\AskMyDocsMcpPack\Http\Admin\AuditController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\CircuitBreakerController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\ServersController;
use Padosoft\AskMyDocsMcpPack\Http\McpServerHttpController;
use Padosoft\AskMyDocsMcpPack\Resilience\CircuitBreaker;
use Padosoft\AskMyDocsMcpPack\Resilience\ResilienceMediator;
use Padosoft\AskMyDocsMcpPack\Resilience\RetryBudget;
use Padosoft\AskMyDocsMcpPack\ServerSide\JsonRpcRequestHandler;
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
        $this->app->singleton(McpServerExposureContract::class, NullMcpServerExposure::class);

        $this->app->singleton(JsonRpcRequestHandler::class, function ($app) {
            return new JsonRpcRequestHandler(
                exposure: $app->make(McpServerExposureContract::class),
                authorizer: $app->make(McpToolAuthorizerContract::class),
            );
        });

        $this->registerResilience();

        $this->app->singleton(ToolInvoker::class, function ($app) {
            $cb = (bool) config('mcp-pack.resilience.circuit_breaker.enabled', false);
            $retry = (bool) config('mcp-pack.resilience.retry.enabled', false);
            // Only inject the mediator when at least one of the two
            // layers is enabled. Otherwise the invoker behaves
            // exactly as in v1.2 — bare callTool() with no wrapping.
            $mediator = ($cb || $retry) ? $app->make(ResilienceMediator::class) : null;
            return new ToolInvoker(resilience: $mediator);
        });

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

            $this->commands([
                McpPingCommand::class,
                McpServeCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->registerServerSideHttpRoute();
        $this->registerAdminRoutes();
    }

    /**
     * v1.4.0 — register the admin REST surface under the configured
     * prefix (default `api/admin/mcp-pack`). Disabled by default.
     */
    private function registerAdminRoutes(): void
    {
        if (! (bool) config('mcp-pack.admin.enabled', false)) {
            return;
        }

        $prefix = (string) config('mcp-pack.admin.prefix', 'api/admin/mcp-pack');
        $middleware = (array) config('mcp-pack.admin.middleware', ['api']);

        Route::middleware($middleware)->prefix($prefix)->group(function (): void {
            Route::get('servers', [ServersController::class, 'index'])->name('mcp-pack.admin.servers.index');
            Route::get('servers/{id}', [ServersController::class, 'show'])->name('mcp-pack.admin.servers.show');
            Route::post('servers/{id}/handshake', [ServersController::class, 'handshake'])->name('mcp-pack.admin.servers.handshake');
            Route::get('servers/{id}/tools', [ServersController::class, 'tools'])->name('mcp-pack.admin.servers.tools');
            Route::get('audit', AuditController::class)->name('mcp-pack.admin.audit');
            Route::get('circuit-breaker', CircuitBreakerController::class)->name('mcp-pack.admin.circuit-breaker');
        });
    }

    /**
     * v1.3.0 — bind the circuit breaker + retry budget + mediator,
     * each backed by the configured cache store (defaulting to the
     * app's default cache when `cache_store` is null).
     */
    private function registerResilience(): void
    {
        $this->app->singleton(CircuitBreaker::class, function ($app) {
            return new CircuitBreaker(
                cache: $this->resilienceCache($app),
                events: $app->make(Dispatcher::class),
                failureThreshold: max(1, (int) config('mcp-pack.resilience.circuit_breaker.failure_threshold', 5)),
                recoverySeconds: max(1, (int) config('mcp-pack.resilience.circuit_breaker.recovery_seconds', 30)),
            );
        });

        $this->app->singleton(RetryBudget::class, function ($app) {
            return new RetryBudget(
                cache: $this->resilienceCache($app),
                bucketSize: max(1, (int) config('mcp-pack.resilience.retry.bucket_size', 20)),
                windowSeconds: max(1, (int) config('mcp-pack.resilience.retry.bucket_window_seconds', 60)),
            );
        });

        $this->app->singleton(ResilienceMediator::class, function ($app) {
            return new ResilienceMediator(
                breaker: $app->make(CircuitBreaker::class),
                budget: $app->make(RetryBudget::class),
                events: $app->make(Dispatcher::class),
                maxAttempts: max(1, (int) config('mcp-pack.resilience.retry.max_attempts', 3)),
                baseBackoffMs: max(0, (int) config('mcp-pack.resilience.retry.base_backoff_ms', 200)),
                maxBackoffMs: max(0, (int) config('mcp-pack.resilience.retry.max_backoff_ms', 5000)),
                breakerEnabled: (bool) config('mcp-pack.resilience.circuit_breaker.enabled', false),
                retryEnabled: (bool) config('mcp-pack.resilience.retry.enabled', false),
            );
        });
    }

    private function resilienceCache(\Illuminate\Contracts\Foundation\Application $app): CacheRepository
    {
        $store = config('mcp-pack.resilience.cache_store');
        $factory = $app->make(CacheFactory::class);
        return is_string($store) && $store !== ''
            ? $factory->store($store)
            : $factory->store();
    }

    /**
     * v1.2.0 — register the HTTP front-door at
     * `config('mcp-pack.server_side.http.prefix')` (default
     * `/mcp`). Host wires its preferred middleware stack via
     * `config('mcp-pack.server_side.http.middleware')`.
     */
    private function registerServerSideHttpRoute(): void
    {
        if (! (bool) config('mcp-pack.server_side.http.enabled', false)) {
            return;
        }

        $prefix = (string) config('mcp-pack.server_side.http.prefix', 'mcp');
        $middleware = (array) config('mcp-pack.server_side.http.middleware', ['api']);

        Route::middleware($middleware)
            ->prefix($prefix)
            ->post('/', McpServerHttpController::class)
            ->name('mcp-pack.server.http');
    }
}
