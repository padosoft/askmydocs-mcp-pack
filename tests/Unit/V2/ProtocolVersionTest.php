<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\V2;

use Padosoft\AskMyDocsMcpPack\Protocol\ProtocolVersion;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use PHPUnit\Framework\TestCase;

final class ProtocolVersionTest extends TestCase
{
    public function test_client_constants_are_derived_from_the_canonical_protocol_list(): void
    {
        $this->assertSame(ProtocolVersion::V2, McpClient::MODERN_PROTOCOL_VERSION);
        $this->assertSame(ProtocolVersion::CLIENT_FALLBACKS, McpClient::SUPPORTED_LEGACY_PROTOCOL_VERSIONS);
        $this->assertSame(ProtocolVersion::CLIENT_FALLBACKS[0], McpClient::LATEST_LEGACY_PROTOCOL_VERSION);
        $this->assertSame([ProtocolVersion::V2, ...ProtocolVersion::CLIENT_FALLBACKS], ProtocolVersion::CLIENT_SUPPORTED);
    }

    public function test_fallbacks_are_ordered_newest_first_and_bounded_as_documented(): void
    {
        $sorted = ProtocolVersion::CLIENT_FALLBACKS;
        rsort($sorted, SORT_STRING);

        $this->assertSame($sorted, ProtocolVersion::CLIENT_FALLBACKS);
        // README / migration guide: legacy negotiation runs from 2025-11-25 back through 2024-10-07.
        $this->assertSame('2025-11-25', ProtocolVersion::CLIENT_FALLBACKS[0]);
        $this->assertSame('2024-10-07', ProtocolVersion::CLIENT_FALLBACKS[array_key_last(ProtocolVersion::CLIENT_FALLBACKS)]);
    }
}
