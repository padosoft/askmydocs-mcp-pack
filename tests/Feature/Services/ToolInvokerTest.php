<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Services;

use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServer;
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
        $transport = (new StubMcpTransport)
            ->scriptToolCall('kb_search', ['hits' => [['title' => 'Doc']]]);
        $this->scriptModernDiscovery($transport);

        McpClient::useTransportResolver(fn () => $transport);

        $invoker = new ToolInvoker;
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
        $transport = new StubMcpTransport; // no scripts
        $this->scriptModernDiscovery($transport);
        $transport->responses['tools/call:boom'] = JsonRpcMessage::errorResponse('x', -32000, 'Server died');
        McpClient::useTransportResolver(fn () => $transport);

        $invoker = new ToolInvoker;
        $result = $invoker->invoke(
            server: $this->server(),
            toolName: 'boom',
            arguments: ['x' => 1],
        );

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Server died', $result->error);

        $row = McpToolCallAudit::query()->first();
        $this->assertSame('error', $row->status);
        $this->assertNull($row->result_hash);
        $this->assertNotNull($row->error_excerpt);
    }

    public function test_audit_enriches_v2_metadata_and_redacts_secrets(): void
    {
        $transport = new StubMcpTransport;
        $this->scriptModernDiscovery($transport);
        $transport->responses['tools/call:async'] = [
            'resultType' => 'task',
            'task' => ['taskId' => '00000000-0000-4000-8000-000000000001'],
            'artifactIds' => ['00000000-0000-4000-8000-000000000002'],
        ];
        McpClient::useTransportResolver(fn () => $transport);

        (new ToolInvoker)->invoke($this->server(), 'async', []);
        $row = McpToolCallAudit::query()->first();
        $this->assertSame('2026-07-28', $row->protocol_version);
        $this->assertSame('task', $row->result_type);
        $this->assertSame('00000000-0000-4000-8000-000000000001', $row->task_id);
        $this->assertSame(['00000000-0000-4000-8000-000000000002'], $row->artifact_ids);

        McpToolCallAudit::query()->delete();
        $transport->responses['tools/call:secret'] = JsonRpcMessage::errorResponse('secret', -32000, 'Bearer top-secret token=my-token access_token=ya29.oauth-at&refresh_token=1//0g-rt {"id_token":"eyJ.oauth-id","client_secret":"GOCSPX-cs"} api_key: k-123 token_count=12');
        (new ToolInvoker)->invoke($this->server(), 'secret', []);
        $excerpt = (string) McpToolCallAudit::query()->value('error_excerpt');
        foreach (['top-secret', 'my-token', 'ya29.oauth-at', '1//0g-rt', 'eyJ.oauth-id', 'GOCSPX-cs', 'k-123'] as $secret) {
            $this->assertStringNotContainsString($secret, $excerpt);
        }
        // Names are kept (only values are redacted) and non-secret keys are untouched.
        $this->assertStringContainsString('access_token=[REDACTED]', $excerpt);
        $this->assertStringContainsString('"client_secret":"[REDACTED]"', $excerpt);
        $this->assertStringContainsString('token_count=12', $excerpt);
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

    private function scriptModernDiscovery(StubMcpTransport $transport): void
    {
        $transport->responses['server/discover'] = [
            'protocolVersion' => McpClient::MODERN_PROTOCOL_VERSION,
            'capabilities' => [],
            'serverInfo' => ['name' => 'test-server', 'version' => '2.0.0'],
        ];
    }
}
