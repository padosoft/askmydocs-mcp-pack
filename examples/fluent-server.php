<?php

use Padosoft\AskMyDocsMcpPack\Facades\Mcp;
use Padosoft\AskMyDocsMcpPack\Fluent\App;
use Padosoft\AskMyDocsMcpPack\Fluent\Tool;
use Padosoft\AskMyDocsMcpPack\Protocol\CacheScope;
use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;
use Padosoft\AskMyDocsMcpPack\Protocol\McpResult;

Mcp::server('askmydocs')
    ->name('AskMyDocs')
    ->version('2.0.0')
    ->cache(300_000, CacheScope::Private)
    ->tool(
        Tool::make('search')
            ->inputSchema(['type' => 'object', 'required' => ['query'], 'properties' => ['query' => ['type' => 'string']]])
            ->outputSchema(['type' => 'object', 'properties' => ['hits' => ['type' => 'array']]])
            ->readOnly()->idempotent()->app('ui://documents/viewer')
            ->handle(static fn (McpRequest $request): McpResult => McpResult::structured([
                'hits' => [['title' => 'Example', 'query' => $request->argument('query')]],
            ]))
    )
    ->app(App::make('viewer')->resource('ui://documents/viewer')->html('<main id="app"></main>'))
    ->web('/mcp', ['auth:mcp'])
    ->local('askmydocs');
