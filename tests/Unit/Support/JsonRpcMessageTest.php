<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

class JsonRpcMessageTest extends TestCase
{
    public function test_request_envelope(): void
    {
        $msg = JsonRpcMessage::request(1, 'tools/list');

        $this->assertTrue($msg->isRequest());
        $this->assertFalse($msg->isNotification());
        $this->assertFalse($msg->isResponse());
        $this->assertFalse($msg->isError());
        $this->assertSame('2.0', JsonRpcMessage::VERSION);
        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $msg->toArray());
    }

    public function test_notification_envelope_has_no_id(): void
    {
        $msg = JsonRpcMessage::notification('progress', ['pct' => 50]);

        $this->assertTrue($msg->isNotification());
        $this->assertFalse($msg->isRequest());
        $payload = $msg->toArray();
        $this->assertArrayNotHasKey('id', $payload);
        $this->assertSame('progress', $payload['method']);
        $this->assertSame(['pct' => 50], $payload['params']);
    }

    public function test_response_carries_result(): void
    {
        $msg = JsonRpcMessage::response('rpc_1', ['tools' => []]);

        $this->assertTrue($msg->isResponse());
        $this->assertSame(['tools' => []], $msg->toArray()['result']);
    }

    public function test_error_response_omits_result(): void
    {
        $msg = JsonRpcMessage::errorResponse(2, -32601, 'Method not found');

        $this->assertTrue($msg->isError());
        $payload = $msg->toArray();
        $this->assertArrayNotHasKey('result', $payload);
        $this->assertSame(-32601, $payload['error']['code']);
        $this->assertSame('Method not found', $payload['error']['message']);
    }

    public function test_round_trip_through_array(): void
    {
        $original = JsonRpcMessage::request('rpc_abc', 'tools/call', ['name' => 'x', 'arguments' => ['k' => 1]]);
        $hydrated = JsonRpcMessage::fromArray($original->toArray());

        $this->assertSame($original->id, $hydrated->id);
        $this->assertSame($original->method, $hydrated->method);
        $this->assertSame($original->params, $hydrated->params);
    }
}
