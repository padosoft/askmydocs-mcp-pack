<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\Foundation\Application;
use Padosoft\AskMyDocsMcpPack\AskMyDocsMcpPackServiceProvider;
use Padosoft\AskMyDocsMcpPack\Fluent\McpManager;
use Padosoft\AskMyDocsMcpPack\Fluent\Tool;
use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;
use Padosoft\AskMyDocsMcpPack\Protocol\McpResult;
use Padosoft\AskMyDocsMcpPack\ServerSide\StdioRunner;
use Padosoft\AskMyDocsMcpPack\ServerSide\V2JsonRpcRequestHandler;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = Application::create(options: [
    'extra' => ['providers' => [AskMyDocsMcpPackServiceProvider::class]],
]);
$app->make(Kernel::class)->bootstrap();
$app->make('config')->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
$app->make('config')->set('queue.default', 'sync');
$app->make(McpManager::class)->server('default')
    ->name('AskMyDocs conformance fixture')
    ->version('2.0.0')
    ->tool(
        Tool::make('echo')
            ->description('Echo a string for wire-level conformance checks.')
            ->inputSchema([
                'type' => 'object',
                'required' => ['value'],
                'properties' => ['value' => ['type' => 'string']],
            ])
            ->readOnly()
            ->idempotent()
            ->handle(static fn (McpRequest $request): McpResult => McpResult::structured([
                'echo' => $request->argument('value'),
            ])),
    )
    ->register();

(new StdioRunner($app->make(V2JsonRpcRequestHandler::class)))->run(['server_id' => 'default']);
