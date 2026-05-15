<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Resilience\CircuitBreaker;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeMcpServer;
use Padosoft\AskMyDocsMcpPack\Tests\Support\StubHandshakeService;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class CircuitBreakerControllerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mcp-pack.admin.enabled', true);
        $app['config']->set('mcp-pack.admin.middleware', ['api', InjectTenantMiddleware::class]);
        // Threshold of 1 so a single failure OPENs the breaker
        // deterministically inside the test.
        $app['config']->set('mcp-pack.resilience.circuit_breaker.failure_threshold', 1);
        $app['config']->set('mcp-pack.resilience.circuit_breaker.recovery_seconds', 30);
    }

    protected function setUp(): void
    {
        parent::setUp();
        InjectTenantMiddleware::$tenantId = null;
    }

    public function test_missing_server_param_returns_400(): void
    {
        $response = $this->getJson('/api/admin/mcp-pack/circuit-breaker');
        $response->assertStatus(400);
        $this->assertSame('missing_parameter', $response->json('error.code'));
    }

    public function test_unknown_server_returns_404(): void
    {
        $this->app->instance(McpServerRegistryContract::class, new InMemoryMcpServerRegistry());
        $response = $this->getJson('/api/admin/mcp-pack/circuit-breaker?server=missing');
        $response->assertStatus(404);
    }

    public function test_reports_state_for_explicit_tool(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new FakeMcpServer(id: 'srv-1', tenantId: null));
        $this->app->instance(McpServerRegistryContract::class, $registry);

        // Open the breaker by recording a failure via the bound instance.
        /** @var CircuitBreaker $cb */
        $cb = $this->app->make(CircuitBreaker::class);
        $cb->recordFailure('srv-1', 'kb.search', 'timeout');

        $response = $this->getJson('/api/admin/mcp-pack/circuit-breaker?server=srv-1&tool=kb.search');
        $response->assertOk();
        $this->assertSame('open', $response->json('data.0.state'));
        $this->assertSame('srv-1', $response->json('data.0.server_id'));
        $this->assertSame('kb.search', $response->json('data.0.tool_name'));
        $this->assertGreaterThan(0, $response->json('data.0.retry_after_seconds'));
        $this->assertSame(1, $response->json('meta.count'));
    }

    public function test_sweeps_allowed_tools_when_no_tool_param(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new FakeMcpServer(
            id: 'srv-2',
            tenantId: null,
            allowedTools: ['kb.search', 'kb.write'],
        ));
        $this->app->instance(McpServerRegistryContract::class, $registry);

        $response = $this->getJson('/api/admin/mcp-pack/circuit-breaker?server=srv-2');
        $response->assertOk();
        $names = array_column($response->json('data'), 'tool_name');
        $this->assertEqualsCanonicalizing(['kb.search', 'kb.write'], $names);
        $this->assertSame(['closed', 'closed'], array_column($response->json('data'), 'state'));
    }

    public function test_enforces_tenant_boundary(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new FakeMcpServer(id: 'srv-acme', tenantId: 'acme'));
        $this->app->instance(McpServerRegistryContract::class, $registry);

        InjectTenantMiddleware::$tenantId = 'globex';

        $this->getJson('/api/admin/mcp-pack/circuit-breaker?server=srv-acme&tool=kb.search')
            ->assertStatus(404);
    }

    public function test_sweep_falls_back_to_handshake_cache_when_allowed_tools_empty(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        // allowedTools is empty → "all advertised tools" semantics; the
        // sweep MUST pick up the handshake cache instead of returning
        // an empty list.
        $registry->add(new FakeMcpServer(id: 'srv-all', tenantId: null, allowedTools: []));
        $this->app->instance(McpServerRegistryContract::class, $registry);

        $stub = new StubHandshakeService();
        $stub->peekHit = true;
        $stub->payload = [
            'capabilities' => ['tools' => []],
            'tools' => [
                ['name' => 'kb.search'],
                ['name' => 'kb.write'],
            ],
        ];
        $this->app->instance(McpHandshakeService::class, $stub);

        $response = $this->getJson('/api/admin/mcp-pack/circuit-breaker?server=srv-all');
        $response->assertOk();
        $names = array_column($response->json('data'), 'tool_name');
        $this->assertEqualsCanonicalizing(['kb.search', 'kb.write'], $names);
    }

    public function test_is_tenant_scoped_under_id_reuse(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new FakeMcpServer(id: 'srv-1', tenantId: 'acme', allowedTools: ['kb.search']));
        $registry->add(new FakeMcpServer(id: 'srv-1', tenantId: 'globex', allowedTools: ['kb.write']));
        $this->app->instance(McpServerRegistryContract::class, $registry);

        // acme view → sees its own kb.search entry.
        InjectTenantMiddleware::$tenantId = 'acme';
        $response = $this->getJson('/api/admin/mcp-pack/circuit-breaker?server=srv-1');
        $response->assertOk();
        $this->assertSame(['kb.search'], array_column($response->json('data'), 'tool_name'));

        // globex view → sees its own kb.write entry.
        InjectTenantMiddleware::$tenantId = 'globex';
        $response = $this->getJson('/api/admin/mcp-pack/circuit-breaker?server=srv-1');
        $response->assertOk();
        $this->assertSame(['kb.write'], array_column($response->json('data'), 'tool_name'));
    }
}
