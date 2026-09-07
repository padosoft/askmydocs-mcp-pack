<?php

namespace Padosoft\AskMyDocsMcpPack\Tasks\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Padosoft\AskMyDocsMcpPack\Tasks\DatabaseTaskManager;

final class RunMcpTask implements ShouldQueue
{
    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $taskId)
    {
        $lease = max(30, (int) config('mcp-pack.tasks.lease_seconds', 300));
        $safety = min(max(5, (int) config('mcp-pack.tasks.lease_safety_seconds', 30)), $lease - 1);
        $this->timeout = $lease - $safety;
    }

    public function handle(DatabaseTaskManager $tasks): void
    {
        $tasks->run($this->taskId);
    }
}
