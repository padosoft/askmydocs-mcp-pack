<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeIdentityBridge;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class AuditControllerShowReplayTest extends TestCase
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
        $bridge->auditRows = [
            'aud_001' => [
                'id' => 'aud_001',
                'ts' => 1_700_000_000,
                'tenant' => 'acme',
                'server' => 'openai-mcp',
                'server_id' => 'srv_01',
                'method' => 'tools/call',
                'tool' => 'search',
                'status' => 200,
                'dur' => 142,
                'actor' => 'lorenzo@padosoft.com',
                'request' => ['method' => 'tools/call'],
                'response' => ['result' => []],
                'headers' => [],
                'timeline' => [],
                'meta' => ['cache_hit' => false],
            ],
            'aud_globex' => [
                'id' => 'aud_globex',
                'tenant' => 'globex',
            ],
        ];
        $bridge->auditTenants = [
            'aud_001' => 'acme',
            'aud_globex' => 'globex',
        ];
        $this->app->instance(McpHostBridgeIdentityContract::class, $bridge);
        return $bridge;
    }

    // ----- show ------------------------------------------------------

    public function test_show_returns_drilldown_payload(): void
    {
        $bridge = $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/audit/aud_001');
        $response->assertOk();
        $this->assertSame('aud_001', $response->json('data.id'));
        $this->assertSame('search', $response->json('data.tool'));
        // R30: bridge received the trusted tenant id.
        $this->assertSame([['aud_001', 'acme']], $bridge->auditForCalls);
    }

    public function test_show_404_when_missing(): void
    {
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/audit/aud_nope');
        $response->assertStatus(404);
        $this->assertSame('not_found', $response->json('error.code'));
    }

    public function test_show_404_when_cross_tenant(): void
    {
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        // aud_globex belongs to tenant globex.
        $response = $this->getJson('/api/admin/mcp-pack/audit/aud_globex');
        $response->assertStatus(404);
    }

    public function test_show_403_when_feature_disabled(): void
    {
        $this->bindBridge();
        $this->app['config']->set('mcp-pack.admin.features.audit_show', false);

        $response = $this->getJson('/api/admin/mcp-pack/audit/aud_001');
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    public function test_show_501_when_bridge_unwired(): void
    {
        $bridge = new FakeIdentityBridge();
        $bridge->forceNotImplemented = true;
        $this->app->instance(McpHostBridgeIdentityContract::class, $bridge);
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/audit/aud_001');
        $response->assertStatus(501);
        $this->assertSame('feature_not_implemented', $response->json('error.code'));
    }

    // ----- replay — mint path ----------------------------------------

    public function test_replay_first_post_mints_token(): void
    {
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/audit/aud_001/replay', []);
        $response->assertStatus(202);
        $token = $response->json('data.confirm_token');
        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^tok_[a-f0-9]{32}$/', $token);
        $this->assertGreaterThan(0, $response->json('data.replays_in_seconds'));
    }

    public function test_replay_mint_404_on_cross_tenant(): void
    {
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/audit/aud_globex/replay', []);
        $response->assertStatus(404);
    }

    public function test_replay_mint_404_on_missing(): void
    {
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/audit/aud_missing/replay', []);
        $response->assertStatus(404);
    }

    // ----- replay — consume path -------------------------------------

    public function test_replay_consume_happy_path(): void
    {
        $bridge = $this->bindBridge();
        $bridge->replayAuditResult = [
            'new_audit_id' => 'aud_002',
            'result' => ['hits' => 5],
            'latency_ms' => 87,
        ];
        InjectTenantMiddleware::$tenantId = 'acme';

        // Mint.
        $mint = $this->postJson('/api/admin/mcp-pack/audit/aud_001/replay', []);
        $token = $mint->json('data.confirm_token');

        // Consume.
        $consume = $this->postJson('/api/admin/mcp-pack/audit/aud_001/replay', [
            'confirm_token' => $token,
        ]);
        $consume->assertOk();
        $this->assertSame('aud_002', $consume->json('data.new_audit_id'));
        $this->assertSame([['aud_001', $token]], $bridge->replayAuditCalls);
    }

    public function test_replay_consume_422_on_token_reuse(): void
    {
        $bridge = $this->bindBridge();
        $bridge->replayAuditResult = ['new_audit_id' => 'aud_002', 'result' => []];
        InjectTenantMiddleware::$tenantId = 'acme';

        $mint = $this->postJson('/api/admin/mcp-pack/audit/aud_001/replay', []);
        $token = $mint->json('data.confirm_token');

        // First consume — success.
        $this->postJson('/api/admin/mcp-pack/audit/aud_001/replay', [
            'confirm_token' => $token,
        ])->assertOk();

        // Second consume — must be rejected.
        $response = $this->postJson('/api/admin/mcp-pack/audit/aud_001/replay', [
            'confirm_token' => $token,
        ]);
        $response->assertStatus(422);
        $this->assertSame('confirmation_invalid', $response->json('error.code'));
    }

    public function test_replay_consume_422_on_forged_token(): void
    {
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        // A well-formed token the package never minted.
        $forged = 'tok_' . str_repeat('a', 32);
        $response = $this->postJson('/api/admin/mcp-pack/audit/aud_001/replay', [
            'confirm_token' => $forged,
        ]);
        $response->assertStatus(422);
        $this->assertSame('confirmation_invalid', $response->json('error.code'));
    }

    public function test_replay_consume_422_on_malformed_token(): void
    {
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/audit/aud_001/replay', [
            'confirm_token' => 'not-a-real-token',
        ]);
        $response->assertStatus(422);
    }

    public function test_replay_consume_404_when_audit_disappears_between_mint_and_consume(): void
    {
        $bridge = $this->bindBridge();
        $bridge->replayAuditResult = ['new_audit_id' => 'aud_002', 'result' => []];
        InjectTenantMiddleware::$tenantId = 'acme';

        $mint = $this->postJson('/api/admin/mcp-pack/audit/aud_001/replay', []);
        $token = $mint->json('data.confirm_token');

        // Simulate a concurrent prune — the audit row is gone by the
        // time the consume call lands.
        $bridge->auditRows = [];

        $response = $this->postJson('/api/admin/mcp-pack/audit/aud_001/replay', [
            'confirm_token' => $token,
        ]);
        $response->assertStatus(404);
    }

    public function test_replay_403_when_feature_disabled(): void
    {
        $this->bindBridge();
        $this->app['config']->set('mcp-pack.admin.features.audit_replay', false);

        $response = $this->postJson('/api/admin/mcp-pack/audit/aud_001/replay', []);
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    public function test_replay_501_when_bridge_unwired(): void
    {
        $bridge = new FakeIdentityBridge();
        $bridge->forceNotImplemented = true;
        $this->app->instance(McpHostBridgeIdentityContract::class, $bridge);
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/audit/aud_001/replay', []);
        $response->assertStatus(501);
    }

    public function test_replay_route_stays_registered_when_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.audit_replay', false);
        $routes = $this->app['router']->getRoutes();
        $this->assertTrue($routes->hasNamedRoute('mcp-pack.admin.audit.replay'));
        $this->assertTrue($routes->hasNamedRoute('mcp-pack.admin.audit.show'));
    }
}
