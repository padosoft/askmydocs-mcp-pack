<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Support;

use Padosoft\AskMyDocsMcpPack\Support\McpRemoteTask;
use PHPUnit\Framework\TestCase;

final class McpRemoteTaskTest extends TestCase
{
    public function test_terminal_flat_task_preserves_result_and_metadata(): void
    {
        $task = McpRemoteTask::fromEnvelope([
            'resultType' => 'complete',
            'taskId' => 'task-1',
            'status' => 'completed',
            'statusMessage' => 'Done',
            'createdAt' => '2026-08-19T12:00:00Z',
            'lastUpdatedAt' => '2026-08-19T12:01:00Z',
            'ttlMs' => 120_000,
            'pollIntervalMs' => 750,
            'result' => ['content' => [['type' => 'text', 'text' => 'Ready']]],
        ]);

        $this->assertTrue($task->isTerminal());
        $this->assertSame('Ready', $task->result['content'][0]['text']);
        $this->assertSame(750, $task->toArray()['pollIntervalMs']);
    }

    public function test_invalid_task_envelope_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        McpRemoteTask::fromEnvelope(['resultType' => 'task']);
    }
}
