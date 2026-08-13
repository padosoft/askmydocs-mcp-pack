<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\V2;

use Padosoft\AskMyDocsMcpPack\Adapters\LaravelMcpAdapter;
use PHPUnit\Framework\TestCase;

final class LaravelMcpAdapterTest extends TestCase
{
    public function test_missing_or_old_optional_dependency_has_an_explicit_diagnostic(): void
    {
        if (enum_exists('Laravel\\Mcp\\Enums\\ProtocolVersion')) {
            $this->markTestSkipped('This dependency matrix includes laravel/mcp; compatibility is covered by CI.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires a release exposing MCP 2026-07-28');

        (new LaravelMcpAdapter)->assertCompatible();
    }
}
