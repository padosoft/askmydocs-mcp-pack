<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Services;

use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServer;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Support\McpNegotiationResult;
use Padosoft\AskMyDocsMcpPack\Support\McpProtocolEra;
use Padosoft\AskMyDocsMcpPack\Tests\Support\StubMcpTransport;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class McpClientDualEraTest extends TestCase
{
    public function test_recent_modern_negotiation_can_be_reused_without_discovery(): void
    {
        $transport = new StubMcpTransport;
        $transport->scriptToolCall('search', ['structuredContent' => ['items' => []]]);
        $client = new McpClient($this->server(), $transport);

        $client->useNegotiation(new McpNegotiationResult(
            McpProtocolEra::Modern,
            McpClient::MODERN_PROTOCOL_VERSION,
            ['tools' => []],
            ['name' => 'cached-server'],
        ));
        $client->callToolResult('search', []);

        $this->assertSame(['tools/call'], array_map(
            static fn (JsonRpcMessage $message): ?string => $message->method,
            $transport->sentRequests,
        ));
        $this->assertSame(1, $client->physicalRequestCount());
    }

    public function test_modern_discovery_is_stateless_and_adds_per_request_meta(): void
    {
        $transport = new StubMcpTransport;
        $transport->responses['server/discover'] = [
            'protocolVersion' => McpClient::MODERN_PROTOCOL_VERSION,
            'capabilities' => ['tools' => ['listChanged' => true]],
            'serverInfo' => ['name' => 'modern-server'],
        ];
        $transport->scriptListTools([['name' => 'search']]);

        $client = new McpClient($this->server(), $transport);
        $negotiated = $client->negotiate();
        $tools = $client->listTools();

        $this->assertSame(McpProtocolEra::Modern, $negotiated->era);
        $this->assertSame('search', $tools[0]['name']);
        $this->assertArrayHasKey('_meta', $transport->sentRequests[1]->params ?? []);
        $this->assertSame(
            'padosoft/askmydocs-mcp-pack',
            $transport->sentRequests[1]->params['_meta']['io.modelcontextprotocol/clientInfo']['name'],
        );
        $this->assertArrayHasKey(
            'io.modelcontextprotocol/tasks',
            $transport->sentRequests[1]->params['_meta']['io.modelcontextprotocol/clientCapabilities']['extensions'],
        );
        $this->assertSame('padosoft/askmydocs-mcp-pack', $transport->sentRequests[0]->params['clientInfo']['name']);
        $this->assertSame('padosoft/askmydocs-mcp-pack', $transport->sentRequests[1]->params['clientInfo']['name']);
        $this->assertSame(['server/discover', 'tools/list'], array_map(
            static fn (JsonRpcMessage $message): ?string => $message->method,
            $transport->sentRequests,
        ));
        $this->assertSame(2, $client->physicalRequestCount());
    }

    public function test_method_not_found_falls_back_to_latest_legacy_initialize(): void
    {
        $transport = (new StubMcpTransport)
            ->scriptInitialize([
                'protocolVersion' => '2025-06-18',
                'capabilities' => ['tools' => []],
            ]);

        $client = new McpClient($this->server(), $transport);
        $negotiated = $client->negotiate();

        $this->assertSame(McpProtocolEra::Legacy, $negotiated->era);
        $this->assertSame('2025-06-18', $negotiated->protocolVersion);
        $this->assertSame(
            ['server/discover', 'initialize', 'notifications/initialized'],
            array_map(static fn (JsonRpcMessage $message): ?string => $message->method, $transport->sentRequests),
        );
        $this->assertSame('2025-11-25', $transport->sentRequests[1]->params['protocolVersion']);
    }

    public function test_transport_failure_never_triggers_legacy_fallback(): void
    {
        $transport = new class implements McpTransportContract
        {
            public int $requests = 0;

            public function request(JsonRpcMessage $request): JsonRpcMessage
            {
                $this->requests++;
                throw new McpTransportException('TLS verification failed');
            }

            public function notify(JsonRpcMessage $notification): void {}

            public function isHealthy(): bool
            {
                return false;
            }
        };

        $client = new McpClient($this->server(), $transport);

        try {
            $client->negotiate();
            $this->fail('Expected transport exception.');
        } catch (McpTransportException $e) {
            $this->assertSame('TLS verification failed', $e->getMessage());
            $this->assertSame(1, $transport->requests);
        }
    }

    public function test_transport_failure_during_first_legacy_revision_does_not_downgrade_again(): void
    {
        $transport = new class implements McpTransportContract
        {
            /** @var list<string> */
            public array $versions = [];

            public function request(JsonRpcMessage $request): JsonRpcMessage
            {
                if ($request->method === 'server/discover') {
                    return JsonRpcMessage::errorResponse($request->id, -32601, 'Method not found');
                }
                $this->versions[] = (string) ($request->params['protocolVersion'] ?? '');
                throw new McpTransportException('connection reset');
            }

            public function notify(JsonRpcMessage $notification): void {}

            public function isHealthy(): bool
            {
                return false;
            }
        };

        try {
            (new McpClient($this->server(), $transport))->negotiate();
            $this->fail('Expected transport failure.');
        } catch (McpTransportException $e) {
            $this->assertSame('connection reset', $e->getMessage());
            $this->assertSame(['2025-11-25'], $transport->versions);
        }
    }

    public function test_explicit_legacy_version_rejection_falls_back_from_2025_11_to_2025_06(): void
    {
        $transport = new class implements McpTransportContract
        {
            /** @var list<string> */
            public array $versions = [];

            public function request(JsonRpcMessage $request): JsonRpcMessage
            {
                if ($request->method === 'server/discover') {
                    return JsonRpcMessage::errorResponse($request->id, -32601, 'Method not found');
                }
                $version = (string) ($request->params['protocolVersion'] ?? '');
                $this->versions[] = $version;
                if ($version === '2025-11-25') {
                    return JsonRpcMessage::errorResponse($request->id, -32602, 'Unsupported protocol version');
                }

                return JsonRpcMessage::response($request->id, [
                    'protocolVersion' => '2025-06-18', 'capabilities' => [], 'serverInfo' => ['name' => 'legacy'],
                ]);
            }

            public function notify(JsonRpcMessage $notification): void {}

            public function isHealthy(): bool
            {
                return true;
            }
        };

        $negotiated = (new McpClient($this->server(), $transport))->negotiate();

        $this->assertSame('2025-06-18', $negotiated->protocolVersion);
        $this->assertSame(['2025-11-25', '2025-06-18'], $transport->versions);
    }

    public function test_legacy_server_may_select_the_oldest_supported_revision(): void
    {
        $transport = (new StubMcpTransport)->scriptInitialize([
            'protocolVersion' => '2024-10-07',
            'capabilities' => [],
        ]);

        $negotiated = (new McpClient($this->server(), $transport))->negotiate();

        $this->assertSame(McpProtocolEra::Legacy, $negotiated->era);
        $this->assertSame('2024-10-07', $negotiated->protocolVersion);
    }

    public function test_tool_result_preserves_every_wire_field(): void
    {
        $transport = new StubMcpTransport;
        $transport->responses['server/discover'] = ['protocolVersion' => McpClient::MODERN_PROTOCOL_VERSION];
        $transport->scriptToolCall('inspect', [
            'content' => [
                ['type' => 'text', 'text' => 'fresh'],
                ['type' => 'image', 'data' => 'abc', 'mimeType' => 'image/png'],
                ['type' => 'audio', 'data' => 'def', 'mimeType' => 'audio/mpeg'],
                ['type' => 'resource_link', 'uri' => 'https://example.test/r/1', 'name' => 'Report'],
                ['type' => 'resource', 'resource' => ['uri' => 'memory://1', 'text' => 'embedded']],
            ],
            'structuredContent' => ['answer' => 42],
            'isError' => false,
            '_meta' => ['ui' => ['resourceUri' => 'ui://widget/result.html']],
            'resultType' => 'input_required',
            'requestState' => 'opaque-state',
            'requests' => [['name' => 'approval']],
            'futureField' => ['kept' => true],
        ]);

        $result = (new McpClient($this->server(), $transport))->callToolResult('inspect', []);

        $this->assertCount(5, $result->content);
        $this->assertSame(['answer' => 42], $result->structuredContent);
        $this->assertSame('ui://widget/result.html', $result->meta['ui']['resourceUri']);
        $this->assertTrue($result->isInputRequired());
        $this->assertSame([['name' => 'approval']], $result->inputRequests);
        $this->assertSame(['kept' => true], $result->toArray()['futureField']);
    }

    public function test_modern_flat_and_pre_release_nested_tasks_share_one_stable_view(): void
    {
        $modern = new StubMcpTransport;
        $modern->responses['server/discover'] = ['protocolVersion' => McpClient::MODERN_PROTOCOL_VERSION];
        $modern->scriptToolCall('async', [
            'resultType' => 'task',
            'taskId' => 'task-modern',
            'status' => 'working',
            'ttlMs' => 60_000,
            'pollIntervalMs' => 250,
        ]);
        $modernTask = (new McpClient($this->server(), $modern))->task('async', [])->remoteTask();

        $nested = new StubMcpTransport;
        $nested->responses['server/discover'] = ['protocolVersion' => McpClient::MODERN_PROTOCOL_VERSION];
        $nested->scriptToolCall('async', [
            'resultType' => 'task',
            'task' => [
                'taskId' => 'task-nested',
                'status' => 'input_required',
                'ttl' => 30_000,
                'pollInterval' => 500,
                'inputRequests' => ['approval' => ['method' => 'elicitation/create']],
            ],
        ]);
        $nestedTask = (new McpClient($this->server(), $nested))->task('async', [])->remoteTask();

        $this->assertSame('task-modern', $modernTask?->taskId);
        $this->assertSame(250, $modernTask?->pollIntervalMs);
        $this->assertSame('task-nested', $nestedTask?->taskId);
        $this->assertTrue($nestedTask?->requiresInput());
        $this->assertSame(30_000, $nestedTask?->ttlMs);
    }

    private function server(): InMemoryMcpServer
    {
        return new InMemoryMcpServer(
            id: 'dual',
            name: 'Dual-era',
            transport: 'http',
            tenantId: 'tenant',
            transportConfig: ['endpoint' => 'https://example.test/mcp'],
        );
    }
}
