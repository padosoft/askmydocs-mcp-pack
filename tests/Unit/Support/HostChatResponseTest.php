<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;
use Padosoft\AskMyDocsMcpPack\Support\HostMessage;
use Padosoft\AskMyDocsMcpPack\Support\ToolCallResult;

class HostChatResponseTest extends TestCase
{
    public function test_has_tool_calls_returns_false_on_empty_array(): void
    {
        $response = new HostChatResponse(content: 'hello', toolCalls: []);

        $this->assertFalse($response->hasToolCalls());
    }

    public function test_has_tool_calls_returns_true_when_populated(): void
    {
        $response = new HostChatResponse(
            content: null,
            toolCalls: [['id' => 'tool_1', 'name' => 'kb_search', 'arguments' => ['q' => 'x']]],
        );

        $this->assertTrue($response->hasToolCalls());
    }

    public function test_host_chat_turn_carries_messages_and_tools(): void
    {
        $turn = new HostChatTurn(
            messages: [HostMessage::user('hi')],
            tools: [],
            tenantId: 'acme',
            extras: ['temperature' => 0.0],
        );

        $this->assertSame('acme', $turn->tenantId);
        $this->assertSame(0.0, $turn->extras['temperature']);
        $this->assertSame('user', $turn->messages[0]['role']);
    }

    public function test_tool_call_result_serialises_error_branch(): void
    {
        $result = new ToolCallResult('tc_1', 'kb_search', null, 'denied', 12.5);

        $this->assertTrue($result->isError());
        $payload = json_decode($result->toMessagePayload(), true);
        $this->assertSame(['error' => 'denied'], $payload);
    }

    public function test_tool_call_result_serialises_success_branch(): void
    {
        $result = new ToolCallResult('tc_2', 'kb_search', ['answer' => 'ok'], null, 8.0);

        $this->assertFalse($result->isError());
        $payload = json_decode($result->toMessagePayload(), true);
        $this->assertSame(['result' => ['answer' => 'ok']], $payload);
    }
}
