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
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerExposureContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerMutableRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpHostBridge;
use Padosoft\AskMyDocsMcpPack\Defaults\ReadOnlyMutableRegistryAdapter;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpServerExposure;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpToolAuthorizer;
use Padosoft\AskMyDocsMcpPack\Http\Admin\ApiKeysController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\AuditController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\CircuitBreakerController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\MeController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\ServersController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\TenantsController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\ToolsController;
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

        // v1.5.0 — admin REST extension. The identity sub-interface is
        // resolved separately: if the host bound an `McpHostBridgeContract`
        // implementation that ALSO implements
        // `McpHostBridgeIdentityContract`, use it directly. Otherwise
        // fall back to `NullMcpHostBridge` which implements both. The
        // admin controllers type-hint against the sub-interface, so an
        // unwired host degrades to HTTP 501 — never falling through to
        // the host's legacy bridge silently.
        $this->app->singleton(McpHostBridgeIdentityContract::class, function ($app) {
            $bridge = $app->make(McpHostBridgeContract::class);
            if ($bridge instanceof McpHostBridgeIdentityContract) {
                return $bridge;
            }
            return $app->make(NullMcpHostBridge::class);
        });

        // v1.5.0 — admin REST extension W1.B. Same trick as the
        // identity contract: when the host bound `McpServerRegistryContract`
        // to an implementation that ALSO implements
        // `McpServerMutableRegistryContract`, expose it directly.
        // Otherwise fall back to the package's `InMemoryMcpServerRegistry`
        // (which adopts the mutable sub-interface via the
        // `HasMutableRegistry` trait — `create/update/delete` throw
        // `HostFeatureNotImplementedException`, translated to HTTP 501
        // by the controllers; `paginate` actually works in-memory).
        $this->app->singleton(McpServerMutableRegistryContract::class, function ($app) {
            $registry = $app->make(McpServerRegistryContract::class);
            if ($registry instanceof McpServerMutableRegistryContract) {
                return $registry;
            }
            // Iter-1 fix: the previous fallback created a FRESH empty
            // in-memory registry, silently dropping the host's actual
            // server catalog on paginated reads. Wrap the host's read
            // registry in a read-only adapter that delegates
            // `forTenant()` / `find()` to it, exposes a working
            // `paginate()` over the same data, and throws 501 on
            // `create/update/delete`. The SPA's read table works for
            // free; writes get the documented HTTP 501 envelope.
            return new ReadOnlyMutableRegistryAdapter($registry);
        });

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

            // v1.5.0 — opt-in identity-surface migrations (user
            // preferences + API keys). Published under the same
            // `mcp-pack-migrations` tag for ergonomics PLUS a
            // dedicated tag so a host can publish ONLY the identity
            // tables without re-publishing the audit table.
            $this->publishes([
                __DIR__ . '/../database/migrations-optional/' => database_path('migrations'),
            ], 'mcp-pack-migrations');

            $this->publishes([
                __DIR__ . '/../database/migrations-optional/' => database_path('migrations'),
            ], 'mcp-pack-identity-migrations');

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

            // v1.5.0 — W1.B Servers CRUD + flat ToolsController.
            // Registered UNCONDITIONALLY (same pattern as W1.A); the
            // per-feature flag (`servers_write` / `tools`) is checked
            // INSIDE the controller via `ResolvesAdminContext::featureGate()`
            // and answers HTTP 403 `feature_disabled` so the SPA can
            // distinguish "operator disabled this section" from
            // "route does not exist on this package version".
            //
            // The `{id}` regex matches the v1.4 W1.A api-keys pattern
            // exactly: `[A-Za-z0-9._\-]+`. `%` / `*` / whitespace /
            // path separators are blocked. The host owns the real
            // lookup so wildcard chars cannot reach a SQL LIKE.
            Route::post('servers', [ServersController::class, 'store'])->name('mcp-pack.admin.servers.store');
            Route::patch('servers/{id}', [ServersController::class, 'update'])
                ->where('id', '[A-Za-z0-9._\-]+')
                ->name('mcp-pack.admin.servers.update');
            Route::delete('servers/{id}', [ServersController::class, 'destroy'])
                ->where('id', '[A-Za-z0-9._\-]+')
                ->name('mcp-pack.admin.servers.destroy');
            Route::get('tools', [ToolsController::class, 'index'])->name('mcp-pack.admin.tools.index');

            Route::get('audit', AuditController::class)->name('mcp-pack.admin.audit');
            Route::get('circuit-breaker', CircuitBreakerController::class)->name('mcp-pack.admin.circuit-breaker');

            // v1.5.0 W1.C — tool invoke + audit drilldown/replay +
            // breaker reset. Routes are registered UNCONDITIONALLY;
            // per-feature gates live inside the controllers
            // (`tool_invoke`, `audit_show`, `audit_replay`,
            // `breaker_reset`) — same pattern as W1.A + W1.B.
            //
            // Regexes are tight per R19: alphanumerics + `.` `_` `-`
            // on id/name segments; the breaker key additionally
            // allows `:` because it carries the `<server_id>:<tool_name>`
            // compound. URL-encoded `:` (`%3A`) decodes to `:` before
            // the regex sees it, so the route still matches when a
            // SPA encodes defensively.
            Route::post('servers/{id}/tools/{name}/invoke', [ServersController::class, 'invoke'])
                ->where('id', '[A-Za-z0-9._\-]+')
                ->where('name', '[A-Za-z0-9._\-]+')
                ->name('mcp-pack.admin.servers.tools.invoke');

            Route::get('audit/{id}', [AuditController::class, 'show'])
                ->where('id', '[A-Za-z0-9._\-]+')
                ->name('mcp-pack.admin.audit.show');

            Route::post('audit/{id}/replay', [AuditController::class, 'replay'])
                ->where('id', '[A-Za-z0-9._\-]+')
                ->name('mcp-pack.admin.audit.replay');

            Route::post('circuit-breaker/{key}/reset', [CircuitBreakerController::class, 'reset'])
                ->where('key', '[A-Za-z0-9.:_\-]+')
                ->name('mcp-pack.admin.circuit-breaker.reset');

            // v1.5.0 — identity surface (W1.A). Routes are registered
            // UNCONDITIONALLY; the per-feature flag check happens
            // INSIDE the controller via `ResolvesAdminContext::featureGate()`,
            // which returns HTTP 403 `feature_disabled` (not 404). This
            // way the SPA can distinguish "the operator turned this
            // section off" from "the route does not exist on this
            // package version" — and the contract documented in
            // `config/mcp-pack.php` matches actual behaviour.
            Route::get('me', [MeController::class, 'show'])->name('mcp-pack.admin.me.show');
            Route::post('me/preferences', [MeController::class, 'updatePreferences'])->name('mcp-pack.admin.me.preferences');
            Route::get('tenants', [TenantsController::class, 'index'])->name('mcp-pack.admin.tenants.index');
            Route::get('api-keys', [ApiKeysController::class, 'index'])->name('mcp-pack.admin.api-keys.index');
            Route::post('api-keys', [ApiKeysController::class, 'store'])->name('mcp-pack.admin.api-keys.store');
            Route::delete('api-keys/{id}', [ApiKeysController::class, 'destroy'])
                    // R19 / `tok_01`-style ids include `_` so we
                    // ALLOW underscore on the URL segment (it cannot
                    // reach a SQL LIKE; the host owns the lookup).
                    // The forbidden set is `%`, `*`, whitespace,
                    // path separators.
                ->where('id', '[A-Za-z0-9._\-]+')
                ->name('mcp-pack.admin.api-keys.destroy');
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
