<?php

namespace Padosoft\AskMyDocsMcpPack\Protocol;

final class ProtocolVersion
{
    public const V2 = '2026-07-28';

    /** @var list<string> */
    public const CLIENT_FALLBACKS = ['2025-11-25', '2025-06-18'];

    /** @var list<string> */
    public const CLIENT_SUPPORTED = [self::V2, ...self::CLIENT_FALLBACKS];

    private function __construct() {}
}
