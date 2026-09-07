<?php

namespace Padosoft\AskMyDocsMcpPack\Console;

use Illuminate\Console\Command;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\TaskManagerContract;

final class PruneMcpTasksCommand extends Command
{
    protected $signature = 'mcp-pack:tasks:prune';

    protected $description = 'Idempotently delete expired MCP v2 tasks.';

    public function handle(TaskManagerContract $tasks): int
    {
        $this->components->info('Pruned '.$tasks->prune().' expired MCP task(s).');

        return self::SUCCESS;
    }
}
