<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Services;

use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServer;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;
use Padosoft\AskMyDocsMcpPack\Tests\Support\StubMcpTransport;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class McpHandshakeServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_refresh_runs_initialize_and_tools_list(): void
    {
        $transport = (new StubMcpTransport())
            ->scriptInitialize(['tools' => []])
            ->scriptListTools([['name' => 'a'], ['name' => 'b']]);

        McpClient::useTransportResolver(fn() => $transport);

        $svc = new McpHandshakeService(ttlSeconds: 0);
        $payload = $svc->refresh($this->server());

        $this->assertCount(2, $payload['tools']);
        $methods = array_map(static fn($r) => $r->method, $transport->sentRequests);
        $this->assertSame(['initialize', 'tools/list'], $methods);
    }

    public function test_refresh_wraps_transport_failure_in_mcp_exception(): void
    {
        $transport = new StubMcpTransport(); // no script -> JSON-RPC error responses
        McpClient::useTransportResolver(fn() => $transport);

        $svc = new McpHandshakeService(ttlSeconds: 0);

        $this->expectException(\Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException::class);
        $svc->refresh($this->server());
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
