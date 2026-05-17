<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerMutableRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;
use Padosoft\AskMyDocsMcpPack\Support\McpServerPage;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeMcpServer;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeMutableRegistry;
use Padosoft\AskMyDocsMcpPack\Tests\Support\StubHandshakeService;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class ServersControllerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mcp-pack.admin.enabled', true);
        $app['config']->set('mcp-pack.admin.prefix', 'api/admin/mcp-pack');
        // The injected middleware sets the trusted tenant attribute
        // from a static, mirroring how a host's Sanctum-backed
        // middleware would set it after validating the actor.
        $app['config']->set('mcp-pack.admin.middleware', ['api', InjectTenantMiddleware::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Default: no trusted tenant attribute set (anonymous /
        // platform-global view); individual tests override.
        InjectTenantMiddleware::$tenantId = null;
    }

    private function bootRegistry(): InMemoryMcpServerRegistry
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new FakeMcpServer(id: 'srv-a', name: 'Alpha', tenantId: 'acme'));
        $registry->add(new FakeMcpServer(id: 'srv-b', name: 'Beta', tenantId: 'globex'));
        $registry->add(new FakeMcpServer(id: 'srv-global', name: 'Platform', tenantId: null));
        $registry->add(new FakeMcpServer(id: 'srv-off', name: 'Disabled', tenantId: 'acme', enabled: false));

        $this->app->instance(McpServerRegistryContract::class, $registry);
        return $registry;
    }

    private function bootHandshakeStub(): StubHandshakeService
    {
        $stub = new StubHandshakeService();
        $this->app->instance(McpHandshakeService::class, $stub);
        return $stub;
    }

    public function test_index_lists_servers_visible_to_the_active_tenant(): void
    {
        $this->bootRegistry();
        $response = $this->getJson('/api/admin/mcp-pack/servers');
        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        // No tenant attribute set → only platform-global servers
        // visible (and the registry hides disabled rows already).
        $this->assertContains('srv-global', $ids);
        $this->assertNotContains('srv-off', $ids);
    }

    public function test_index_honours_trusted_tenant_attribute(): void
    {
        $this->bootRegistry();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/servers');
        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains('srv-a', $ids, 'tenant-scoped server visible to acme');
        $this->assertContains('srv-global', $ids, 'platform-global always visible');
        $this->assertNotContains('srv-b', $ids, 'globex-scoped server hidden from acme');
        $this->assertSame('acme', $response->json('meta.tenant_id'));
    }

    public function test_show_returns_404_when_server_missing(): void
    {
        $this->bootRegistry();
        $response = $this->getJson('/api/admin/mcp-pack/servers/no-such-id');
        $response->assertStatus(404);
        $this->assertSame('not_found', $response->json('error.code'));
    }

    public function test_show_enforces_tenant_boundary(): void
    {
        $this->bootRegistry();
        InjectTenantMiddleware::$tenantId = 'acme';

        // srv-b belongs to globex → must look as if it doesn't exist.
        $this->getJson('/api/admin/mcp-pack/servers/srv-b')->assertStatus(404);
        // Own tenant + platform-global both visible.
        $this->getJson('/api/admin/mcp-pack/servers/srv-a')->assertOk();
        $this->getJson('/api/admin/mcp-pack/servers/srv-global')->assertOk();
    }

    public function test_handshake_returns_payload_from_handshake_service(): void
    {
        $this->bootRegistry();
        $stub = $this->bootHandshakeStub();
        $stub->payload = [
            'capabilities' => ['tools' => []],
            'tools' => [['name' => 'kb.search', 'description' => 'search']],
        ];

        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-global/handshake');
        $response->assertOk();
        $this->assertSame('srv-global', $response->json('data.server_id'));
        $this->assertSame('kb.search', $response->json('data.tools.0.name'));
        $this->assertSame(1, $stub->refreshCalls);
        $this->assertSame([false], $stub->forceCalls);

        // force=1 should propagate to the handshake service.
        $this->postJson('/api/admin/mcp-pack/servers/srv-global/handshake?force=1')->assertOk();
        $this->assertSame([false, true], $stub->forceCalls);
    }

    public function test_handshake_surfaces_502_on_transport_failure(): void
    {
        $this->bootRegistry();
        $stub = $this->bootHandshakeStub();
        $stub->throwMessage = 'connection refused';

        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-global/handshake');
        $response->assertStatus(502);
        $this->assertSame('handshake_failed', $response->json('error.code'));
    }

    public function test_show_is_tenant_scoped_under_id_reuse(): void
    {
        // Two tenants reuse the same server id "srv-1" — the contract
        // documents ids as scoped per tenant. `show` must surface the
        // active tenant's row, not the other tenant's same-id entry.
        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new FakeMcpServer(id: 'srv-1', name: 'Acme Alpha', tenantId: 'acme'));
        $registry->add(new FakeMcpServer(id: 'srv-1', name: 'Globex Alpha', tenantId: 'globex'));
        $this->app->instance(McpServerRegistryContract::class, $registry);

        InjectTenantMiddleware::$tenantId = 'globex';
        $response = $this->getJson('/api/admin/mcp-pack/servers/srv-1');
        $response->assertOk();
        $this->assertSame('Globex Alpha', $response->json('data.name'));

        InjectTenantMiddleware::$tenantId = 'acme';
        $response = $this->getJson('/api/admin/mcp-pack/servers/srv-1');
        $response->assertOk();
        $this->assertSame('Acme Alpha', $response->json('data.name'));
    }

    public function test_handshake_reports_cached_true_only_on_real_cache_hit(): void
    {
        $this->bootRegistry();
        $stub = $this->bootHandshakeStub();
        $stub->payload = [
            'capabilities' => ['tools' => []],
            'tools' => [['name' => 'kb.search']],
        ];

        // First call: no cache yet → `cached: false` even with force=0.
        $stub->peekHit = false;
        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-global/handshake');
        $response->assertOk();
        $this->assertFalse($response->json('data.cached'));

        // Cache populated → `cached: true` on force=0.
        $stub->peekHit = true;
        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-global/handshake');
        $response->assertOk();
        $this->assertTrue($response->json('data.cached'));

        // force=1 → `cached: false` regardless of cache state.
        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-global/handshake?force=1');
        $response->assertOk();
        $this->assertFalse($response->json('data.cached'));
    }

    public function test_tools_filters_by_allowed_tools_when_configured(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new FakeMcpServer(
            id: 'srv-scoped',
            tenantId: null,
            allowedTools: ['kb.search'],
        ));
        $this->app->instance(McpServerRegistryContract::class, $registry);

        $stub = $this->bootHandshakeStub();
        $stub->payload = [
            'capabilities' => ['tools' => []],
            'tools' => [
                ['name' => 'kb.search', 'description' => 'search'],
                ['name' => 'kb.write', 'description' => 'write'],
            ],
        ];

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv-scoped/tools');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('kb.search', $response->json('data.0.name'));
        $this->assertTrue($response->json('meta.filtered'));
    }

    // ----- v1.5.0 W1.B — pagination on /servers (read path) ---------

    public function test_index_with_per_page_routes_through_paginate(): void
    {
        $this->bootRegistry();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/servers?per_page=10');
        $response->assertOk();
        $meta = $response->json('meta');
        $this->assertArrayHasKey('total', $meta);
        $this->assertArrayHasKey('per_page', $meta);
        $this->assertArrayHasKey('current_page', $meta);
        $this->assertArrayHasKey('last_page', $meta);
        $this->assertSame(10, $meta['per_page']);
        $this->assertSame(1, $meta['current_page']);
        $this->assertSame('acme', $meta['tenant_id']);
    }

    public function test_index_pagination_filters_propagate_to_registry(): void
    {
        $this->bootRegistry();
        // q=Platform matches only the platform-global server.
        $response = $this->getJson('/api/admin/mcp-pack/servers?q=Platform&per_page=5');
        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame(['srv-global'], $ids);
    }

    public function test_index_per_page_is_clamped_to_200(): void
    {
        $this->bootRegistry();
        $response = $this->getJson('/api/admin/mcp-pack/servers?per_page=99999');
        $response->assertOk();
        $this->assertSame(200, $response->json('meta.per_page'));
    }

    // ----- v1.5.0 W1.B — POST /servers --------------------------------

    private function bindMutable(): FakeMutableRegistry
    {
        $mutable = new FakeMutableRegistry();
        $mutable->servers = [
            new FakeMcpServer(id: 'srv-a', name: 'Alpha', tenantId: 'acme'),
            new FakeMcpServer(id: 'srv-b', name: 'Beta', tenantId: 'globex'),
            new FakeMcpServer(id: 'srv-global', name: 'Platform', tenantId: null),
        ];
        $this->app->instance(McpServerRegistryContract::class, $mutable);
        $this->app->instance(McpServerMutableRegistryContract::class, $mutable);
        return $mutable;
    }

    public function test_store_happy_path_returns_201_with_location_header(): void
    {
        $mutable = $this->bindMutable();
        InjectTenantMiddleware::$tenantId = 'acme';

        $mutable->createResult = new FakeMcpServer(id: 'srv-new', name: 'NewSrv', tenantId: 'acme');

        $response = $this->postJson('/api/admin/mcp-pack/servers', [
            'name' => 'NewSrv',
            'transport' => 'http',
            'url' => 'https://example.test/mcp',
        ]);

        $response->assertStatus(201);
        $this->assertSame('srv-new', $response->json('data.id'));
        $this->assertNotNull($response->headers->get('Location'));
        $this->assertStringContainsString('srv-new', $response->headers->get('Location'));
    }

    public function test_store_binds_trusted_tenant_id_ignoring_wire_value(): void
    {
        // R30: the wire `tenant_id` MUST be replaced by the trusted
        // attribute. An attacker on tenant `acme` posting `tenant_id=globex`
        // sees the controller silently scrub the wire value.
        $mutable = $this->bindMutable();
        InjectTenantMiddleware::$tenantId = 'acme';

        $mutable->createResult = new FakeMcpServer(id: 'srv-new', tenantId: 'acme');

        $this->postJson('/api/admin/mcp-pack/servers', [
            'name' => 'newone',
            'transport' => 'http',
            'url' => 'https://example.test',
            'tenant_id' => 'globex',
        ])->assertStatus(201);

        $this->assertSame(1, count($mutable->createCalls));
        [, $attrs] = $mutable->createCalls[0];
        $this->assertSame('acme', $attrs['tenant_id'], 'controller MUST inject trusted tenant id');
    }

    public function test_store_422_on_invalid_transport(): void
    {
        $this->bindMutable();
        $response = $this->postJson('/api/admin/mcp-pack/servers', [
            'name' => 'newone',
            'transport' => 'websocket',
            'url' => 'https://example.test',
        ]);
        $response->assertStatus(422);
    }

    public function test_store_422_on_name_with_sql_like_wildcard(): void
    {
        $this->bindMutable();
        $response = $this->postJson('/api/admin/mcp-pack/servers', [
            'name' => 'bad%name',
            'transport' => 'http',
            'url' => 'https://example.test',
        ]);
        $response->assertStatus(422);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    public function test_store_422_on_control_chars_in_name(): void
    {
        $this->bindMutable();
        $response = $this->postJson('/api/admin/mcp-pack/servers', [
            'name' => "newone\nlog-injection",
            'transport' => 'http',
            'url' => 'https://example.test',
        ]);
        // Note: \n is also outside the regex character class; this
        // assertion just pins that control-char rejection works at
        // either the regex layer or the withValidator layer.
        $response->assertStatus(422);
    }

    public function test_store_403_when_feature_disabled(): void
    {
        $this->bindMutable();
        $this->app['config']->set('mcp-pack.admin.features.servers_write', false);

        $response = $this->postJson('/api/admin/mcp-pack/servers', [
            'name' => 'NewSrv',
            'transport' => 'http',
            'url' => 'https://example.test',
        ]);
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    public function test_store_501_when_host_has_not_bound_mutable_registry(): void
    {
        // The default service-provider fallback is the InMemoryMcpServerRegistry
        // which throws 501 on create/update/delete via the trait.
        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new FakeMcpServer(id: 'srv-a', tenantId: 'acme'));
        $this->app->instance(McpServerRegistryContract::class, $registry);
        $this->app->instance(McpServerMutableRegistryContract::class, $registry);

        $response = $this->postJson('/api/admin/mcp-pack/servers', [
            'name' => 'NewSrv',
            'transport' => 'http',
            'url' => 'https://example.test',
        ]);
        $response->assertStatus(501);
        $this->assertSame('feature_not_implemented', $response->json('error.code'));
    }

    // ----- v1.5.0 W1.B — PATCH /servers/{id} --------------------------

    public function test_update_happy_path_returns_200(): void
    {
        $mutable = $this->bindMutable();
        InjectTenantMiddleware::$tenantId = 'acme';
        $mutable->updateResult = new FakeMcpServer(id: 'srv-a', name: 'AlphaRenamed', tenantId: 'acme');

        $response = $this->patchJson('/api/admin/mcp-pack/servers/srv-a', [
            'name' => 'AlphaRenamed',
        ]);
        $response->assertOk();
        $this->assertSame('AlphaRenamed', $response->json('data.name'));
        $this->assertSame([['srv-a', ['name' => 'AlphaRenamed']]], $mutable->updateCalls);
    }

    public function test_update_404_when_server_missing(): void
    {
        $this->bindMutable();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->patchJson('/api/admin/mcp-pack/servers/srv-missing', [
            'name' => 'X',
        ]);
        $response->assertStatus(404);
        $this->assertSame('not_found', $response->json('error.code'));
    }

    public function test_update_403_cross_tenant(): void
    {
        $this->bindMutable();
        InjectTenantMiddleware::$tenantId = 'acme';
        // srv-b lives under tenant 'globex' — must refuse from 'acme'.
        $response = $this->patchJson('/api/admin/mcp-pack/servers/srv-b', [
            'name' => 'X',
        ]);
        $response->assertStatus(403);
        $this->assertSame('tenant_forbidden', $response->json('error.code'));
    }

    public function test_update_422_when_tenant_id_provided_on_wire(): void
    {
        $this->bindMutable();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->patchJson('/api/admin/mcp-pack/servers/srv-a', [
            'name' => 'AlphaRenamed',
            'tenant_id' => 'acme', // even matching value is rejected
        ]);
        $response->assertStatus(422);
        $this->assertArrayHasKey('tenant_id', $response->json('errors'));
    }

    public function test_update_403_when_feature_disabled(): void
    {
        $this->bindMutable();
        $this->app['config']->set('mcp-pack.admin.features.servers_write', false);

        $response = $this->patchJson('/api/admin/mcp-pack/servers/srv-a', [
            'name' => 'X',
        ]);
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    // ----- v1.5.0 W1.B — DELETE /servers/{id} -------------------------

    public function test_destroy_happy_path_returns_204(): void
    {
        $mutable = $this->bindMutable();
        InjectTenantMiddleware::$tenantId = 'acme';
        $mutable->deleteResult = true;

        $response = $this->deleteJson('/api/admin/mcp-pack/servers/srv-a');
        $response->assertStatus(204);
        $this->assertSame(['srv-a'], $mutable->deleteCalls);
    }

    public function test_destroy_404_when_server_missing(): void
    {
        $this->bindMutable();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->deleteJson('/api/admin/mcp-pack/servers/srv-missing');
        $response->assertStatus(404);
    }

    public function test_destroy_404_when_delete_returns_false(): void
    {
        $mutable = $this->bindMutable();
        InjectTenantMiddleware::$tenantId = 'acme';
        $mutable->deleteResult = false; // idempotent miss

        $response = $this->deleteJson('/api/admin/mcp-pack/servers/srv-a');
        $response->assertStatus(404);
    }

    public function test_destroy_403_cross_tenant(): void
    {
        $this->bindMutable();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->deleteJson('/api/admin/mcp-pack/servers/srv-b');
        $response->assertStatus(403);
        $this->assertSame('tenant_forbidden', $response->json('error.code'));
    }

    public function test_destroy_403_when_feature_disabled(): void
    {
        $this->bindMutable();
        $this->app['config']->set('mcp-pack.admin.features.servers_write', false);

        $response = $this->deleteJson('/api/admin/mcp-pack/servers/srv-a');
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    // ----- v1.5.0 W1.B — route registration architecture pin ----------

    public function test_routes_stay_registered_when_servers_write_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.servers_write', false);

        $routes = $this->app['router']->getRoutes();
        $this->assertTrue($routes->hasNamedRoute('mcp-pack.admin.servers.store'));
        $this->assertTrue($routes->hasNamedRoute('mcp-pack.admin.servers.update'));
        $this->assertTrue($routes->hasNamedRoute('mcp-pack.admin.servers.destroy'));
    }

    public function test_routes_stay_registered_when_tools_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.tools', false);

        $routes = $this->app['router']->getRoutes();
        $this->assertTrue($routes->hasNamedRoute('mcp-pack.admin.tools.index'));
    }
}

/**
 * Inline middleware mirroring how a real host (Sanctum + RBAC) sets
 * the trusted `mcp_pack.tenant_id` attribute on the Symfony request.
 * The static lets a single class drive every test scenario.
 */
class InjectTenantMiddleware
{
    public static ?string $tenantId = null;

    public function handle($request, \Closure $next)
    {
        if (self::$tenantId !== null) {
            $request->attributes->set('mcp_pack.tenant_id', self::$tenantId);
        }
        return $next($request);
    }
}
