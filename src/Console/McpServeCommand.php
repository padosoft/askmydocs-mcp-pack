<?php

namespace Padosoft\AskMyDocsMcpPack\Console;

use Illuminate\Console\Command;
use Padosoft\AskMyDocsMcpPack\ServerSide\JsonRpcRequestHandler;
use Padosoft\AskMyDocsMcpPack\ServerSide\StdioRunner;

/**
 * v1.2.0 — `php artisan mcp-pack:serve` — boots the long-lived stdio
 * loop so the host can be wired as an MCP server in any client that
 * speaks the stdio profile (Claude Desktop, Cursor, VS Code's MCP
 * extension, Cline, …).
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
 *         "args": ["/path/to/host/artisan", "mcp-pack:serve"]
 *       }
 *     }
 *   }
 */
class McpServeCommand extends Command
{
    protected $signature = 'mcp-pack:serve';

    protected $description = 'Run the MCP server-side stdio loop. Hosts expose their tool catalog via McpServerExposureContract.';

    public function handle(JsonRpcRequestHandler $handler): int
    {
        $runner = new StdioRunner($handler);
        $runner->run();

        return self::SUCCESS;
    }
}
