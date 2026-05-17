<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Padosoft\AskMyDocsMcpPack\Support\HostApiKey;

class HostApiKeyTest extends TestCase
{
    public function test_to_array_omits_the_plaintext(): void
    {
        $key = new HostApiKey(
            id: 'tok_01',
            name: 'cli',
            scopes: ['mcp.tools.invoke'],
            createdBy: 'a@b.c',
            createdAt: '2026-05-18T00:00:00Z',
            lastUsedAt: null,
            plaintext: 'super-secret-do-not-leak',
        );

        $list = $key->toArray();

        $this->assertArrayNotHasKey('plaintext', $list);
        $this->assertSame('tok_01', $list['id']);
        $this->assertSame(['mcp.tools.invoke'], $list['scopes']);
    }

    public function test_to_create_array_surfaces_the_plaintext_exactly_once(): void
    {
        $key = new HostApiKey(
            id: 'tok_02',
            name: 'cli',
            scopes: ['mcp.tools.invoke'],
            plaintext: 'plaintext-only-on-create',
        );

        $create = $key->toCreateArray();

        $this->assertSame('plaintext-only-on-create', $create['plaintext']);
        $this->assertSame('tok_02', $create['id']);
    }

    public function test_from_array_coerces_scopes_to_strings(): void
    {
        $key = HostApiKey::fromArray([
            'id' => 'k',
            'name' => 'cli',
            'scopes' => ['mcp.x', 42],
        ]);

        $this->assertSame(['mcp.x', '42'], $key->scopes);
    }
}
