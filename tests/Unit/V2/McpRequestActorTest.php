<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\V2;

use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpRequestActorTest extends TestCase
{
    /** @return iterable<string,array{0:mixed,1:?string,2:?string}> */
    public static function actors(): iterable
    {
        yield 'scalar string actor is preserved' => ['alice', 'principal-1', 'alice'];
        yield 'scalar int actor is preserved' => [42, 'principal-1', '42'];
        yield 'array actor uses its id' => [['id' => 7, 'name' => 'x'], 'principal-1', '7'];
        yield 'object actor uses getAuthIdentifier' => [new class
        {
            public function getAuthIdentifier(): int
            {
                return 99;
            }
        }, 'principal-1', '99'];
        yield 'null actor falls back to principal' => [null, 'principal-1', 'principal-1'];
        yield 'null actor without principal is null' => [null, null, null];
        yield 'array actor without id falls back to principal' => [['name' => 'x'], 'principal-1', 'principal-1'];
    }

    #[DataProvider('actors')]
    public function test_actor_id_matches_tool_invoker_semantics(mixed $actor, ?string $principalId, ?string $expected): void
    {
        $request = new McpRequest('tools/call', [], 'acme', $actor, $principalId, [], [], []);

        $this->assertSame($expected, $request->actorId());
    }
}
