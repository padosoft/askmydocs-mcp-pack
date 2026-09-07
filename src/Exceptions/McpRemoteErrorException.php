<?php

namespace Padosoft\AskMyDocsMcpPack\Exceptions;

/** A JSON-RPC error delivered successfully by a remote MCP server. */
final class McpRemoteErrorException extends McpException
{
    /** @param array<string,mixed>|null $data */
    public function __construct(
        string $message,
        public readonly int $rpcCode,
        public readonly ?array $data = null,
    ) {
        parent::__construct($message, $rpcCode);
    }
}
