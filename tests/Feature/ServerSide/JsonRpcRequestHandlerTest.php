<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\ServerSide;

use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpToolAuthorizer;
use Padosoft\AskMyDocsMcpPack\ServerSide\JsonRpcRequestHandler;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Tests\Support\AnonymousTool;
use Padosoft\AskMyDocsMcpPack\Tests\Support\InMemoryServerExposure;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class JsonRpcRequestHandlerTest extends TestCase
{
    public function test_initialize_returns_server_info_and_capabilities(): void
    {
        $handler = $this->handler(new InMemoryServerExposure(
            serverInfoData: ['name' => 'askmydocs-test', 'version' => '0.0.1'],
            capabilitiesData: ['tools' => new \stdClass(), 'resources' => new \stdClass()],
        ));

        $response = $handler->handle(JsonRpcMessage::request(1, 'initialize'));

        $this->assertNotNull($response);
        $this->assertSame('askmydocs-test', $response->result['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $response->result['capabilities']);
        $this->assertArrayHasKey('resources', $response->result['capabilities']);
        $this->assertSame('2025-03-26', $response->result['protocolVersion']);
    }

    public function test_tools_list_returns_exposed_tools_filtered_by_authorizer(): void
    {
        $exposure = new InMemoryServerExposure(
            toolList: [
                new AnonymousTool('public_tool', fn() => 'ok', 'Public tool'),
                new AnonymousTool('private_tool', fn() => 'ok', 'Private tool'),
            ],
        );
        $authorizer = new class implements \Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract {
            public function authorize(mixed $actor, ?string $tenantId, \Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract $tool): bool
            {
                return $tool->name() !== 'private_tool';
            }
        };
        $handler = new JsonRpcRequestHandler($exposure, $authorizer);

        $response = $handler->handle(JsonRpcMessage::request(2, 'tools/list'));
        $names = array_column($response->result['tools'], 'name');

        $this->assertSame(['public_tool'], $names);
    }

    public function test_tools_call_invokes_tool_and_normalises_string_result(): void
    {
        $handler = $this->handler(new InMemoryServerExposure(toolList: [
            new AnonymousTool('echo', fn(array $args) => 'echo:' . ($args['msg'] ?? '')),
        ]));

        $response = $handler->handle(
            JsonRpcMessage::request(3, 'tools/call', [
                'name' => 'echo',
                'arguments' => ['msg' => 'hello'],
            ]),
        );

        $this->assertSame([['type' => 'text', 'text' => 'echo:hello']], $response->result['content']);
    }

    public function test_tools_call_unknown_tool_returns_domain_error(): void
    {
        $handler = $this->handler(new InMemoryServerExposure());

        $response = $handler->handle(JsonRpcMessage::request(4, 'tools/call', ['name' => 'nope']));

        $this->assertTrue($response->isError());
        $this->assertSame(-32001, $response->error['code']);
        $this->assertStringContainsString('not exposed for the current tenant', $response->error['message']);
    }

    public function test_tools_call_missing_name_returns_invalid_params_error(): void
    {
        $handler = $this->handler(new InMemoryServerExposure());

        $response = $handler->handle(JsonRpcMessage::request(5, 'tools/call', []));

        $this->assertTrue($response->isError());
        $this->assertSame(-32602, $response->error['code']);
    }

    public function test_unknown_method_returns_method_not_found(): void
    {
        $handler = $this->handler(new InMemoryServerExposure());

        $response = $handler->handle(JsonRpcMessage::request(6, 'unknown/method'));

        $this->assertTrue($response->isError());
        $this->assertSame(-32601, $response->error['code']);
    }

    public function test_notification_returns_null_no_response(): void
    {
        $handler = $this->handler(new InMemoryServerExposure());

        $response = $handler->handle(JsonRpcMessage::notification('notifications/initialized'));

        $this->assertNull($response);
    }

    public function test_tools_list_empty_when_exposure_publishes_nothing(): void
    {
        $handler = $this->handler(new InMemoryServerExposure());

        $response = $handler->handle(JsonRpcMessage::request(7, 'tools/list'));

        $this->assertSame([], $response->result['tools']);
    }

    private function handler(InMemoryServerExposure $exposure): JsonRpcRequestHandler
    {
        return new JsonRpcRequestHandler($exposure, new NullMcpToolAuthorizer());
    }
}
