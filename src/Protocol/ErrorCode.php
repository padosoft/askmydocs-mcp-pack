<?php

namespace Padosoft\AskMyDocsMcpPack\Protocol;

final class ErrorCode
{
    public const HEADER_MISMATCH = -32020;

    public const CAPABILITY_MISSING = -32021;

    public const PROTOCOL_VERSION_UNSUPPORTED = -32022;

    public const RESOURCE_NOT_FOUND = -32023;

    public const TASK_CONFLICT = -32024;

    private function __construct() {}
}
