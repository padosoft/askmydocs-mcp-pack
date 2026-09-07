<?php

namespace Padosoft\AskMyDocsMcpPack\Protocol;

/**
 * Single source of truth for the protocol revisions this package speaks.
 *
 * The v2 server speaks exactly {@see self::V2}. The client tries discovery on
 * {@see self::V2} first and, only when the modern method/version is explicitly
 * unsupported, negotiates the legacy revisions in {@see self::CLIENT_FALLBACKS}
 * order (newest first). `Services\McpClient` derives its own constants from
 * these lists so the advertised and the actual downgrade boundary can never
 * diverge.
 */
final class ProtocolVersion
{
    public const V2 = '2026-07-28';

    /** Legacy revisions the client negotiates via `initialize`, newest first. @var list<string> */
    public const CLIENT_FALLBACKS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05', '2024-10-07'];

    /** @var list<string> */
    public const CLIENT_SUPPORTED = [self::V2, ...self::CLIENT_FALLBACKS];

    private function __construct() {}
}
