<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeMcpServer;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class ToolsControllerTest extends TestCase
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

    /**
     * Boot a per-server handshake stub that knows how to return a
     * different tools list per server id. The real `McpHandshakeService`
     * binding is replaced by a custom subclass that branches on the
     * server's id.
     *
     * @param array<string,array<int,array<string,mixed>>> $byServer
     */
    private function bootMultiServerHandshake(array $byServer): void
    {
        $stub = new MultiServerHandshakeStub($byServer);
        $this->app->instance(McpHandshakeService::class, $stub);
    }

    private function bootRegistry(): InMemoryMcpServerRegistry
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new FakeMcpServer(id: 'srv-a', name: 'Alpha', tenantId: 'acme'));
        $registry->add(new FakeMcpServer(id: 'srv-b', name: 'Beta', tenantId: 'acme'));
        $this->app->instance(McpServerRegistryContract::class, $registry);
        return $registry;
    }

    public function test_index_returns_flat_aggregated_tools_across_servers(): void
    {
        $this->bootRegistry();
        InjectTenantMiddleware::$tenantId = 'acme';
        $this->bootMultiServerHandshake([
            'srv-a' => [
                ['name' => 'search', 'description' => 'web search', 'destructive' => false],
                ['name' => 'create_issue', 'description' => 'opens an issue', 'destructive' => true],
            ],
            'srv-b' => [
                ['name' => 'post_message', 'description' => 'post to slack', 'destructive' => true],
            ],
        ]);

        $response = $this->getJson('/api/admin/mcp-pack/tools');
        $response->assertOk();

        $rows = $response->json('data');
        $this->assertCount(3, $rows);

        $byKey = array_column($rows, null, 'name');
        $this->assertSame('srv-a', $byKey['search']['server_id']);
        $this->assertSame('Alpha', $byKey['search']['server_name']);
        $this->assertSame('web search', $byKey['search']['desc']);
        $this->assertFalse($byKey['search']['destructive']);

        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.server_count'));
    }

    public function test_index_dedupes_by_server_id_and_tool_name(): void
    {
        $this->bootRegistry();
        InjectTenantMiddleware::$tenantId = 'acme';
        $this->bootMultiServerHandshake([
            'srv-a' => [
                ['name' => 'search', 'description' => 'first'],
                ['name' => 'search', 'description' => 'dup'], // same (server, tool) — dropped
            ],
            'srv-b' => [
                ['name' => 'search', 'description' => 'different server, same name OK'],
            ],
        ]);

        $response = $this->getJson('/api/admin/mcp-pack/tools');
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_filters_by_destructive_true(): void
    {
        $this->bootRegistry();
        InjectTenantMiddleware::$tenantId = 'acme';
        $this->bootMultiServerHandshake([
            'srv-a' => [
                ['name' => 'search', 'destructive' => false],
                ['name' => 'create_issue', 'destructive' => true],
            ],
            'srv-b' => [
                ['name' => 'list_channels', 'destructive' => false],
            ],
        ]);

        $response = $this->getJson('/api/admin/mcp-pack/tools?destructive=true');
        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['create_issue'], $names);
    }

    public function test_index_filters_by_destructive_false(): void
    {
        $this->bootRegistry();
        InjectTenantMiddleware::$tenantId = 'acme';
        $this->bootMultiServerHandshake([
            'srv-a' => [
                ['name' => 'search', 'destructive' => false],
                ['name' => 'create_issue', 'destructive' => true],
            ],
            'srv-b' => [],
        ]);

        $response = $this->getJson('/api/admin/mcp-pack/tools?destructive=false');
        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['search'], $names);
    }

    public function test_index_filters_by_q_substring_against_name_and_desc(): void
    {
        $this->bootRegistry();
        InjectTenantMiddleware::$tenantId = 'acme';
        $this->bootMultiServerHandshake([
            'srv-a' => [
                ['name' => 'search', 'description' => 'web SEARCH'],
                ['name' => 'merge_pr', 'description' => 'merge a PR'],
            ],
            'srv-b' => [],
        ]);

        $response = $this->getJson('/api/admin/mcp-pack/tools?q=merge');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('merge_pr', $response->json('data.0.name'));
    }

    public function test_index_filters_by_server_id(): void
    {
        $this->bootRegistry();
        InjectTenantMiddleware::$tenantId = 'acme';
        $this->bootMultiServerHandshake([
            'srv-a' => [['name' => 'search']],
            'srv-b' => [['name' => 'post_message']],
        ]);

        $response = $this->getJson('/api/admin/mcp-pack/tools?server_id=srv-b');
        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('srv-b', $rows[0]['server_id']);
    }

    public function test_index_returns_403_when_feature_disabled(): void
    {
        $this->bootRegistry();
        $this->app['config']->set('mcp-pack.admin.features.tools', false);

        $response = $this->getJson('/api/admin/mcp-pack/tools');
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    public function test_index_records_unreachable_servers_in_meta(): void
    {
        $this->bootRegistry();
        InjectTenantMiddleware::$tenantId = 'acme';
        $this->bootMultiServerHandshake([
            'srv-a' => [['name' => 'search']],
            // srv-b throws via the special sentinel
            'srv-b' => '__THROW__',
        ]);

        $response = $this->getJson('/api/admin/mcp-pack/tools');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(['srv-b'], $response->json('meta.unreachable_servers'));
    }

    public function test_index_honours_allowed_tools_when_configured(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new FakeMcpServer(
            id: 'srv-scoped',
            tenantId: 'acme',
            allowedTools: ['search'],
        ));
        $this->app->instance(McpServerRegistryContract::class, $registry);
        InjectTenantMiddleware::$tenantId = 'acme';
        $this->bootMultiServerHandshake([
            'srv-scoped' => [
                ['name' => 'search'],
                ['name' => 'write_back'],
            ],
        ]);

        $response = $this->getJson('/api/admin/mcp-pack/tools');
        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['search'], $names);
    }

    public function test_index_classifies_destructive_by_name_when_metadata_missing(): void
    {
        $this->bootRegistry();
        InjectTenantMiddleware::$tenantId = 'acme';
        $this->bootMultiServerHandshake([
            'srv-a' => [
                ['name' => 'list_channels'],
                ['name' => 'delete_channel'],
            ],
            'srv-b' => [],
        ]);

        $response = $this->getJson('/api/admin/mcp-pack/tools');
        $response->assertOk();
        $rows = $response->json('data');
        $byName = array_column($rows, null, 'name');
        $this->assertFalse($byName['list_channels']['destructive']);
        $this->assertTrue($byName['delete_channel']['destructive']);
    }
}

/**
 * Multi-server handshake stub: returns a different tools list per
 * server id. The string sentinel `'__THROW__'` opts the server into
 * raising `McpTransportException` so the unreachable-servers
 * meta-block path can be exercised.
 */
class MultiServerHandshakeStub extends McpHandshakeService
{
    /** @param array<string,array<int,array<string,mixed>>|string> $byServer */
    public function __construct(private array $byServer)
    {
        parent::__construct(ttlSeconds: 0);
    }

    public function peek(\Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract $server): ?array
    {
        return null;
    }

    public function refresh(\Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract $server, bool $force = false): array
    {
        $entry = $this->byServer[$server->id()] ?? [];
        if ($entry === '__THROW__') {
            throw new \Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException(
                "handshake failed for [{$server->id()}]",
            );
        }
        return [
            'capabilities' => ['tools' => []],
            'tools' => $entry,
        ];
    }
}
