<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Transports;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Support\McpProtocolEra;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;
use Padosoft\AskMyDocsMcpPack\Transports\HttpJsonRpcTransport;

class HttpJsonRpcTransportTest extends TestCase
{
    public function test_request_round_trip_parses_response(): void
    {
        Http::fake([
            'gateway.example.test/rpc' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 'rpc_1',
                'result' => ['ok' => true],
            ], 200),
        ]);

        $transport = new HttpJsonRpcTransport([
            'endpoint' => 'http://gateway.example.test/rpc',
            'headers' => ['Authorization' => 'Bearer test'],
            'timeout_ms' => 2_000,
        ]);

        $response = $transport->request(JsonRpcMessage::request('rpc_1', 'tools/list'));

        $this->assertTrue($response->isResponse());
        $this->assertSame(['ok' => true], $response->result);
    }

    public function test_request_throws_on_non_2xx(): void
    {
        Http::fake([
            'gateway.example.test/rpc' => Http::response('boom', 503),
        ]);

        $transport = new HttpJsonRpcTransport(['endpoint' => 'http://gateway.example.test/rpc']);

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessageMatches('/HTTP MCP transport returned status 503/');
        $transport->request(JsonRpcMessage::request(1, 'tools/list'));
    }

    public function test_request_throws_on_non_json_payload(): void
    {
        Http::fake([
            'gateway.example.test/rpc' => Http::response('plain text', 200),
        ]);

        $transport = new HttpJsonRpcTransport(['endpoint' => 'http://gateway.example.test/rpc']);

        $this->expectException(McpTransportException::class);
        $transport->request(JsonRpcMessage::request(1, 'tools/list'));
    }

    public function test_response_size_is_bounded_and_non_json_errors_do_not_echo_the_body(): void
    {
        Http::fakeSequence()
            ->push(str_repeat('x', 33), 200)
            ->push('secret-upstream-detail', 503);

        $transport = new HttpJsonRpcTransport([
            'endpoint' => 'http://gateway.example.test/rpc',
            'max_response_bytes' => 32,
        ]);
        try {
            $transport->request(JsonRpcMessage::request(1, 'tools/list'));
            $this->fail('Expected the response size guard.');
        } catch (McpTransportException $e) {
            $this->assertStringContainsString('size limit', $e->getMessage());
        }

        $transport = new HttpJsonRpcTransport(['endpoint' => 'http://gateway.example.test/rpc']);
        try {
            $transport->request(JsonRpcMessage::request(2, 'tools/list'));
            $this->fail('Expected the upstream status failure.');
        } catch (McpTransportException $e) {
            $this->assertStringContainsString('status 503', $e->getMessage());
            $this->assertStringNotContainsString('secret-upstream-detail', $e->getMessage());
        }
    }

    public function test_is_healthy_hits_health_path(): void
    {
        Http::fake([
            'gateway.example.test/rpc/healthz' => Http::response('ok', 200),
        ]);

        $transport = new HttpJsonRpcTransport([
            'endpoint' => 'http://gateway.example.test/rpc',
            'health_path' => '/healthz',
        ]);

        $this->assertTrue($transport->isHealthy());
    }

    public function test_request_rejects_non_request_messages(): void
    {
        $transport = new HttpJsonRpcTransport(['endpoint' => 'http://stub']);

        $this->expectException(\InvalidArgumentException::class);
        $transport->request(JsonRpcMessage::notification('progress'));
    }

    public function test_modern_headers_and_legacy_session_are_exposed(): void
    {
        Http::fakeSequence()
            ->push([
                'jsonrpc' => '2.0',
                'id' => 'modern',
                'result' => ['ok' => true],
            ], 200)
            ->push([
                'jsonrpc' => '2.0',
                'id' => 'task',
                'result' => ['resultType' => 'complete', 'taskId' => 'task-123', 'status' => 'working', 'ttlMs' => 60_000],
            ], 200)
            ->push([
                'jsonrpc' => '2.0',
                'id' => 'init',
                'result' => ['protocolVersion' => '2025-11-25'],
            ], 200, ['Mcp-Session-Id' => 'session-123'])
            ->push([
                'jsonrpc' => '2.0',
                'id' => 'list',
                'result' => ['tools' => []],
            ], 200);

        $transport = new HttpJsonRpcTransport(['endpoint' => 'https://gateway.example.test/mcp']);
        $transport->useProtocol(McpProtocolEra::Modern, '2026-07-28');
        $transport->request(JsonRpcMessage::request('modern', 'tools/call', ['name' => 'search']));

        Http::assertSent(static fn ($request): bool => $request->header('MCP-Protocol-Version') === ['2026-07-28']
            && $request->header('Mcp-Method') === ['tools/call']
            && $request->header('Mcp-Name') === ['search']);

        $transport->request(JsonRpcMessage::request('task', 'tasks/get', ['taskId' => 'task-123']));
        Http::assertSent(static fn ($request): bool => $request->header('Mcp-Method') === ['tasks/get']
            && $request->header('Mcp-Name') === ['task-123']);

        $transport->useProtocol(McpProtocolEra::Legacy, '2025-11-25');
        $transport->request(JsonRpcMessage::request('init', 'initialize'));
        $transport->request(JsonRpcMessage::request('list', 'tools/list'));

        $this->assertSame('session-123', $transport->sessionId());
        $this->assertSame(200, $transport->lastStatusCode());
        Http::assertSent(static fn ($request): bool => $request->header('Mcp-Session-Id') === ['session-123']);
    }
}
