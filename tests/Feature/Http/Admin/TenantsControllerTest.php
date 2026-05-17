<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Support\HostTenant;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeIdentityBridge;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class TenantsControllerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mcp-pack.admin.enabled', true);
        $app['config']->set('mcp-pack.admin.middleware', ['api', InjectTenantMiddleware::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        InjectTenantMiddleware::$tenantId = null;
    }

    private function bindBridge(): FakeIdentityBridge
    {
        $bridge = new FakeIdentityBridge();
        $this->app->instance(McpHostBridgeIdentityContract::class, $bridge);
        return $bridge;
    }

    public function test_index_returns_tenants_with_active_tenant_meta(): void
    {
        $bridge = $this->bindBridge();
        $bridge->tenants = [
            new HostTenant(id: 'acme-corp', name: 'Acme Corp', primary: true),
            new HostTenant(id: 'demo-corp', name: 'Demo Corp'),
        ];
        InjectTenantMiddleware::$tenantId = 'acme-corp';

        $response = $this->getJson('/api/admin/mcp-pack/tenants');
        $response->assertOk();
        $this->assertSame('acme-corp', $response->json('data.0.id'));
        $this->assertTrue($response->json('data.0.primary'));
        $this->assertSame(2, $response->json('meta.count'));
        $this->assertSame('acme-corp', $response->json('meta.active_tenant_id'));
    }

    public function test_index_returns_empty_array_when_host_has_no_tenants_visible(): void
    {
        $bridge = $this->bindBridge();
        $bridge->tenants = [];

        $response = $this->getJson('/api/admin/mcp-pack/tenants');
        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.count'));
    }

    public function test_index_returns_501_when_host_does_not_implement(): void
    {
        $this->bindBridge(); // tenants stays null → throws

        $response = $this->getJson('/api/admin/mcp-pack/tenants');
        $response->assertStatus(501);
        $this->assertSame('feature_not_implemented', $response->json('error.code'));
    }

    public function test_returns_403_when_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.tenants', false);
        $this->bindBridge();

        $response = $this->getJson('/api/admin/mcp-pack/tenants');
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }
}
