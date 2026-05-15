<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpHostBridge;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpToolAuthorizer;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;
use Padosoft\AskMyDocsMcpPack\Services\McpToolCallingService;

class ServiceProviderTest extends TestCase
{
    public function test_default_bindings_resolve_to_safe_defaults(): void
    {
        $this->assertInstanceOf(NullMcpHostBridge::class, $this->app->make(McpHostBridgeContract::class));
        $this->assertInstanceOf(InMemoryMcpServerRegistry::class, $this->app->make(McpServerRegistryContract::class));
        $this->assertInstanceOf(NullMcpToolAuthorizer::class, $this->app->make(McpToolAuthorizerContract::class));
    }

    public function test_orchestrator_is_resolvable(): void
    {
        $this->assertInstanceOf(McpToolCallingService::class, $this->app->make(McpToolCallingService::class));
        $this->assertInstanceOf(McpHandshakeService::class, $this->app->make(McpHandshakeService::class));
    }

    public function test_config_is_published_under_mcp_pack_namespace(): void
    {
        $this->assertSame(3, config('mcp-pack.tool_calling.max_iterations'));
        $this->assertSame(0, config('mcp-pack.handshake.ttl_seconds'));
    }

    public function test_migration_is_loaded(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('mcp_tool_call_audit'));
    }
}
