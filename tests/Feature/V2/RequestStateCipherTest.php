<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\V2;

use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;
use Padosoft\AskMyDocsMcpPack\Protocol\RequestStateCipher;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

final class RequestStateCipherTest extends TestCase
{
    public function test_object_key_order_does_not_change_the_bound_request_digest(): void
    {
        $cipher = $this->app->make(RequestStateCipher::class);
        $request = new McpRequest('tools/call', [], 'acme', null, 'alice', [], [], []);
        $state = $cipher->issue($request, [
            'filters' => ['status' => 'open', 'range' => ['from' => 10, 'to' => 20]],
            'ids' => ['a', 'b'],
        ]);

        $payload = $cipher->consume($state, 'acme', 'alice', 'tools/call', [
            'ids' => ['a', 'b'],
            'filters' => ['range' => ['to' => 20, 'from' => 10], 'status' => 'open'],
        ]);

        $this->assertTrue($payload['singleUse']);
    }

    public function test_list_order_remains_significant(): void
    {
        $cipher = $this->app->make(RequestStateCipher::class);
        $request = new McpRequest('tools/call', [], 'acme', null, 'alice', [], [], []);
        $state = $cipher->issue($request, ['ids' => ['a', 'b']]);

        $this->expectException(\InvalidArgumentException::class);
        $cipher->consume($state, 'acme', 'alice', 'tools/call', ['ids' => ['b', 'a']]);
    }
}
