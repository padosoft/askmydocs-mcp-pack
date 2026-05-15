<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Services;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServer;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Models\McpToolCallAudit;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Services\McpToolCallingService;
use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostMessage;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeHostBridge;
use Padosoft\AskMyDocsMcpPack\Tests\Support\StubMcpTransport;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class McpToolCallingServiceTest extends TestCase
{
    private StubMcpTransport $transport;
    private FakeHostBridge $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = (new StubMcpTransport())
            ->scriptInitialize()
            ->scriptListTools([
                ['name' => 'kb_search', 'description' => 'Search KB', 'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]],
                ['name' => 'kb_doc',    'description' => 'Get doc',   'inputSchema' => ['type' => 'object', 'properties' => ['slug' => ['type' => 'string']]]],
            ])
            ->scriptToolCall('kb_search', ['hits' => [['id' => 1, 'title' => 'Doc A']]]);

        McpClient::useTransportResolver(fn() => $this->transport);

        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new InMemoryMcpServer(
            id: 'srv1',
            name: 'Local KB',
            transport: 'http',
            tenantId: 'acme',
            transportConfig: ['endpoint' => 'http://stub'],
        ));
        $this->app->instance(McpServerRegistryContract::class, $registry);

        $this->host = new FakeHostBridge();
        $this->app->instance(McpHostBridgeContract::class, $this->host);
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_short_circuits_when_config_kill_switch_is_off(): void
    {
        config()->set('mcp-pack.tool_calling.enabled', false);
        $this->host->script[] = new HostChatResponse('plain answer', []);

        $service = $this->app->make(McpToolCallingService::class);
        $response = $service->chatWithTools([HostMessage::user('hi')], tenantId: 'acme');

        $this->assertSame('plain answer', $response->content);
        $this->assertCount(1, $this->host->seenTurns);
        $this->assertSame([], $this->host->seenTurns[0]->tools, 'no tool catalog should be built when the kill-switch is off');
    }

    public function test_short_circuits_when_provider_lacks_tool_support(): void
    {
        $this->host->supportsTools = false;
        $this->host->script[] = new HostChatResponse('plain answer', []);

        $service = $this->app->make(McpToolCallingService::class);
        $response = $service->chatWithTools([HostMessage::user('hi')], tenantId: 'acme');

        $this->assertSame('plain answer', $response->content);
        // catalog was never built — only one round trip
        $this->assertCount(1, $this->host->seenTurns);
        $this->assertSame([], $this->host->seenTurns[0]->tools);
    }

    public function test_returns_directly_when_model_emits_no_tool_calls(): void
    {
        $this->host->script[] = new HostChatResponse('plain answer', []);

        $service = $this->app->make(McpToolCallingService::class);
        $response = $service->chatWithTools([HostMessage::user('hi')], tenantId: 'acme');

        $this->assertSame('plain answer', $response->content);
        $this->assertCount(2, $this->host->seenTurns[0]->tools, 'catalog of 2 tools should be in the first turn');
    }

    public function test_drives_one_tool_call_round_trip_and_persists_audit(): void
    {
        // turn 1: model wants to call kb_search; turn 2: model finalises.
        $this->host->script = [
            new HostChatResponse(
                content: null,
                toolCalls: [['id' => 'tc1', 'name' => 'kb_search', 'arguments' => ['q' => 'hello']]],
            ),
            new HostChatResponse(content: 'final grounded answer', toolCalls: []),
        ];

        $service = $this->app->make(McpToolCallingService::class);
        $response = $service->chatWithTools(
            [HostMessage::user('hi')],
            tenantId: 'acme',
            actor: 'user-1',
            context: ['conversation_id' => 42, 'message_id' => 7],
        );

        $this->assertSame('final grounded answer', $response->content);
        $this->assertCount(2, $this->host->seenTurns);
        $this->assertSame(1, McpToolCallAudit::query()->count());

        $row = McpToolCallAudit::query()->first();
        $this->assertSame('acme', $row->tenant_id);
        $this->assertSame('user-1', $row->actor);
        $this->assertSame('kb_search', $row->tool_name);
        $this->assertSame('ok', $row->status);
        $this->assertSame(42, $row->conversation_id);
        $this->assertSame(7, $row->message_id);
        $this->assertSame(64, strlen($row->input_hash));
        $this->assertSame(64, strlen($row->result_hash));
    }

    public function test_unknown_tool_returns_error_message_without_invocation(): void
    {
        $this->host->script = [
            new HostChatResponse(
                content: null,
                toolCalls: [['id' => 'tc1', 'name' => 'nope', 'arguments' => []]],
            ),
            new HostChatResponse(content: 'apology', toolCalls: []),
        ];

        $service = $this->app->make(McpToolCallingService::class);
        $service->chatWithTools([HostMessage::user('x')], tenantId: 'acme');

        // No audit row was written — the tool was rejected pre-dispatch.
        $this->assertSame(0, McpToolCallAudit::query()->count());

        // The second turn's last message must be a tool-role error blob.
        $messages = $this->host->seenTurns[1]->messages;
        $last = $messages[count($messages) - 1];
        $this->assertSame('tool', $last['role']);
        $this->assertStringContainsString('not configured for the current tenant', $last['content']);
    }

    public function test_stops_at_max_iterations(): void
    {
        // Model keeps asking for the same tool forever.
        $loopingResponse = new HostChatResponse(
            content: null,
            toolCalls: [['id' => 'tcX', 'name' => 'kb_search', 'arguments' => ['q' => 'loop']]],
        );
        // 3 iterations of the loop + 1 final "no budget" turn = 4 total host calls.
        $this->host->script = array_fill(0, 3, $loopingResponse);
        $this->host->script[] = new HostChatResponse(content: 'truncated', toolCalls: []);

        $service = $this->app->make(McpToolCallingService::class);
        $response = $service->chatWithTools([HostMessage::user('q')], tenantId: 'acme');

        $this->assertSame('truncated', $response->content);
        // 3 tool calls happened before the budget was exhausted.
        $this->assertSame(3, McpToolCallAudit::query()->count());
        $this->assertCount(4, $this->host->seenTurns);
        // Final turn had an empty tool catalog (no budget remains).
        $this->assertSame([], $this->host->seenTurns[3]->tools);
    }

    public function test_filters_allowed_tools(): void
    {
        // Restrict server to kb_doc only — kb_search must vanish from catalog.
        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new InMemoryMcpServer(
            id: 'srv1',
            name: 'Local KB',
            transport: 'http',
            tenantId: 'acme',
            transportConfig: ['endpoint' => 'http://stub'],
            allowedTools: ['kb_doc'],
        ));
        $this->app->instance(McpServerRegistryContract::class, $registry);

        $this->host->script[] = new HostChatResponse('done', []);

        $service = $this->app->make(McpToolCallingService::class);
        $service->chatWithTools([HostMessage::user('q')], tenantId: 'acme');

        $catalog = $this->host->seenTurns[0]->tools;
        $this->assertCount(1, $catalog);
        $this->assertSame('kb_doc', $catalog[0]->name());
    }
}
