<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Padosoft\AskMyDocsMcpPack\Contracts\Concerns\HasMutableRegistry;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerMutableRegistryContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException;
use Padosoft\AskMyDocsMcpPack\Support\McpServerPage;

/**
 * v1.5.0 — pins the contract that {@see HasMutableRegistry} provides
 * 501-throwing defaults for every mutable method. Hosts that adopt
 * the trait inherit the safe default; overrides are opt-in.
 */
class HasMutableRegistryTraitTest extends TestCase
{
    public function test_paginate_throws_host_feature_not_implemented(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        $this->bareImpl()->paginate(null);
    }

    public function test_create_throws_host_feature_not_implemented(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        $this->bareImpl()->create([]);
    }

    public function test_update_throws_host_feature_not_implemented(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        $this->bareImpl()->update('srv-1', []);
    }

    public function test_delete_throws_host_feature_not_implemented(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        $this->bareImpl()->delete('srv-1');
    }

    private function bareImpl(): McpServerMutableRegistryContract
    {
        return new class implements McpServerMutableRegistryContract {
            use HasMutableRegistry;

            public function forTenant(?string $tenantId): array
            {
                return [];
            }

            public function find(string $id): ?McpServerContract
            {
                return null;
            }
        };
    }
}
