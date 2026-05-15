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

    public function test_list_resources_returns_catalog(): void
    {
        $transport = new StubMcpTransport();
        $transport->responses['resources/list'] = [
            'resources' => [
                ['uri' => 'file:///kb/doc-1.md', 'name' => 'Doc 1', 'mimeType' => 'text/markdown'],
                ['uri' => 'file:///kb/doc-2.md', 'name' => 'Doc 2', 'mimeType' => 'text/markdown'],
            ],
        ];
        McpClient::useTransportResolver(fn () => $transport);

        $resources = McpClient::forServer($this->server())->listResources();

        $this->assertCount(2, $resources);
        $this->assertSame('file:///kb/doc-1.md', $resources[0]['uri']);
        $this->assertSame('Doc 1', $resources[0]['name']);
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

        $resources = McpClient::forServer($this->server())->listResources();

        $this->assertCount(1, $resources);
        $this->assertSame('file:///valid.md', $resources[0]['uri']);
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

    public function test_list_prompts_returns_catalog(): void
    {
        $transport = new StubMcpTransport();
        $transport->responses['prompts/list'] = [
            'prompts' => [
                ['name' => 'kb_explain', 'description' => 'Explain a KB doc'],
                ['name' => 'audit_summary', 'description' => 'Summarise the audit log'],
            ],
        ];
        McpClient::useTransportResolver(fn () => $transport);

        $prompts = McpClient::forServer($this->server())->listPrompts();

        $this->assertCount(2, $prompts);
        $this->assertSame(['kb_explain', 'audit_summary'], array_column($prompts, 'name'));
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

        $this->assertSame([], McpClient::forServer($this->server())->listResources());
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
