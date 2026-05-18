<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeIdentityBridge;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class ResourcesControllerTest extends TestCase
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
        $bridge->resources = [
            'srv_01' => [
                ['uri' => 'mcp://openai/docs/', 'name' => 'docs/', 'type' => 'dir'],
                [
                    'uri' => 'mcp://openai/docs/readme.md',
                    'name' => 'readme.md',
                    'type' => 'file',
                    'mime' => 'text/markdown',
                    'size' => 4128,
                    'parent' => 'mcp://openai/docs/',
                ],
            ],
            'srv_globex' => [
                ['uri' => 'mcp://globex/secret.md', 'name' => 'secret.md', 'type' => 'file'],
            ],
        ];
        $bridge->resourceTenants = [
            'srv_01' => 'acme',
            'srv_globex' => 'globex',
        ];
        $bridge->resourceContents = [
            'srv_01' => [
                'mcp://openai/docs/readme.md' => [
                    'uri' => 'mcp://openai/docs/readme.md',
                    'name' => 'readme.md',
                    'mime' => 'text/markdown',
                    'size' => 4128,
                    'content' => '# OpenAI MCP — Quick start',
                ],
            ],
            'srv_globex' => [
                'mcp://globex/secret.md' => [
                    'uri' => 'mcp://globex/secret.md',
                    'name' => 'secret.md',
                    'mime' => 'text/markdown',
                    'size' => 12,
                    'content' => 'globex stuff',
                ],
            ],
        ];
        $this->app->instance(McpHostBridgeIdentityContract::class, $bridge);
        return $bridge;
    }

    // ----- list ------------------------------------------------------

    public function test_index_returns_resource_tree(): void
    {
        $bridge = $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_01/resources');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame('docs/', $response->json('data.0.name'));
        $this->assertSame('text/markdown', $response->json('data.1.mime'));
        $this->assertSame('srv_01', $response->json('meta.server_id'));
        $this->assertSame('acme', $response->json('meta.tenant_id'));
        $this->assertSame(2, $response->json('meta.total'));
        // R30: bridge received the trusted tenant id.
        $this->assertSame([['srv_01', 'acme']], $bridge->listResourcesCalls);
    }

    public function test_index_returns_empty_data_for_cross_tenant_server(): void
    {
        $this->bindBridge();
        // Actor is `acme`, the server belongs to `globex` — the
        // bridge returns [] (no leakage). The controller answers 200
        // with an empty list, NOT a 404 (mirrors R30 — we don't even
        // confirm the server exists).
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_globex/resources');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_index_returns_501_when_host_does_not_implement(): void
    {
        $bridge = $this->bindBridge();
        $bridge->forceNotImplemented = true;

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_01/resources');
        $response->assertStatus(501);
        $this->assertSame('feature_not_implemented', $response->json('error.code'));
    }

    public function test_index_returns_403_when_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.resources', false);
        $this->bindBridge();

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_01/resources');
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    // ----- content ----------------------------------------------------

    public function test_show_returns_resource_content(): void
    {
        $bridge = $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';
        $encoded = rawurlencode('mcp://openai/docs/readme.md');

        $response = $this->getJson("/api/admin/mcp-pack/servers/srv_01/resources/{$encoded}");

        $response->assertOk();
        $this->assertSame('readme.md', $response->json('data.name'));
        $this->assertSame('text/markdown', $response->json('data.mime'));
        $this->assertSame('# OpenAI MCP — Quick start', $response->json('data.content'));
        $this->assertSame('srv_01', $response->json('meta.server_id'));
        // R19: the bridge received the DECODED uri exactly once.
        $this->assertSame(
            [['srv_01', 'mcp://openai/docs/readme.md', 'acme']],
            $bridge->resourceContentCalls,
        );
    }

    public function test_show_returns_404_when_resource_missing(): void
    {
        $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';
        $encoded = rawurlencode('mcp://openai/docs/missing.md');

        $response = $this->getJson("/api/admin/mcp-pack/servers/srv_01/resources/{$encoded}");
        $response->assertStatus(404);
        $this->assertSame('not_found', $response->json('error.code'));
    }

    public function test_show_returns_404_for_cross_tenant_resource(): void
    {
        $this->bindBridge();
        // Actor is `acme`, the server + resource belong to `globex`.
        // The bridge surfaces null (R30) so the controller answers
        // 404 — same response shape as "doesn't exist".
        InjectTenantMiddleware::$tenantId = 'acme';
        $encoded = rawurlencode('mcp://globex/secret.md');

        $response = $this->getJson("/api/admin/mcp-pack/servers/srv_globex/resources/{$encoded}");
        $response->assertStatus(404);
        $this->assertSame('not_found', $response->json('error.code'));
    }

    public function test_show_returns_501_when_host_does_not_implement(): void
    {
        $bridge = $this->bindBridge();
        $bridge->forceNotImplemented = true;
        $encoded = rawurlencode('mcp://openai/docs/readme.md');

        $response = $this->getJson("/api/admin/mcp-pack/servers/srv_01/resources/{$encoded}");
        $response->assertStatus(501);
        $this->assertSame('feature_not_implemented', $response->json('error.code'));
    }

    public function test_show_returns_403_when_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.resources', false);
        $this->bindBridge();
        $encoded = rawurlencode('mcp://openai/docs/readme.md');

        $response = $this->getJson("/api/admin/mcp-pack/servers/srv_01/resources/{$encoded}");
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    public function test_index_returns_empty_array_when_bridge_has_no_data(): void
    {
        $bridge = new FakeIdentityBridge();
        // No resources data at all (the default).
        $this->app->instance(McpHostBridgeIdentityContract::class, $bridge);

        $response = $this->getJson('/api/admin/mcp-pack/servers/srv_unknown/resources');
        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_index_forwards_null_tenant_when_no_middleware_attribute(): void
    {
        $bridge = $this->bindBridge();
        // No InjectTenantMiddleware::$tenantId set — controller
        // passes null to the bridge (platform-global view).
        $this->getJson('/api/admin/mcp-pack/servers/srv_01/resources')->assertOk();
        $this->assertSame([['srv_01', null]], $bridge->listResourcesCalls);
    }

    public function test_show_decodes_the_uri_exactly_once_before_calling_the_bridge(): void
    {
        $bridge = $this->bindBridge();
        InjectTenantMiddleware::$tenantId = 'acme';
        // Add an entry with a URI that contains spaces and `:` so we
        // can verify the decoded value matches.
        $uri = 'mcp://openai/docs/with spaces.md';
        $bridge->resourceContents['srv_01'][$uri] = [
            'uri' => $uri,
            'name' => 'with spaces.md',
            'mime' => 'text/markdown',
            'size' => 8,
            'content' => 'hi',
        ];
        $encoded = rawurlencode($uri);

        $this->getJson("/api/admin/mcp-pack/servers/srv_01/resources/{$encoded}")->assertOk();
        // Last call's URI argument equals the decoded literal.
        $lastCall = end($bridge->resourceContentCalls);
        $this->assertSame($uri, $lastCall[1]);
    }
}
