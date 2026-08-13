<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

interface JsonRpcRequestHandlerContract
{
    /** @param array<string,mixed> $context */
    public function handle(JsonRpcMessage $message, array $context = []): ?JsonRpcMessage;
}
