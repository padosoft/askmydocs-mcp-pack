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

    public function test_paginate_returns_filter_plus_slice_for_active_tenant(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add($this->server('one', 'acme'));
        $registry->add($this->server('two', 'acme'));
        $registry->add($this->server('three', 'acme'));
        $registry->add($this->server('four', 'globex'));
        $registry->add($this->server('five', null)); // platform-global

        $page = $registry->paginate(tenantId: 'acme', filters: [], page: 1, perPage: 2);

        $this->assertSame(4, $page->total); // 3 acme + 1 global
        $this->assertSame(2, $page->perPage);
        $this->assertSame(1, $page->currentPage);
        $this->assertSame(2, $page->lastPage);
        $this->assertCount(2, $page->data);
    }

    public function test_paginate_q_filter_is_case_insensitive_substring_on_name(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add($this->namedServer('a', 'OpenAI MCP'));
        $registry->add($this->namedServer('b', 'GitHub MCP'));
        $registry->add($this->namedServer('c', 'Slack MCP'));

        $page = $registry->paginate(null, ['q' => 'github']);
        $this->assertSame(1, $page->total);
        $this->assertSame('b', $page->data[0]->id());
    }

    public function test_paginate_transport_filter_exact_match(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add($this->namedServer('a', 'A', transport: 'http'));
        $registry->add($this->namedServer('b', 'B', transport: 'sse'));
        $registry->add($this->namedServer('c', 'C', transport: 'stdio'));

        $page = $registry->paginate(null, ['transport' => 'sse']);
        $this->assertSame(1, $page->total);
        $this->assertSame('b', $page->data[0]->id());
    }

    public function test_paginate_enabled_filter_coerces_boolean(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add($this->server('on', null, enabled: true));
        $registry->add($this->server('off', null, enabled: false));

        $page = $registry->paginate(null, ['enabled' => 'false']);
        $this->assertSame(1, $page->total);
        $this->assertSame('off', $page->data[0]->id());

        $page = $registry->paginate(null, ['enabled' => '1']);
        $this->assertSame(1, $page->total);
        $this->assertSame('on', $page->data[0]->id());
    }

    public function test_paginate_out_of_range_page_returns_empty_slice_with_meta(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add($this->server('one', null));

        $page = $registry->paginate(null, [], page: 99, perPage: 10);
        $this->assertSame([], $page->data);
        $this->assertSame(1, $page->total);
        $this->assertSame(99, $page->currentPage); // preserved for SPA breadcrumb
        $this->assertSame(1, $page->lastPage);
    }

    public function test_paginate_per_page_clamps_to_one_when_zero(): void
    {
        $registry = new InMemoryMcpServerRegistry();
        $registry->add($this->server('one', null));

        $page = $registry->paginate(null, [], page: 1, perPage: 0);
        $this->assertSame(1, $page->perPage);
    }

    public function test_paginate_includes_disabled_rows_so_admin_can_see_them(): void
    {
        // `forTenant()` hides disabled rows (read-path semantics);
        // `paginate()` is the admin view, so it includes them. The
        // SPA filters via `?enabled=true` when it wants only the
        // healthy ones.
        $registry = new InMemoryMcpServerRegistry();
        $registry->add($this->server('a', 'acme', enabled: true));
        $registry->add($this->server('b', 'acme', enabled: false));

        $page = $registry->paginate('acme');
        $this->assertSame(2, $page->total);
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

    private function namedServer(string $id, string $name, string $transport = 'http'): InMemoryMcpServer
    {
        return new InMemoryMcpServer(
            id: $id,
            name: $name,
            transport: $transport,
            tenantId: null,
            transportConfig: ['endpoint' => 'http://stub'],
            allowedTools: [],
            enabled: true,
        );
    }
}
