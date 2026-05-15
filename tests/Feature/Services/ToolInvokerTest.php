<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Services;

use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServer;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Models\McpToolCallAudit;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Services\ToolInvoker;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Tests\Support\StubMcpTransport;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class ToolInvokerTest extends TestCase
{
    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_invoke_persists_ok_audit_with_hashes(): void
    {
        $transport = (new StubMcpTransport())
            ->scriptToolCall('kb_search', ['hits' => [['title' => 'Doc']]]);

        McpClient::useTransportResolver(fn() => $transport);

        $invoker = new ToolInvoker();
        $result = $invoker->invoke(
            server: $this->server(),
            toolName: 'kb_search',
            arguments: ['q' => 'hello'],
            context: ['tenant_id' => 'acme', 'actor' => 'alice', 'conversation_id' => 1, 'message_id' => 2],
        );

        $this->assertFalse($result->isError());
        $this->assertSame(['hits' => [['title' => 'Doc']]], $result->result);

        $row = McpToolCallAudit::query()->first();
        $this->assertSame('acme', $row->tenant_id);
        $this->assertSame('alice', $row->actor);
        $this->assertSame('ok', $row->status);
        $this->assertNull($row->error_excerpt);
        $this->assertSame(64, strlen($row->input_hash));
        $this->assertSame(64, strlen($row->result_hash));
    }

    public function test_invoke_records_transport_error_branch(): void
    {
        $transport = new StubMcpTransport(); // no scripts
        $transport->responses['tools/call:boom'] = JsonRpcMessage::errorResponse('x', -32000, 'Server died');
        McpClient::useTransportResolver(fn() => $transport);

        $invoker = new ToolInvoker();
        $result = $invoker->invoke(
            server: $this->server(),
            toolName: 'boom',
            arguments: ['x' => 1],
        );

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Server died', $result->error);

        $row = McpToolCallAudit::query()->first();
        $this->assertSame('transport_error', $row->status);
        $this->assertNull($row->result_hash);
        $this->assertNotNull($row->error_excerpt);
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
