<?php

namespace Padosoft\AskMyDocsMcpPack\Console;

use Illuminate\Console\Command;
use Padosoft\AskMyDocsMcpPack\ServerSide\StdioRunner;
use Padosoft\AskMyDocsMcpPack\ServerSide\V2JsonRpcRequestHandler;

/**
 * `php artisan mcp-pack:serve` boots the MCP 2026-07-28 stdio loop for
 * a compiled Fluent server. Each message is independently validated;
 * the process is long-lived only because stdio owns one child process.
 *
 * The artisan command does NOT enforce auth (stdio runs locally). The
 * client process spawns this command via its `command` / `args`
 * config; the host's filesystem permissions are the trust boundary.
 *
 * Example Claude Desktop config:
 *
 *   {
 *     "mcpServers": {
 *       "askmydocs": {
 *         "command": "php",
 *         "args": ["/path/to/host/artisan", "mcp-pack:serve", "askmydocs"]
 *       }
 *     }
 *   }
 */
class McpServeCommand extends Command
{
    protected $signature = 'mcp-pack:serve {server? : Fluent local server alias or id}';

    protected $description = 'Run a Fluent MCP 2026-07-28 server over the stateless stdio request loop.';

    public function handle(V2JsonRpcRequestHandler $handler): int
    {
        $runner = new StdioRunner($handler);
        $runner->run(['server_id' => (string) ($this->argument('server') ?: config('mcp-pack.v2.default_server', 'default'))]);

        return self::SUCCESS;
    }
}
