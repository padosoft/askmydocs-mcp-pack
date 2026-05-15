<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeMcpServer;
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
