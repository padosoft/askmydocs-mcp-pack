<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Padosoft\AskMyDocsMcpPack\Support\HostUser;

class HostUserTest extends TestCase
{
    public function test_constructor_round_trips_through_to_array(): void
    {
        $user = new HostUser(
            id: 42,
            email: 'lorenzo@padosoft.com',
            name: 'Lorenzo Padovani',
            initials: 'LP',
            tenantId: 'acme-corp',
            permissions: ['mcp.servers.view', 'mcp.tools.invoke'],
        );

        $this->assertSame([
            'id' => 42,
            'email' => 'lorenzo@padosoft.com',
            'name' => 'Lorenzo Padovani',
            'initials' => 'LP',
            'tenant_id' => 'acme-corp',
            'permissions' => ['mcp.servers.view', 'mcp.tools.invoke'],
        ], $user->toArray());
    }

    public function test_from_array_is_defensive_against_missing_keys(): void
    {
        $user = HostUser::fromArray([]);

        $this->assertSame(0, $user->id);
        $this->assertSame('', $user->email);
        $this->assertSame('', $user->name);
        $this->assertNull($user->initials);
        $this->assertNull($user->tenantId);
        $this->assertSame([], $user->permissions);
    }

    public function test_from_array_coerces_non_string_permissions_to_strings(): void
    {
        $user = HostUser::fromArray([
            'id' => '42',
            'email' => 'a@b.c',
            'name' => 'Test',
            'permissions' => ['mcp.x', 99, true],
        ]);

        $this->assertSame('42', $user->id);
        $this->assertSame(['mcp.x', '99', '1'], $user->permissions);
    }

    public function test_from_array_rejects_empty_string_tenant_id(): void
    {
        $user = HostUser::fromArray([
            'id' => 1,
            'email' => 'x@y.z',
            'name' => 'A',
            'tenant_id' => '',
        ]);

        $this->assertNull($user->tenantId);
    }
}
