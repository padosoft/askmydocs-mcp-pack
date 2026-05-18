<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeIdentityBridge;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class PromptsControllerTest extends TestCase
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
        $bridge->prompts = [
            'srv_01' => [
                [
                    'name' => 'research_brief',
                    'desc' => 'Generate a research brief on a topic',
                    'args' => [
                        ['name' => 'topic', 'type' => 'string', 'required' => true],
                    ],
                    'preview' => [
                        ['role' => 'system', 'text' => 'You are a senior research analyst.'],
                    ],
                ],
            ],
            'srv_globex' => [
                ['name' => 'leak', 'desc' => 'globex internal', 'args' => [], 'preview' => []],
            ],
        ];
        $bridge->promptTenants = [
            'srv_01' => 'acme',
            'srv_globex' => 'globex',
        ];
        $bridge->promptDetails = [
            'srv_01' => [
                'research_brief' => [
                    'name' => 'research_brief',
                    'desc' => 'Generate a research brief on a topic',
                    'args' => [
                        ['name' => 'topic', 'type' => 'string', 'required' => true],
                    ],
                    'preview' => [
                        ['role' => 'system', 'text' => 'You are a senior research analyst.'],
                    ],
                ],
            ],
            'srv_globex' => [
                'leak' => ['name' => 'leak', 'desc' => 'globex internal', 'args' => [], 'preview' => []],
            ],
        ];
        $this->app->instance(McpHostBridgeIdentityContract::class, $bridge);
        return $bridge;
    }

    // ----- list ------------------------------------------------------

    public function test_index_returns_prompt_catalog(): void
    {
        $bridge = $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_01/prompts');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('research_brief', $response->json('data.0.name'));
        $this->assertSame('srv_01', $response->json('meta.server_id'));
        $this->assertSame('acme', $response->json('meta.tenant_id'));
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame([['srv_01', 'acme']], $bridge->listPromptsCalls);
    }

    public function test_index_returns_empty_data_for_cross_tenant_server(): void
    {
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_globex/prompts');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_index_returns_501_when_host_does_not_implement(): void
    {
        $bridge = $this->bindBridge();
        $bridge->forceNotImplemented = true;

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_01/prompts');
        $response->assertStatus(501);
        $this->assertSame('feature_not_implemented', $response->json('error.code'));
    }

    public function test_index_returns_403_when_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.prompts', false);
        $this->bindBridge();

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_01/prompts');
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    // ----- detail ----------------------------------------------------

    public function test_show_returns_prompt_detail(): void
    {
        $bridge = $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_01/prompts/research_brief');

        $response->assertOk();
        $this->assertSame('research_brief', $response->json('data.name'));
        $this->assertNotEmpty($response->json('data.args'));
        $this->assertSame('srv_01', $response->json('meta.server_id'));
        $this->assertSame(
            [['srv_01', 'research_brief', 'acme']],
            $bridge->promptDetailCalls,
        );
    }

    public function test_show_returns_404_when_prompt_missing(): void
    {
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_01/prompts/nope');
        $response->assertStatus(404);
        $this->assertSame('not_found', $response->json('error.code'));
    }

    public function test_show_returns_404_for_cross_tenant_prompt(): void
    {
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_globex/prompts/leak');
        $response->assertStatus(404);
        $this->assertSame('not_found', $response->json('error.code'));
    }

    public function test_show_returns_501_when_host_does_not_implement(): void
    {
        $bridge = $this->bindBridge();
        $bridge->forceNotImplemented = true;

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_01/prompts/research_brief');
        $response->assertStatus(501);
        $this->assertSame('feature_not_implemented', $response->json('error.code'));
    }

    public function test_show_returns_403_when_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.prompts', false);
        $this->bindBridge();

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_01/prompts/research_brief');
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    public function test_index_returns_empty_array_when_bridge_has_no_data(): void
    {
        $bridge = new FakeIdentityBridge();
        $this->app->instance(McpHostBridgeIdentityContract::class, $bridge);

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_nada/prompts');
        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
    }
}
