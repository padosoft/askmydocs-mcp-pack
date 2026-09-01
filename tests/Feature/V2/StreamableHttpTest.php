<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\V2;

use Padosoft\AskMyDocsMcpPack\Contracts\V2\SubscriptionBrokerContract;
use Padosoft\AskMyDocsMcpPack\Fluent\McpManager;
use Padosoft\AskMyDocsMcpPack\Fluent\Tool;
use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

final class StreamableHttpTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mcp-pack.server_side.http.enabled', true);
        $app['config']->set('mcp-pack.server_side.http.prefix', 'mcp');
        $app['config']->set('mcp-pack.server_side.http.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(McpManager::class)->server('default')
            ->tool(Tool::make('header-tool')->inputSchema(['type' => 'object', 'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => true]]])->handle(fn (array $arguments): array => $arguments))
            ->tool(Tool::make('progress-tool')->handle(function (McpRequest $request): string {
                $request->progress(1, 2, 'halfway');

                return 'done';
            }))
            ->register();
    }

    public function test_header_routing_is_required_and_mismatches_use_minus_32020(): void
    {
        $payload = $this->payload('server/discover');
        $this->postJson('/mcp', $payload, ['MCP-Protocol-Version' => '2026-07-28'])
            ->assertStatus(400)->assertJsonPath('error.code', -32020);

        $this->postJson('/mcp', $payload, ['MCP-Protocol-Version' => '2025-11-25', 'Mcp-Method' => 'server/discover'])
            ->assertStatus(400)->assertJsonPath('error.code', -32022);
    }

    public function test_x_mcp_header_arguments_are_validated_and_injected(): void
    {
        $payload = $this->payload('tools/call', ['name' => 'header-tool', 'arguments' => []]);
        $response = $this->postJson('/mcp', $payload, [
            'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'header-tool', 'Mcp-Param-Region' => 'eu-west',
        ])->assertOk();
        $response->assertJsonPath('result.structuredContent.region', 'eu-west');
    }

    public function test_sse_emits_progress_before_the_terminal_response(): void
    {
        $payload = $this->payload('tools/call', ['name' => 'progress-tool', 'arguments' => []]);
        $response = $this->postJson('/mcp', $payload, [
            'Accept' => 'text/event-stream', 'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'progress-tool',
        ])->assertOk();
        $stream = $response->streamedContent();

        $progress = strpos($stream, 'event: notifications/progress');
        $terminal = strpos($stream, 'event: message');
        $this->assertNotFalse($progress);
        $this->assertNotFalse($terminal);
        $this->assertLessThan($terminal, $progress);
        $this->assertStringContainsString('halfway', $stream);
    }

    public function test_wildcard_accept_header_does_not_implicitly_enable_sse(): void
    {
        $response = $this->postJson('/mcp', $this->payload('server/discover'), [
            'Accept' => '*/*',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'server/discover',
        ])->assertOk();

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_empty_subscription_poll_preserves_the_requested_cursor(): void
    {
        $broker = $this->app->make(SubscriptionBrokerContract::class);
        $broker->publish(null, 'notifications/resources/list_changed', ['revision' => 1]);
        $cursor = $broker->listen(null)[0]['id'];

        $this->postJson('/mcp', $this->payload('subscriptions/listen', ['after' => $cursor]), [
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'subscriptions/listen',
        ])->assertOk()
            ->assertJsonPath('result.events', [])
            ->assertJsonPath('result.lastEventId', $cursor);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function payload(string $method, array $params = []): array
    {
        $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => []];

        return ['jsonrpc' => '2.0', 'id' => 'http-test', 'method' => $method, 'params' => $params];
    }
}
