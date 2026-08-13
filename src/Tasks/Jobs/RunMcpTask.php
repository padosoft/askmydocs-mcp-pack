<?php

namespace Padosoft\AskMyDocsMcpPack\Tasks\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Padosoft\AskMyDocsMcpPack\Tasks\DatabaseTaskManager;

final class RunMcpTask implements ShouldQueue
{
    public int $tries = 1;

    public function __construct(public readonly string $taskId) {}

    public function handle(DatabaseTaskManager $tasks): void
    {
        $tasks->run($this->taskId);
    }
}
