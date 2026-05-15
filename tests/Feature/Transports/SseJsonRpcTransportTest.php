<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Transports;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;
use Padosoft\AskMyDocsMcpPack\Transports\SseJsonRpcTransport;

class SseJsonRpcTransportTest extends TestCase
{
    public function test_request_returns_final_frame_when_intermediate_notifications_precede(): void
    {
        $body = "data: " . json_encode(['jsonrpc' => '2.0', 'method' => 'progress', 'params' => ['pct' => 10]]) . "\n\n"
              . "data: " . json_encode(['jsonrpc' => '2.0', 'method' => 'progress', 'params' => ['pct' => 50]]) . "\n\n"
              . "data: " . json_encode(['jsonrpc' => '2.0', 'id' => 'rpc_x', 'result' => ['ok' => true]]) . "\n\n";

        Http::fake([
            'sse.example.test/stream' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $transport = new SseJsonRpcTransport(['endpoint' => 'http://sse.example.test/stream']);
        $response = $transport->request(JsonRpcMessage::request('rpc_x', 'tools/call'));

        $this->assertTrue($response->isResponse());
        $this->assertSame(['ok' => true], $response->result);
    }

    public function test_request_falls_back_to_last_response_shaped_frame_when_no_id_match(): void
    {
        // Server emits a final frame whose id differs (e.g. broken
        // proxy renumbering). The transport must still return a
        // structurally-valid JSON-RPC envelope rather than throwing.
        $body = "data: " . json_encode(['jsonrpc' => '2.0', 'id' => 'unexpected', 'result' => ['ok' => 'last']]) . "\n\n";

        Http::fake([
            'sse.example.test/stream' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $transport = new SseJsonRpcTransport(['endpoint' => 'http://sse.example.test/stream']);
        $response = $transport->request(JsonRpcMessage::request('rpc_x', 'tools/call'));

        $this->assertSame(['ok' => 'last'], $response->result);
    }

    public function test_request_throws_when_event_stream_carries_no_jsonrpc_frame(): void
    {
        Http::fake([
            'sse.example.test/stream' => Http::response("data: not json\n\n", 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $transport = new SseJsonRpcTransport(['endpoint' => 'http://sse.example.test/stream']);

        $this->expectException(McpTransportException::class);
        $transport->request(JsonRpcMessage::request('rpc_x', 'tools/call'));
    }

    public function test_request_throws_when_only_notifications_arrive_no_response_frame(): void
    {
        // Pure-notification stream — no frame carries `result`/`error`
        // (only `method` + no `id`). The transport must NOT mistake a
        // notification for the response and must surface the protocol
        // violation as a transport error.
        $body = "data: " . json_encode(['jsonrpc' => '2.0', 'method' => 'progress', 'params' => ['pct' => 10]]) . "\n\n"
              . "data: " . json_encode(['jsonrpc' => '2.0', 'method' => 'log', 'params' => ['msg' => 'tick']]) . "\n\n";

        Http::fake([
            'sse.example.test/stream' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $transport = new SseJsonRpcTransport(['endpoint' => 'http://sse.example.test/stream']);

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessageMatches('/no matching JSON-RPC response frame/');
        $transport->request(JsonRpcMessage::request('rpc_x', 'tools/call'));
    }

    public function test_request_throws_on_non_2xx(): void
    {
        Http::fake([
            'sse.example.test/stream' => Http::response('gateway down', 502),
        ]);

        $transport = new SseJsonRpcTransport(['endpoint' => 'http://sse.example.test/stream']);

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessageMatches('/SSE MCP transport returned status 502/');
        $transport->request(JsonRpcMessage::request(1, 'tools/list'));
    }

    public function test_request_rejects_non_request_messages(): void
    {
        $transport = new SseJsonRpcTransport(['endpoint' => 'http://sse.example.test/stream']);

        $this->expectException(\InvalidArgumentException::class);
        $transport->request(JsonRpcMessage::notification('progress'));
    }

    public function test_is_healthy_hits_health_path(): void
    {
        Http::fake([
            'sse.example.test/stream/healthz' => Http::response('ok', 200),
        ]);

        $transport = new SseJsonRpcTransport([
            'endpoint' => 'http://sse.example.test/stream',
            'health_path' => '/healthz',
        ]);

        $this->assertTrue($transport->isHealthy());
    }

    public function test_request_handles_multi_line_data_fields(): void
    {
        // Per the SSE spec a multi-line `data:` field is concatenated
        // with \n separators when the JSON payload is split across
        // physical lines (some gateways pretty-print).
        $body = "data: {\"jsonrpc\":\"2.0\",\n" .
                "data:  \"id\":\"rpc_a\",\n" .
                "data:  \"result\":{\"ok\":true}}\n\n";

        Http::fake([
            'sse.example.test/stream' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $transport = new SseJsonRpcTransport(['endpoint' => 'http://sse.example.test/stream']);
        $response = $transport->request(JsonRpcMessage::request('rpc_a', 'tools/list'));

        $this->assertSame(['ok' => true], $response->result);
    }
}
