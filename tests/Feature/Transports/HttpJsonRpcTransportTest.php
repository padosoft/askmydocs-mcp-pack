<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Transports;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
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
}
