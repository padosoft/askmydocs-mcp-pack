<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;

final class FailingTaskHandler
{
    public function handle(McpRequest $request): never
    {
        throw new \RuntimeException('sensitive handler failure');
    }
}
