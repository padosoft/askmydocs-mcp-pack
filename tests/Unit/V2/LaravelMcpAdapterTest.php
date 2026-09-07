<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\V2;

use Padosoft\AskMyDocsMcpPack\Adapters\LaravelMcpAdapter;
use Padosoft\AskMyDocsMcpPack\Protocol\ProtocolVersion;
use PHPUnit\Framework\TestCase;

final class LaravelMcpAdapterTest extends TestCase
{
    public function test_missing_or_old_optional_dependency_has_an_explicit_diagnostic(): void
    {
        $enum = 'Laravel\\Mcp\\Enums\\ProtocolVersion';
        $exposesLatest = enum_exists($enum) && defined($enum.'::LATEST');
        $latest = $exposesLatest ? constant($enum.'::LATEST') : null;
        $installedVersion = $latest instanceof \BackedEnum ? $latest->value : null;

        if ($installedVersion === ProtocolVersion::V2) {
            // Only a laravel/mcp release that already speaks 2026-07-28 makes the
            // incompatibility diagnostic unreachable from this process.
            $this->markTestSkipped('Installed laravel/mcp already exposes MCP 2026-07-28; the incompatibility diagnostic cannot be exercised here.');
        }

        $this->expectException(\RuntimeException::class);
        // Absent (or too old to expose LATEST): "requires a release exposing …".
        // Present but on an older protocol: "Installed laravel/mcp is incompatible …".
        $this->expectExceptionMessage($exposesLatest ? 'Installed laravel/mcp is incompatible' : 'requires a release exposing MCP 2026-07-28');

        (new LaravelMcpAdapter)->assertCompatible();
    }
}
