<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AskMyDocsMcpPack\Models\McpToolCallAudit;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class AuditControllerTest extends TestCase
{
    use RefreshDatabase;

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

    private function seedAuditRow(array $overrides = []): McpToolCallAudit
    {
        return McpToolCallAudit::create(array_merge([
            'tenant_id' => 'acme',
            'actor' => 'user-1',
            'mcp_server_id' => 'srv-1',
            'mcp_server_name' => 'Alpha',
            'tool_name' => 'kb.search',
            'input_hash' => str_repeat('a', 64),
            'result_hash' => str_repeat('b', 64),
            'duration_ms' => 12,
            'status' => 'ok',
            'error_excerpt' => null,
        ], $overrides));
    }

    public function test_returns_paginated_rows_scoped_to_active_tenant(): void
    {
        $this->seedAuditRow(['tenant_id' => 'acme']);
        $this->seedAuditRow(['tenant_id' => 'acme', 'tool_name' => 'kb.write']);
        $this->seedAuditRow(['tenant_id' => 'globex']);
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/audit');
        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total'), 'only acme rows count');
        $this->assertSame('acme', $response->json('meta.tenant_id'));
        $tools = array_column($response->json('data'), 'tool_name');
        $this->assertEqualsCanonicalizing(['kb.search', 'kb.write'], $tools);
    }

    public function test_filters_by_tool_name_and_status(): void
    {
        $this->seedAuditRow(['tool_name' => 'kb.search', 'status' => 'ok']);
        $this->seedAuditRow(['tool_name' => 'kb.search', 'status' => 'error']);
        $this->seedAuditRow(['tool_name' => 'kb.write', 'status' => 'ok']);
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/audit?tool_name=kb.search&status=ok');
        $response->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('kb.search', $response->json('data.0.tool_name'));
        $this->assertSame('ok', $response->json('data.0.status'));
    }

    public function test_filters_by_server_id(): void
    {
        $this->seedAuditRow(['mcp_server_id' => 'srv-1']);
        $this->seedAuditRow(['mcp_server_id' => 'srv-2']);
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/audit?server_id=srv-2');
        $response->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('srv-2', $response->json('data.0.mcp_server_id'));
    }

    public function test_per_page_is_clamped_between_1_and_200(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedAuditRow();
        }
        InjectTenantMiddleware::$tenantId = 'acme';

        // Below the floor.
        $this->getJson('/api/admin/mcp-pack/audit?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);

        // Above the ceiling.
        $this->getJson('/api/admin/mcp-pack/audit?per_page=10000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 200);

        // Inside the band — verbatim.
        $this->getJson('/api/admin/mcp-pack/audit?per_page=3')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 3);
    }
}
