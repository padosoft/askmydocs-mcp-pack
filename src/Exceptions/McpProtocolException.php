<?php

namespace Padosoft\AskMyDocsMcpPack\Exceptions;

final class McpProtocolException extends McpException
{
    public function __construct(
        public readonly int $rpcCode,
        string $message,
        public readonly mixed $data = null,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }
}
