<?php

namespace Padosoft\AskMyDocsMcpPack;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Padosoft\AskMyDocsMcpPack\Adapters\LaravelMcpAdapter;
use Padosoft\AskMyDocsMcpPack\Apps\AppRenderer;
use Padosoft\AskMyDocsMcpPack\Artifacts\FlysystemArtifactManager;
use Padosoft\AskMyDocsMcpPack\Auth\HostAuthenticationResolver;
use Padosoft\AskMyDocsMcpPack\Auth\NullOAuthAccessTokenValidator;
use Padosoft\AskMyDocsMcpPack\Console\McpPingCommand;
use Padosoft\AskMyDocsMcpPack\Console\McpServeCommand;
use Padosoft\AskMyDocsMcpPack\Console\PruneMcpArtifactsCommand;
use Padosoft\AskMyDocsMcpPack\Console\PruneMcpTasksCommand;
use Padosoft\AskMyDocsMcpPack\Console\RecoverMcpTasksCommand;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerExposureContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerMutableRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\ArtifactManagerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\AuthenticationResolverContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\CancellationRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\OAuthAccessTokenValidatorContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\SubscriptionBrokerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\TaskManagerContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpHostBridge;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpServerExposure;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpToolAuthorizer;
use Padosoft\AskMyDocsMcpPack\Defaults\ReadOnlyMutableRegistryAdapter;
use Padosoft\AskMyDocsMcpPack\Fluent\McpManager;
use Padosoft\AskMyDocsMcpPack\Http\Admin\ApiKeysController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\AuditController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\CircuitBreakerController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\EventsSseController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\MeController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\OpenApiController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\PromptsController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\ResourcesController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\ServersController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\TenantsController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\ToolsController;
use Padosoft\AskMyDocsMcpPack\Http\Admin\V2\AdminController as AdminV2Controller;
use Padosoft\AskMyDocsMcpPack\Http\Admin\V2\OpenApiController as OpenApiV2Controller;
use Padosoft\AskMyDocsMcpPack\Http\V2\ArtifactDownloadController;
use Padosoft\AskMyDocsMcpPack\Http\V2\McpStreamableHttpController;
use Padosoft\AskMyDocsMcpPack\Http\V2\Middleware\RequireAdminIdentity;
use Padosoft\AskMyDocsMcpPack\Http\V2\Middleware\ValidateOAuthResourceRequest;
use Padosoft\AskMyDocsMcpPack\Http\V2\OAuthProtectedResourceController;
use Padosoft\AskMyDocsMcpPack\Protocol\CursorCodec;
use Padosoft\AskMyDocsMcpPack\Protocol\HandlerInvoker;
use Padosoft\AskMyDocsMcpPack\Protocol\RequestStateCipher;
use Padosoft\AskMyDocsMcpPack\Resilience\CircuitBreaker;
use Padosoft\AskMyDocsMcpPack\Resilience\ResilienceMediator;
use Padosoft\AskMyDocsMcpPack\Resilience\RetryBudget;
use Padosoft\AskMyDocsMcpPack\ServerSide\JsonRpcRequestHandler;
use Padosoft\AskMyDocsMcpPack\ServerSide\V2JsonRpcRequestHandler;
use Padosoft\AskMyDocsMcpPack\Services\McpDiscoveryService;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;
use Padosoft\AskMyDocsMcpPack\Services\McpToolCallingService;
use Padosoft\AskMyDocsMcpPack\Services\ToolInvoker;
use Padosoft\AskMyDocsMcpPack\Subscriptions\CacheCancellationRegistry;
use Padosoft\AskMyDocsMcpPack\Subscriptions\CacheSubscriptionBroker;
use Padosoft\AskMyDocsMcpPack\Tasks\DatabaseTaskManager;
use Padosoft\AskMyDocsMcpPack\Validation\JsonSchemaValidator;

class AskMyDocsMcpPackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mcp-pack.php', 'mcp-pack');

        $this->app->singleton(McpHostBridgeContract::class, NullMcpHostBridge::class);
        $this->app->singleton(McpServerRegistryContract::class, InMemoryMcpServerRegistry::class);
        $this->app->singleton(McpToolAuthorizerContract::class, NullMcpToolAuthorizer::class);
        $this->app->singleton(McpServerExposureContract::class, NullMcpServerExposure::class);

        $this->app->singleton(SubscriptionBrokerContract::class, function ($app) {
            $store = config('mcp-pack.v2.cache_store');
            $cache = $app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null);

            return new CacheSubscriptionBroker($cache, (int) config('mcp-pack.subscriptions.ttl_seconds', 3600));
        });
        $this->app->singleton(CancellationRegistryContract::class, function ($app) {
            $store = config('mcp-pack.v2.cache_store');

            return new CacheCancellationRegistry($app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null));
        });
        $this->app->singleton(McpManager::class);
        $this->app->singleton(AuthenticationResolverContract::class, HostAuthenticationResolver::class);
        $this->app->singleton(OAuthAccessTokenValidatorContract::class, NullOAuthAccessTokenValidator::class);
        $this->app->singleton(ArtifactManagerContract::class, FlysystemArtifactManager::class);
        $this->app->singleton(DatabaseTaskManager::class);
        $this->app->singleton(TaskManagerContract::class, fn ($app) => $app->make(DatabaseTaskManager::class));
        $this->app->singleton(HandlerInvoker::class);
        $this->app->singleton(AppRenderer::class);
        $this->app->singleton(RequestStateCipher::class);
        $this->app->singleton(JsonSchemaValidator::class, fn () => new JsonSchemaValidator(
            maxSchemaBytes: (int) config('mcp-pack.validation.max_schema_bytes', 262_144),
            maxInstanceBytes: (int) config('mcp-pack.validation.max_instance_bytes', 1_048_576),
            maxDepth: (int) config('mcp-pack.validation.max_depth', 64),
            allowedRemoteHosts: array_values(array_filter((array) config('mcp-pack.validation.remote_ref_hosts', []), 'is_string')),
        ));
        $this->app->singleton(CursorCodec::class, fn () => new CursorCodec(hash('sha256', (string) config('app.key', 'mcp-pack-v2'))));
        $this->app->singleton(V2JsonRpcRequestHandler::class);
        $this->app->singleton(LaravelMcpAdapter::class);

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

        $this->app->singleton(McpDiscoveryService::class, function ($app) {
            return new McpDiscoveryService(
                ttlSeconds: (int) config('mcp-pack.handshake.ttl_seconds', 300),
            );
        });
        $this->app->singleton(McpHandshakeService::class, fn () => new McpHandshakeService(ttlSeconds: (int) config('mcp-pack.handshake.ttl_seconds', 300)));

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
        if ((bool) config('mcp-pack.laravel_mcp.enabled', false)) {
            $this->app->make(LaravelMcpAdapter::class)->assertCompatible();
        }
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mcp-pack.php' => config_path('mcp-pack.php'),
            ], 'mcp-pack-config');

            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'mcp-pack-migrations');

            // v1.5.0 — opt-in identity-surface migrations (user
            // preferences + API keys). Published under the same
            // `mcp-pack-migrations` tag for ergonomics PLUS a
            // dedicated tag so a host can publish ONLY the identity
            // tables without re-publishing the audit table.
            $this->publishes([
                __DIR__.'/../database/migrations-optional/' => database_path('migrations'),
            ], 'mcp-pack-migrations');

            $this->publishes([
                __DIR__.'/../database/migrations-optional/' => database_path('migrations'),
            ], 'mcp-pack-identity-migrations');

            $this->commands([
                McpPingCommand::class,
                McpServeCommand::class,
                PruneMcpTasksCommand::class,
                PruneMcpArtifactsCommand::class,
                RecoverMcpTasksCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerServerSideHttpRoute();
        $this->registerAdminRoutes();
        $this->registerAdminV2Routes();
        $this->registerOAuthMetadataRoute();
        $this->registerArtifactDownloadRoute();
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

            // v1.5.0 W1.D — Resources + Prompts + SSE + OpenAPI.
            // Same UNCONDITIONAL-registration pattern as W1.A/B/C:
            // per-feature gates (`resources`, `prompts`,
            // `events_sse`, `openapi`) are checked inside the
            // controllers via `ResolvesAdminContext::featureGate()`
            // so a runtime flag flip surfaces as HTTP 403
            // `feature_disabled` (not 404).
            //
            // The `{uri}` segment uses `.+` (NOT `[^/]+` — see the
            // explanatory comment on the route below) so URIs that
            // contain literal `/` (e.g. `mcp://openai/docs/readme.md`)
            // match correctly after Symfony's router decodes the
            // path. The controller receives the parameter ALREADY
            // decoded by Symfony (decode-exactly-once, R19) and
            // forwards it to the bridge as-is.
            Route::get('servers/{id}/resources', [ResourcesController::class, 'index'])
                ->where('id', '[A-Za-z0-9._\-]+')
                ->name('mcp-pack.admin.servers.resources.index');
            // The `{uri}` regex is `.+` (not `[^/]+`) because the SPA
            // sends URIs like `mcp://openai/docs/readme.md` which
            // contain literal `/` AFTER Symfony's router has
            // `rawurldecode()`d the path (per UrlMatcher line 73).
            // `[^/]+` would 404 on every URI that has a path
            // component. `.+` is safe because the route prefix
            // anchors `/servers/{id}/resources/` so there is no
            // ambiguity with sibling routes. The controller receives
            // Symfony's already-decoded parameter and forwards it to
            // the bridge unchanged (decode-exactly-once, R19).
            Route::get('servers/{id}/resources/{uri}', [ResourcesController::class, 'show'])
                ->where('id', '[A-Za-z0-9._\-]+')
                ->where('uri', '.+')
                ->name('mcp-pack.admin.servers.resources.show');

            Route::get('servers/{id}/prompts', [PromptsController::class, 'index'])
                ->where('id', '[A-Za-z0-9._\-]+')
                ->name('mcp-pack.admin.servers.prompts.index');
            Route::get('servers/{id}/prompts/{name}', [PromptsController::class, 'show'])
                ->where('id', '[A-Za-z0-9._\-]+')
                ->where('name', '[A-Za-z0-9._\-]+')
                ->name('mcp-pack.admin.servers.prompts.show');

            Route::get('events', EventsSseController::class)->name('mcp-pack.admin.events');

            Route::get('openapi.json', OpenApiController::class)->name('mcp-pack.admin.openapi');
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

    private function resilienceCache(Application $app): CacheRepository
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
        if ((bool) config('mcp-pack.oauth.enabled', false)) {
            $middleware[] = ValidateOAuthResourceRequest::class;
        }

        Route::middleware($middleware)
            ->prefix($prefix)
            ->post('/', McpStreamableHttpController::class)
            ->defaults('mcp_server', (string) config('mcp-pack.v2.default_server', 'default'))
            ->name('mcp-pack.server.http');
    }

    private function registerOAuthMetadataRoute(): void
    {
        if (! (bool) config('mcp-pack.oauth.enabled', false)) {
            return;
        }
        Route::middleware((array) config('mcp-pack.oauth.metadata_middleware', ['api']))
            ->get('/.well-known/oauth-protected-resource', OAuthProtectedResourceController::class)
            ->name('mcp-pack.v2.oauth.protected-resource');
    }

    private function registerAdminV2Routes(): void
    {
        if (! (bool) config('mcp-pack.admin_v2.enabled', false)) {
            return;
        }
        $middleware = (array) config('mcp-pack.admin_v2.middleware', ['api']);
        $middleware[] = RequireAdminIdentity::class;
        Route::middleware(array_values(array_unique($middleware)))
            ->prefix((string) config('mcp-pack.admin_v2.prefix', 'api/admin/mcp-pack/v2'))
            ->group(function (): void {
                Route::get('capabilities', [AdminV2Controller::class, 'capabilities']);
                Route::get('apps', [AdminV2Controller::class, 'apps']);
                Route::get('tasks', [AdminV2Controller::class, 'tasks']);
                Route::get('tasks/{task}', [AdminV2Controller::class, 'task'])->whereUuid('task');
                Route::post('tasks/{task}/input', [AdminV2Controller::class, 'updateTask'])->whereUuid('task');
                Route::post('tasks/{task}/cancel', [AdminV2Controller::class, 'cancelTask'])->whereUuid('task');
                Route::get('artifacts', [AdminV2Controller::class, 'artifacts']);
                Route::post('artifacts', [AdminV2Controller::class, 'storeArtifact']);
                Route::get('artifacts/{artifact}', [AdminV2Controller::class, 'artifact'])->whereUuid('artifact');
                Route::get('artifacts/{artifact}/download', [AdminV2Controller::class, 'artifactUrl'])->whereUuid('artifact');
                Route::delete('artifacts/{artifact}', [AdminV2Controller::class, 'deleteArtifact'])->whereUuid('artifact');
                Route::get('openapi.json', OpenApiV2Controller::class);
            });
    }

    private function registerArtifactDownloadRoute(): void
    {
        if (! (bool) config('mcp-pack.artifacts.enabled', true)) {
            return;
        }
        Route::get('/mcp-pack/v2/artifacts/{artifact}/download', ArtifactDownloadController::class)
            ->whereUuid('artifact')
            ->name('mcp-pack.v2.artifacts.download');
    }
}
