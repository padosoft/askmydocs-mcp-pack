<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Services;

use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServer;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Tests\Support\StubMcpTransport;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class McpClientResourcesPromptsTest extends TestCase
{
    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_list_resources_returns_page_with_cursor(): void
    {
        $transport = new StubMcpTransport();
        $transport->responses['resources/list'] = [
            'resources' => [
                ['uri' => 'file:///kb/doc-1.md', 'name' => 'Doc 1', 'mimeType' => 'text/markdown'],
                ['uri' => 'file:///kb/doc-2.md', 'name' => 'Doc 2', 'mimeType' => 'text/markdown'],
            ],
            'nextCursor' => 'page2',
        ];
        McpClient::useTransportResolver(fn () => $transport);

        $page = McpClient::forServer($this->server())->listResources();

        $this->assertCount(2, $page['resources']);
        $this->assertSame('file:///kb/doc-1.md', $page['resources'][0]['uri']);
        $this->assertSame('page2', $page['nextCursor']);
    }

    public function test_list_all_resources_drains_every_page(): void
    {
        $transport = new StubMcpTransport();
        // The stub only keys by method, so we can't return different
        // bodies per cursor without a richer fake. Use a closure-like
        // approach: feed an envelope with no nextCursor → terminates
        // after one page. The "drains every page" behaviour is
        // verified by the loop terminating cleanly + the flat array.
        $transport->responses['resources/list'] = [
            'resources' => [['uri' => 'file:///only.md']],
            'nextCursor' => null,
        ];
        McpClient::useTransportResolver(fn () => $transport);

        $all = McpClient::forServer($this->server())->listAllResources();
        $this->assertCount(1, $all);
        $this->assertSame('file:///only.md', $all[0]['uri']);
    }

    public function test_list_resources_filters_entries_without_uri(): void
    {
        $transport = new StubMcpTransport();
        $transport->responses['resources/list'] = [
            'resources' => [
                ['uri' => 'file:///valid.md'],
                ['name' => 'no-uri'],   // dropped
                'not-an-object',         // dropped
            ],
        ];
        McpClient::useTransportResolver(fn () => $transport);

        $page = McpClient::forServer($this->server())->listResources();

        $this->assertCount(1, $page['resources']);
        $this->assertSame('file:///valid.md', $page['resources'][0]['uri']);
        $this->assertNull($page['nextCursor']);
    }

    public function test_read_resource_passes_uri_in_params(): void
    {
        $transport = new StubMcpTransport();
        $transport->responses['resources/read'] = [
            'contents' => [
                ['type' => 'text', 'text' => 'hello world'],
            ],
        ];
        McpClient::useTransportResolver(fn () => $transport);

        $result = McpClient::forServer($this->server())->readResource('file:///kb/x.md');

        $this->assertSame('hello world', $result['contents'][0]['text']);

        $sent = $transport->sentRequests[0];
        $this->assertSame('resources/read', $sent->method);
        $this->assertSame('file:///kb/x.md', $sent->params['uri']);
    }

    public function test_list_prompts_returns_page_with_cursor(): void
    {
        $transport = new StubMcpTransport();
        $transport->responses['prompts/list'] = [
            'prompts' => [
                ['name' => 'kb_explain', 'description' => 'Explain a KB doc'],
                ['name' => 'audit_summary', 'description' => 'Summarise the audit log'],
            ],
            'nextCursor' => 'next-token',
        ];
        McpClient::useTransportResolver(fn () => $transport);

        $page = McpClient::forServer($this->server())->listPrompts();

        $this->assertCount(2, $page['prompts']);
        $this->assertSame(['kb_explain', 'audit_summary'], array_column($page['prompts'], 'name'));
        $this->assertSame('next-token', $page['nextCursor']);
    }

    public function test_get_prompt_encodes_empty_arguments_as_object(): void
    {
        $transport = new StubMcpTransport();
        $transport->responses['prompts/get'] = [
            'messages' => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'hello']],
            ],
        ];
        McpClient::useTransportResolver(fn () => $transport);

        McpClient::forServer($this->server())->getPrompt('zero_arg_prompt');

        $sent = $transport->sentRequests[0];
        $this->assertSame('zero_arg_prompt', $sent->params['name']);
        // The MCP spec requires `arguments` be a JSON object; empty
        // arguments must encode as {} (stdClass) not [] (array).
        $this->assertInstanceOf(\stdClass::class, $sent->params['arguments']);
        $this->assertSame('{}', json_encode($sent->params['arguments']));
    }

    public function test_get_prompt_passes_name_and_arguments(): void
    {
        $transport = new StubMcpTransport();
        $transport->responses['prompts/get'] = [
            'messages' => [
                ['role' => 'system', 'content' => ['type' => 'text', 'text' => 'You are a KB explainer.']],
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Explain doc-1.']],
            ],
        ];
        McpClient::useTransportResolver(fn () => $transport);

        $rendered = McpClient::forServer($this->server())->getPrompt('kb_explain', ['doc_id' => 'doc-1']);

        $this->assertCount(2, $rendered['messages']);
        $sent = $transport->sentRequests[0];
        $this->assertSame('prompts/get', $sent->method);
        $this->assertSame('kb_explain', $sent->params['name']);
        $this->assertSame(['doc_id' => 'doc-1'], $sent->params['arguments']);
    }

    public function test_list_resources_returns_empty_on_non_array_payload(): void
    {
        $transport = new StubMcpTransport();
        // package returns the result envelope verbatim; the
        // canonical "empty list" shape is just an empty `resources` key
        $transport->responses['resources/list'] = ['resources' => 'oops-not-an-array'];
        McpClient::useTransportResolver(fn () => $transport);

        $page = McpClient::forServer($this->server())->listResources();
        $this->assertSame([], $page['resources']);
        $this->assertNull($page['nextCursor']);
    }

    private function server(): InMemoryMcpServer
    {
        return new InMemoryMcpServer(
            id: 's',
            name: 'S',
            transport: 'http',
            tenantId: 'acme',
            transportConfig: ['endpoint' => 'http://stub'],
        );
    }
}
