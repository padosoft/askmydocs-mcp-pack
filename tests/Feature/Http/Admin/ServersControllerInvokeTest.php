<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerMutableRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpToolNotAuthorizedException;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;
use Padosoft\AskMyDocsMcpPack\Services\ToolInvoker;
use Padosoft\AskMyDocsMcpPack\Support\ToolCallResult;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeMcpServer;
use Padosoft\AskMyDocsMcpPack\Tests\Support\StubHandshakeService;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class ServersControllerInvokeTest extends TestCase
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

    private function bootHandshake(array $tools): StubHandshakeService
    {
        $stub = new StubHandshakeService();
        $stub->payload = [
            'capabilities' => ['tools' => []],
            'tools' => $tools,
        ];
        $this->app->instance(McpHandshakeService::class, $stub);
        return $stub;
    }

    private function bootInvoker(): FakeToolInvoker
    {
        $invoker = new FakeToolInvoker();
        $this->app->instance(ToolInvoker::class, $invoker);
        return $invoker;
    }

    public function test_invoke_happy_path_returns_200_with_result(): void
    {
        $this->bootRegistry();
        $this->bootHandshake([['name' => 'kb.search']]);
        $invoker = $this->bootInvoker();
        $invoker->result = ['hits' => 3];
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-a/tools/kb.search/invoke', [
            'arguments' => ['q' => 'hello'],
        ]);

        $response->assertOk();
        $this->assertSame(['hits' => 3], $response->json('data.result'));
        $this->assertIsInt($response->json('data.latency_ms'));
        $this->assertSame('kb.search', $invoker->lastToolName);
        $this->assertSame(['q' => 'hello'], $invoker->lastArguments);
        $this->assertSame('acme', $invoker->lastContext['tenant_id']);
    }

    public function test_invoke_404_when_cross_tenant(): void
    {
        $this->bootRegistry();
        $this->bootHandshake([]);
        $this->bootInvoker();
        InjectTenantMiddleware::$tenantId = 'acme';

        // srv-b belongs to globex.
        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-b/tools/kb.search/invoke', [
            'arguments' => [],
        ]);
        $response->assertStatus(404);
    }

    public function test_invoke_422_confirmation_required_on_destructive_tool(): void
    {
        $this->bootRegistry();
        $this->bootHandshake([['name' => 'kb.delete', 'destructive' => true]]);
        $this->bootInvoker();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-a/tools/kb.delete/invoke', [
            'arguments' => ['id' => 1],
        ]);
        $response->assertStatus(422);
        $this->assertSame('confirmation_required', $response->json('error.code'));
    }

    public function test_invoke_destructive_tool_proceeds_with_confirm_true(): void
    {
        $this->bootRegistry();
        $this->bootHandshake([['name' => 'kb.delete', 'destructive' => true]]);
        $invoker = $this->bootInvoker();
        $invoker->result = ['deleted' => true];
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-a/tools/kb.delete/invoke', [
            'arguments' => ['id' => 1],
            'confirm' => true,
        ]);
        $response->assertOk();
        $this->assertSame(['deleted' => true], $response->json('data.result'));
    }

    public function test_invoke_502_on_transport_exception(): void
    {
        $this->bootRegistry();
        $this->bootHandshake([['name' => 'kb.search']]);
        $invoker = $this->bootInvoker();
        $invoker->throw = new McpTransportException('connection refused');
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-a/tools/kb.search/invoke', [
            'arguments' => [],
        ]);
        $response->assertStatus(502);
        $this->assertSame('transport_error', $response->json('error.code'));
    }

    public function test_invoke_403_on_not_authorized_exception(): void
    {
        $this->bootRegistry();
        $this->bootHandshake([['name' => 'kb.search']]);
        $invoker = $this->bootInvoker();
        $invoker->throw = new McpToolNotAuthorizedException('denied');
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-a/tools/kb.search/invoke', [
            'arguments' => [],
        ]);
        $response->assertStatus(403);
        $this->assertSame('not_authorized', $response->json('error.code'));
    }

    public function test_invoke_502_when_tool_call_result_carries_error(): void
    {
        // R14: ToolInvoker catches errors into ToolCallResult; the
        // controller MUST surface the error as a non-200 status.
        $this->bootRegistry();
        $this->bootHandshake([['name' => 'kb.search']]);
        $invoker = $this->bootInvoker();
        $invoker->error = 'upstream returned 503';
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-a/tools/kb.search/invoke', [
            'arguments' => [],
        ]);
        $response->assertStatus(502);
        $this->assertSame('transport_error', $response->json('error.code'));
        $this->assertStringContainsString('503', $response->json('error.message'));
    }

    public function test_invoke_403_when_feature_disabled(): void
    {
        $this->bootRegistry();
        $this->bootHandshake([]);
        $this->bootInvoker();
        $this->app['config']->set('mcp-pack.admin.features.tool_invoke', false);

        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-a/tools/kb.search/invoke', [
            'arguments' => [],
        ]);
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    public function test_invoke_route_stays_registered_when_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.tool_invoke', false);
        $routes = $this->app['router']->getRoutes();
        $this->assertTrue($routes->hasNamedRoute('mcp-pack.admin.servers.tools.invoke'));
    }

    public function test_invoke_422_on_missing_arguments(): void
    {
        $this->bootRegistry();
        $this->bootHandshake([]);
        $this->bootInvoker();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-a/tools/kb.search/invoke', []);
        $response->assertStatus(422);
    }

    public function test_invoke_422_on_control_char_in_arguments(): void
    {
        $this->bootRegistry();
        $this->bootHandshake([['name' => 'kb.search']]);
        $this->bootInvoker();
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-a/tools/kb.search/invoke', [
            'arguments' => ['q' => "evil\x00null"],
        ]);
        $response->assertStatus(422);
    }

    public function test_invoke_422_when_arguments_nest_too_deep(): void
    {
        $this->bootRegistry();
        $this->bootHandshake([['name' => 'kb.search']]);
        $this->bootInvoker();
        InjectTenantMiddleware::$tenantId = 'acme';

        // Build a 9-level deep payload (cap is 8).
        $deep = 'leaf';
        for ($i = 0; $i < 9; $i++) {
            $deep = ['nested' => $deep];
        }
        $response = $this->postJson('/api/admin/mcp-pack/servers/srv-a/tools/kb.search/invoke', [
            'arguments' => $deep,
        ]);
        $response->assertStatus(422);
    }
}

/**
 * Stub {@see ToolInvoker} for tests. Setting `$throw` makes
 * `invoke()` rethrow the configured exception; setting `$result`
 * makes it return a successful `ToolCallResult`; setting `$error`
 * makes it return a `ToolCallResult` with an error message (the
 * R14 "captured-into-result" path).
 */
class FakeToolInvoker extends ToolInvoker
{
    public mixed $result = null;

    public ?string $error = null;

    public ?\Throwable $throw = null;

    public ?string $lastToolName = null;

    /** @var array<string,mixed> */
    public array $lastArguments = [];

    /** @var array<string,mixed> */
    public array $lastContext = [];

    public function __construct()
    {
        parent::__construct(resilience: null);
    }

    public function invoke(
        McpServerContract $server,
        string $toolName,
        array $arguments,
        array $context = [],
    ): ToolCallResult {
        $this->lastToolName = $toolName;
        $this->lastArguments = $arguments;
        $this->lastContext = $context;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new ToolCallResult(
            toolCallId: 'tool_test',
            toolName: $toolName,
            result: $this->result,
            error: $this->error,
            latencyMs: 12.5,
        );
    }
}
