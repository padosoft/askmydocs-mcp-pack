<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Defaults;

use PHPUnit\Framework\TestCase;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServer;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpHostBridge;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpToolAuthorizer;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;

class InMemoryRegistryTest extends TestCase
{
    public function test_for_tenant_filters_disabled_servers(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add($this->server('one', 'acme', enabled: true));
        $registry->add($this->server('two', 'acme', enabled: false));

        $this->assertCount(1, $registry->forTenant('acme'));
        $this->assertSame('one', $registry->forTenant('acme')[0]->id());
    }

    public function test_for_tenant_includes_platform_global_entries(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add($this->server('one', 'acme'));
        $registry->add($this->server('two', null)); // platform-global

        $entries = $registry->forTenant('acme');

        $this->assertCount(2, $entries);
    }

    public function test_for_tenant_excludes_other_tenants(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add($this->server('one', 'acme'));
        $registry->add($this->server('two', 'globex'));

        $this->assertCount(1, $registry->forTenant('acme'));
        $this->assertSame('one', $registry->forTenant('acme')[0]->id());
    }

    public function test_find_returns_null_when_disabled(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add($this->server('one', 'acme', enabled: false));

        $this->assertNull($registry->find('one'));
    }

    public function test_null_authorizer_allows_everything(): void
    {
        $authorizer = new NullMcpToolAuthorizer();
        $tool = new class implements \Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract {
            public function name(): string { return 't'; }
            public function description(): string { return ''; }
            public function schema(): array { return []; }
            public function isIdempotent(): bool { return false; }
            public function isReadOnly(): bool { return false; }
            public function invoke(array $arguments): mixed { return null; }
        };

        $this->assertTrue($authorizer->authorize('actor', 'acme', $tool));
    }

    public function test_null_host_bridge_throws_loudly(): void
    {
        $this->expectException(\LogicException::class);
        (new NullMcpHostBridge())->chat(new HostChatTurn([], []));
    }

    private function server(string $id, ?string $tenantId, bool $enabled = true): InMemoryMcpServer
    {
        return new InMemoryMcpServer(
            id: $id,
            name: "Server {$id}",
            transport: 'http',
            tenantId: $tenantId,
            transportConfig: ['endpoint' => 'http://stub'],
            allowedTools: [],
            enabled: $enabled,
        );
    }
}
