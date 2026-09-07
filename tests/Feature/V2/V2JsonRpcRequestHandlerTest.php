<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\V2;

use Padosoft\AskMyDocsMcpPack\Fluent\App;
use Padosoft\AskMyDocsMcpPack\Fluent\McpManager;
use Padosoft\AskMyDocsMcpPack\Fluent\Prompt;
use Padosoft\AskMyDocsMcpPack\Fluent\Resource;
use Padosoft\AskMyDocsMcpPack\Fluent\Tool;
use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;
use Padosoft\AskMyDocsMcpPack\ServerSide\V2JsonRpcRequestHandler;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

final class V2JsonRpcRequestHandlerTest extends TestCase
{
    private V2JsonRpcRequestHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(McpManager::class)->server('default')->name('AskMyDocs')->version('2.0.0')
            ->tool(Tool::make('echo')->inputSchema(['type' => 'object', 'required' => ['value'], 'properties' => ['value' => ['type' => 'string']]])->outputSchema(['type' => 'object', 'properties' => ['echo' => ['type' => 'string']]])->readOnly()->idempotent()->app('ui://docs/viewer')->handle(fn (McpRequest $request): array => ['echo' => $request->argument('value')]))
            ->tool(Tool::make('zeta')->handle(fn (): string => 'z'))
            ->resource(Resource::make('docs://guide')->mimeType('text/markdown')->handle(fn () => '# Guide'))
            ->prompt(Prompt::make('summarize')->handle(fn (array $args): array => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Summarize '.($args['topic'] ?? '')]]]))
            ->app(App::make('viewer')->resource('ui://docs/viewer')->html('<main>safe</main>')->csp(['connectDomains' => []])->permissions('clipboard-write'))
            ->register();
        $this->handler = $this->app->make(V2JsonRpcRequestHandler::class);
    }

    public function test_discovery_and_every_result_include_v2_metadata(): void
    {
        $response = $this->rpc('server/discover');
        $this->assertSame('2026-07-28', $response['protocolVersion']);
        $this->assertSame('complete', $response['resultType']);
        $this->assertSame('AskMyDocs', $response['serverInfo']['name']);
        $this->assertArrayHasKey('io.modelcontextprotocol/ui', $response['capabilities']['extensions']);
    }

    public function test_local_server_alias_resolves_to_its_compiled_definition(): void
    {
        $this->app->make(McpManager::class)->local('docs-alias', 'default');
        $params = ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ]];
        $response = $this->handler->handle(JsonRpcMessage::request('alias', 'server/discover', $params), ['server_id' => 'docs-alias']);

        $this->assertFalse($response->isError());
        $this->assertSame('AskMyDocs', $response->result['serverInfo']['name']);
    }

    public function test_tool_schema_errors_are_recoverable_and_valid_calls_are_structured(): void
    {
        $invalid = $this->rpc('tools/call', ['name' => 'echo', 'arguments' => ['value' => 42]]);
        $this->assertTrue($invalid['isError']);
        $this->assertSame('complete', $invalid['resultType']);

        $valid = $this->rpc('tools/call', ['name' => 'echo', 'arguments' => ['value' => 'hello']]);
        $this->assertSame(['echo' => 'hello'], $valid['structuredContent']);
        $this->assertSame('complete', $valid['resultType']);
    }

    public function test_app_resource_uses_mcp_app_mime_and_deny_by_default_metadata(): void
    {
        $listed = $this->rpc('resources/list');
        $result = $this->rpc('resources/read', ['uri' => 'ui://docs/viewer']);
        $appResource = collect($listed['resources'])->firstWhere('uri', 'ui://docs/viewer');
        $this->assertSame([], $appResource['_meta']['ui']['csp']['resourceDomains']);
        $this->assertInstanceOf(\stdClass::class, $appResource['_meta']['ui']['permissions']['clipboardWrite']);
        $this->assertSame('text/html;profile=mcp-app', $result['contents'][0]['mimeType']);
        $this->assertSame('<main>safe</main>', $result['contents'][0]['text']);
        $this->assertSame([], $result['contents'][0]['_meta']['ui']['csp']['connectDomains']);
    }

    public function test_missing_namespaced_meta_is_a_protocol_error(): void
    {
        $message = JsonRpcMessage::request('x', 'server/discover', []);
        $response = $this->handler->handle($message, ['server_id' => 'default']);
        $this->assertSame(-32022, $response->error['code']);
    }

    public function test_tasks_are_not_advertised_until_operational_and_enabled(): void
    {
        config()->set('mcp-pack.tasks.enabled', false);
        $disabled = $this->rpc('server/discover');
        $this->assertArrayNotHasKey('io.modelcontextprotocol/tasks', $disabled['capabilities']['extensions']);
        $this->assertNotContains('tasks/get', $disabled['methods']);

        config()->set('mcp-pack.tasks.enabled', true);
        $enabled = $this->rpc('server/discover');
        $this->assertArrayHasKey('io.modelcontextprotocol/tasks', $enabled['capabilities']['extensions']);
        $this->assertContains('tasks/get', $enabled['methods']);
    }

    public function test_ui_resource_is_not_exposed_without_ui_capability(): void
    {
        $listParams = ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ]];
        $listed = $this->handler->handle(JsonRpcMessage::request('no-ui-list', 'resources/list', $listParams), ['server_id' => 'default']);
        $this->assertNotContains('ui://docs/viewer', array_column($listed->result['resources'], 'uri'));

        $params = ['uri' => 'ui://docs/viewer', '_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ]];
        $response = $this->handler->handle(JsonRpcMessage::request('no-ui', 'resources/read', $params), ['server_id' => 'default']);
        $this->assertSame(-32021, $response->error['code']);
    }

    public function test_lists_are_deterministically_paginated_with_signed_snapshot_cursors_and_cache_hints(): void
    {
        $first = $this->rpc('tools/list', ['limit' => 1]);
        $this->assertSame(['echo'], array_column($first['tools'], 'name'));
        $this->assertIsString($first['nextCursor']);
        $this->assertSame(300000, $first['ttlMs']);
        $this->assertSame('private', $first['cacheScope']);

        $second = $this->rpc('tools/list', ['limit' => 1, 'cursor' => $first['nextCursor']]);
        $this->assertSame(['zeta'], array_column($second['tools'], 'name'));
        $this->assertArrayNotHasKey('nextCursor', $second);

        $params = ['cursor' => $first['nextCursor'].'x', '_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ]];
        $tampered = $this->handler->handle(JsonRpcMessage::request('bad-cursor', 'tools/list', $params), ['server_id' => 'default']);
        $this->assertSame(-32602, $tampered->error['code']);
    }

    public function test_tool_app_metadata_has_standard_visibility_and_openai_alias(): void
    {
        $listed = $this->rpc('tools/list');
        $echo = collect($listed['tools'])->firstWhere('name', 'echo');

        $this->assertSame(['model', 'app'], $echo['_meta']['ui']['visibility']);
        $this->assertSame('ui://docs/viewer', $echo['_meta']['ui']['resourceUri']);
        $this->assertSame('ui://docs/viewer', $echo['_meta']['openai/outputTemplate']);
    }

    public function test_missing_task_and_artifact_are_recoverable_without_cross_scope_disclosure(): void
    {
        config()->set('mcp-pack.tasks.enabled', true);

        $task = $this->rpc('tasks/get', ['taskId' => '00000000-0000-4000-8000-000000000000']);
        $artifact = $this->rpc('resources/read', ['uri' => 'artifact://00000000-0000-4000-8000-000000000000']);

        $this->assertTrue($task['isError']);
        $this->assertTrue($artifact['isError']);
        $this->assertSame('complete', $task['resultType']);
        $this->assertSame('complete', $artifact['resultType']);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function rpc(string $method, array $params = []): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => ['extensions' => [
                'io.modelcontextprotocol/ui' => new \stdClass,
                'io.modelcontextprotocol/tasks' => new \stdClass,
            ]],
            'clientInfo' => ['name' => 'tests', 'version' => '1'],
        ];
        $response = $this->handler->handle(JsonRpcMessage::request('test', $method, $params), ['server_id' => 'default', 'tenant_id' => 'acme', 'principal_id' => 'alice']);
        $this->assertFalse($response->isError(), json_encode($response->error));

        return $response->result;
    }
}
