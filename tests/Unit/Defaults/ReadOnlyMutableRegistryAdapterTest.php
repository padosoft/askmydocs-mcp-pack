<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Defaults;

use PHPUnit\Framework\TestCase;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Defaults\ReadOnlyMutableRegistryAdapter;
use Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeMcpServer;

/**
 * v1.5.0 W1.B iter-1 — pin the contract for the new fallback adapter
 * that wraps a host's read-only `McpServerRegistryContract` when the
 * host has not bound a writable registry.
 *
 * Why this test file exists: the previous PR #10 implementation
 * resolved `McpServerMutableRegistryContract` to a FRESH empty
 * `InMemoryMcpServerRegistry`, silently dropping the host's actual
 * server catalog on paginated reads. The adapter shipped in iter-1
 * delegates `forTenant()` / `find()` to the host's registry and
 * exposes an in-memory `paginate()` over the same data — write
 * paths stay 501 by design.
 */
class ReadOnlyMutableRegistryAdapterTest extends TestCase
{
    private McpServerRegistryContract $inner;

    protected function setUp(): void
    {
        $this->inner = new class implements McpServerRegistryContract {
            public array $servers;

            public function __construct()
            {
                $this->servers = [
                    new FakeMcpServer(id: 'srv-a', name: 'Alpha', transport: 'http', tenantId: 'acme'),
                    new FakeMcpServer(id: 'srv-b', name: 'Beta',  transport: 'sse', tenantId: 'acme'),
                    new FakeMcpServer(id: 'srv-c', name: 'Gamma', transport: 'http', tenantId: 'globex'),
                ];
            }

            public function forTenant(?string $tenantId): array
            {
                return array_values(array_filter(
                    $this->servers,
                    static fn(McpServerContract $s): bool => $s->tenantId() === $tenantId || $tenantId === null,
                ));
            }

            public function find(string $id): ?McpServerContract
            {
                foreach ($this->servers as $s) {
                    if ($s->id() === $id) {
                        return $s;
                    }
                }
                return null;
            }
        };
    }

    public function test_for_tenant_delegates_to_inner_read_registry(): void
    {
        $adapter = new ReadOnlyMutableRegistryAdapter($this->inner);
        $rows = $adapter->forTenant('acme');
        $this->assertCount(2, $rows);
        $ids = array_map(fn (McpServerContract $s): string => $s->id(), $rows);
        $this->assertSame(['srv-a', 'srv-b'], $ids);
    }

    public function test_find_delegates_to_inner_read_registry(): void
    {
        $adapter = new ReadOnlyMutableRegistryAdapter($this->inner);
        $this->assertSame('srv-c', $adapter->find('srv-c')?->id());
        $this->assertNull($adapter->find('srv-missing'));
    }

    public function test_paginate_returns_filter_plus_slice_over_inner_registry(): void
    {
        // This is the load-bearing case: GET /servers?per_page=10 on
        // a host with a v1.4 read-only registry MUST return the
        // host's actual servers — NOT an empty page.
        $adapter = new ReadOnlyMutableRegistryAdapter($this->inner);
        $page = $adapter->paginate('acme', filters: [], page: 1, perPage: 10);

        $this->assertSame(2, $page->total);
        $this->assertSame(['srv-a', 'srv-b'], array_map(
            fn (McpServerContract $s): string => $s->id(),
            $page->data,
        ));
    }

    public function test_paginate_q_filter_matches_inner_data(): void
    {
        $adapter = new ReadOnlyMutableRegistryAdapter($this->inner);
        $page = $adapter->paginate('acme', filters: ['q' => 'beta']);
        $this->assertSame(1, $page->total);
        $this->assertSame('srv-b', $page->data[0]->id());
    }

    public function test_paginate_transport_filter(): void
    {
        $adapter = new ReadOnlyMutableRegistryAdapter($this->inner);
        $page = $adapter->paginate('acme', filters: ['transport' => 'sse']);
        $this->assertSame(1, $page->total);
        $this->assertSame('srv-b', $page->data[0]->id());
    }

    public function test_create_throws_host_feature_not_implemented(): void
    {
        $adapter = new ReadOnlyMutableRegistryAdapter($this->inner);
        $this->expectException(HostFeatureNotImplementedException::class);
        $adapter->create(['name' => 'X']);
    }

    public function test_update_throws_host_feature_not_implemented(): void
    {
        $adapter = new ReadOnlyMutableRegistryAdapter($this->inner);
        $this->expectException(HostFeatureNotImplementedException::class);
        $adapter->update('srv-a', ['name' => 'X']);
    }

    public function test_delete_throws_host_feature_not_implemented(): void
    {
        $adapter = new ReadOnlyMutableRegistryAdapter($this->inner);
        $this->expectException(HostFeatureNotImplementedException::class);
        $adapter->delete('srv-a');
    }

    public function test_find_for_active_tenant_via_trait_walks_for_tenant_first(): void
    {
        // Default `findForActiveTenant()` lives on the trait and
        // walks `forTenant()` first (the safe path under id reuse).
        $adapter = new ReadOnlyMutableRegistryAdapter($this->inner);
        $this->assertSame('srv-a', $adapter->findForActiveTenant('acme', 'srv-a')?->id());
        // srv-c belongs to globex — invisible from acme.
        $this->assertNull($adapter->findForActiveTenant('acme', 'srv-c'));
    }
}
