<?php

namespace Padosoft\AskMyDocsMcpPack\Console;

use Illuminate\Console\Command;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\TaskManagerContract;

final class RecoverMcpTasksCommand extends Command
{
    protected $signature = 'mcp-pack:tasks:recover';

    protected $description = 'Requeue working MCP tasks whose worker lease expired.';

    public function handle(TaskManagerContract $tasks): int
    {
        $this->components->info('Requeued '.$tasks->recover().' recoverable MCP task(s).');

        return self::SUCCESS;
    }
}
