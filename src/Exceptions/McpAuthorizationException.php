<?php

namespace Padosoft\AskMyDocsMcpPack\Exceptions;

/** An HTTP MCP endpoint rejected the current credentials. */
final class McpAuthorizationException extends McpTransportException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly ?string $oauthError = null,
        public readonly ?string $wwwAuthenticate = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "MCP authorization was rejected (HTTP {$httpStatus}); reauthorization may be required.",
            $httpStatus,
            $previous,
        );
    }
}
