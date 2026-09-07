<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Transports;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Support\McpProtocolEra;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;
use Padosoft\AskMyDocsMcpPack\Transports\LegacySseJsonRpcTransport;

class LegacySseJsonRpcTransportTest extends TestCase
{
    public function test_it_reads_the_advertised_post_endpoint_and_response_from_the_get_stream(): void
    {
        $stream = "event: endpoint\ndata: /messages?session=abc\n\n"
            .'data: '.json_encode(['jsonrpc' => '2.0', 'id' => 'rpc_1', 'result' => ['ok' => true]])."\n\n";
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 'session-1'], $stream),
            new Response(202),
        ]));
        $stack->push(Middleware::history($history));

        $transport = new LegacySseJsonRpcTransport([
            'endpoint' => 'https://mcp.example.test/sse',
            'client' => new Client(['handler' => $stack]),
        ]);
        $transport->useProtocol(McpProtocolEra::Legacy, '2024-10-07');

        $response = $transport->request(JsonRpcMessage::request('rpc_1', 'tools/list'));

        $this->assertSame(['ok' => true], $response->result);
        $this->assertCount(2, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('https://mcp.example.test/messages?session=abc', (string) $history[1]['request']->getUri());
        $this->assertSame('2024-10-07', $history[1]['request']->getHeaderLine('MCP-Protocol-Version'));
        $this->assertSame('session-1', $history[1]['request']->getHeaderLine('Mcp-Session-Id'));
    }

    public function test_it_rejects_a_cross_origin_advertised_message_endpoint(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], "event: endpoint\ndata: https://evil.example/messages\n\n"),
        ]));
        $transport = new LegacySseJsonRpcTransport([
            'endpoint' => 'https://mcp.example.test/sse',
            'client' => new Client(['handler' => $stack]),
        ]);

        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessage('must remain on the stream origin');

        $transport->request(JsonRpcMessage::request('rpc_1', 'tools/list'));
    }
}
