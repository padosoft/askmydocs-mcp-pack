<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeIdentityBridge;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeMcpServer;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class CircuitBreakerControllerResetTest extends TestCase
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

    private function bootRegistry(): InMemoryMcpServerRegistry
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new FakeMcpServer(id: 'srv-a', name: 'Alpha', tenantId: 'acme'));
        $registry->add(new FakeMcpServer(id: 'srv-b', name: 'Beta', tenantId: 'globex'));
        $this->app->instance(McpServerRegistryContract::class, $registry);
        return $registry;
    }

    private function bindBridge(): FakeIdentityBridge
    {
        $bridge = new FakeIdentityBridge();
        $bridge->resetBreakerResult = true;
        $this->app->instance(McpHostBridgeIdentityContract::class, $bridge);
        return $bridge;
    }

    // ----- mint path --------------------------------------------------

    public function test_reset_first_post_mints_token(): void
    {
        $this->bootRegistry();
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a:kb.search/reset', []);
        $response->assertStatus(202);
        $token = $response->json('data.confirm_token');
        $this->assertMatchesRegularExpression('/^tok_[a-f0-9]{32}$/', $token);
    }

    public function test_reset_mint_404_on_cross_tenant(): void
    {
        $this->bootRegistry();
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-b:kb.search/reset', []);
        $response->assertStatus(404);
    }

    public function test_reset_422_on_invalid_key_format(): void
    {
        $this->bootRegistry();
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        // Missing `:` — should be `<server_id>:<tool_name>`.
        $response = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a-only/reset', []);
        $response->assertStatus(422);
    }

    // ----- consume path -----------------------------------------------

    public function test_reset_consume_happy_path(): void
    {
        $this->bootRegistry();
        $bridge = $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $mint = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a:kb.search/reset', []);
        $token = $mint->json('data.confirm_token');

        $consume = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a:kb.search/reset', [
            'confirm_token' => $token,
        ]);
        $consume->assertOk();
        $this->assertTrue($consume->json('data.changed'));
        $this->assertSame([['srv-a', 'kb.search', $token]], $bridge->resetBreakerCalls);
    }

    public function test_reset_consume_422_on_token_reuse(): void
    {
        $this->bootRegistry();
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $mint = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a:kb.search/reset', []);
        $token = $mint->json('data.confirm_token');

        $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a:kb.search/reset', [
            'confirm_token' => $token,
        ])->assertOk();

        $response = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a:kb.search/reset', [
            'confirm_token' => $token,
        ]);
        $response->assertStatus(422);
        $this->assertSame('confirmation_invalid', $response->json('error.code'));
    }

    public function test_reset_consume_422_on_forged_token(): void
    {
        $this->bootRegistry();
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $forged = 'tok_' . str_repeat('f', 32);
        $response = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a:kb.search/reset', [
            'confirm_token' => $forged,
        ]);
        $response->assertStatus(422);
    }

    public function test_reset_consume_422_on_malformed_token(): void
    {
        $this->bootRegistry();
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a:kb.search/reset', [
            'confirm_token' => 'bogus',
        ]);
        $response->assertStatus(422);
    }

    public function test_reset_consume_token_cannot_be_used_for_a_different_target(): void
    {
        // Token minted for srv-a:kb.search must not authorise
        // srv-a:kb.delete.
        $this->bootRegistry();
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        // We need a kb.delete tool to exist as a possible reset
        // target — the controller only checks server visibility
        // (not tool existence) so this works even without the
        // handshake list. Mint for kb.search, try to consume on
        // kb.delete.
        $mint = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a:kb.search/reset', []);
        $token = $mint->json('data.confirm_token');

        $response = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a:kb.delete/reset', [
            'confirm_token' => $token,
        ]);
        $response->assertStatus(422);
        $this->assertSame('confirmation_invalid', $response->json('error.code'));
    }

    public function test_reset_403_when_feature_disabled(): void
    {
        $this->bootRegistry();
        $this->bindBridge();
        $this->app['config']->set('mcp-pack.admin.features.breaker_reset', false);

        $response = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a:kb.search/reset', []);
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    public function test_reset_501_when_bridge_unwired(): void
    {
        $this->bootRegistry();
        $bridge = new FakeIdentityBridge();
        $bridge->forceNotImplemented = true;
        $this->app->instance(McpHostBridgeIdentityContract::class, $bridge);
        InjectTenantMiddleware::$tenantId = 'acme';

        $mint = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a:kb.search/reset', []);
        $token = $mint->json('data.confirm_token');

        $response = $this->postJson('/api/admin/mcp-pack/circuit-breaker/srv-a:kb.search/reset', [
            'confirm_token' => $token,
        ]);
        $response->assertStatus(501);
    }

    public function test_reset_route_stays_registered_when_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.breaker_reset', false);
        $routes = $this->app['router']->getRoutes();
        $this->assertTrue($routes->hasNamedRoute('mcp-pack.admin.circuit-breaker.reset'));
    }

    public function test_reset_404_on_bogus_server_id(): void
    {
        $this->bootRegistry();
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/circuit-breaker/no-such-srv:kb.search/reset', []);
        $response->assertStatus(404);
    }
}
