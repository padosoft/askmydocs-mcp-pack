<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Console;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServer;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Tests\Support\StubMcpTransport;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class McpPingCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_ping_reports_ok_for_reachable_server(): void
    {
        $transport = (new StubMcpTransport())
            ->scriptInitialize()
            ->scriptListTools([['name' => 'a'], ['name' => 'b']]);
        McpClient::useTransportResolver(fn() => $transport);

        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new InMemoryMcpServer(
            id: 's1',
            name: 'KB',
            transport: 'http',
            tenantId: 'acme',
            transportConfig: ['endpoint' => 'http://stub'],
        ));
        $this->app->instance(McpServerRegistryContract::class, $registry);

        $this->artisan('mcp-pack:ping', ['--tenant' => 'acme'])
            ->assertExitCode(0);
    }

    public function test_ping_reports_error_for_unreachable_server(): void
    {
        $transport = new StubMcpTransport(); // no script -> -32601 error
        McpClient::useTransportResolver(fn() => $transport);

        $registry = new InMemoryMcpServerRegistry();
        $registry->add(new InMemoryMcpServer(
            id: 's-bad',
            name: 'Dead',
            transport: 'http',
            tenantId: 'acme',
            transportConfig: ['endpoint' => 'http://stub'],
        ));
        $this->app->instance(McpServerRegistryContract::class, $registry);

        $this->artisan('mcp-pack:ping', ['--tenant' => 'acme'])
            ->assertExitCode(1);
    }
}
