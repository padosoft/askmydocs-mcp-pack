<?php

namespace Padosoft\AskMyDocsMcpPack\Facades;

use Illuminate\Support\Facades\Facade;
use Padosoft\AskMyDocsMcpPack\Fluent\McpManager;

/** @method static \Padosoft\AskMyDocsMcpPack\Fluent\Server server(string $id) */
final class Mcp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return McpManager::class;
    }
}
