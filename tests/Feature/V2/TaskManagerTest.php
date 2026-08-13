<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\V2;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\CancellationRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\TaskManagerContract;
use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;
use Padosoft\AskMyDocsMcpPack\Tasks\Jobs\RunMcpTask;
use Padosoft\AskMyDocsMcpPack\Tasks\TaskStatus;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FailingTaskHandler;
use Padosoft\AskMyDocsMcpPack\Tests\Support\InputRequiredTaskHandler;
use Padosoft\AskMyDocsMcpPack\Tests\Support\PartialInputTaskHandler;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

final class TaskManagerTest extends TestCase
{
    public function test_task_round_trip_is_durable_input_driven_and_single_use(): void
    {
        config()->set('mcp-pack.tasks.enabled', true);
        $manager = $this->app->make(TaskManagerContract::class);
        $request = new McpRequest('tools/call', ['document' => 'a'], 'acme', null, 'alice', [], ['io.modelcontextprotocol/tasks' => new \stdClass], []);

        $task = $manager->create(InputRequiredTaskHandler::class, $request);
        $task->refresh();
        $this->assertSame(TaskStatus::InputRequired, $task->state);
        $this->assertNotNull($task->request_state);

        $updated = $manager->update($task->getKey(), ['approval' => ['value' => true]], 'acme', 'alice', $task->request_state);
        $updated->refresh();
        $this->assertSame(TaskStatus::Completed, $updated->state);
        $this->assertTrue($updated->result['structuredContent']['approved']);
    }

    public function test_cancellation_is_terminal_and_idempotent(): void
    {
        Queue::fake();
        config()->set('mcp-pack.tasks.enabled', true);
        $manager = $this->app->make(TaskManagerContract::class);
        $request = new McpRequest('tools/call', [], 'acme', null, 'alice', [], [], []);
        $task = $manager->create(InputRequiredTaskHandler::class, $request);
        $cancelled = $manager->cancel($task->getKey(), 'acme', 'alice');
        $again = $manager->cancel($task->getKey(), 'acme', 'alice');
        $this->assertSame(TaskStatus::Cancelled, $cancelled->state);
        $this->assertSame(TaskStatus::Cancelled, $again->state);
        $cancellations = $this->app->make(CancellationRegistryContract::class);
        $this->assertTrue($cancellations->isCancelled('acme', $task->getKey(), 'alice'));
        $this->assertFalse($cancellations->isCancelled('acme', $task->getKey(), 'mallory'));
    }

    public function test_partial_and_duplicate_inputs_preserve_single_use_state_until_complete(): void
    {
        config()->set('mcp-pack.tasks.enabled', true);
        $manager = $this->app->make(TaskManagerContract::class);
        $request = new McpRequest('tools/call', ['document' => 'a'], 'acme', null, 'alice', [], [], []);
        $task = $manager->create(PartialInputTaskHandler::class, $request)->fresh();
        $state = $task->request_state;

        $partial = $manager->update($task->getKey(), ['approval' => ['value' => true]], 'acme', 'alice', $state);
        $this->assertSame(TaskStatus::InputRequired, $partial->state);
        $this->assertSame($state, $partial->request_state);

        $same = $manager->update($task->getKey(), ['approval' => ['value' => true]], 'acme', 'alice', $state);
        $this->assertSame(TaskStatus::InputRequired, $same->state);

        try {
            $manager->update($task->getKey(), ['approval' => ['value' => false]], 'acme', 'alice', $state);
            $this->fail('Conflicting duplicate input should fail.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Conflicting duplicate', $e->getMessage());
        }

        $completed = $manager->update($task->getKey(), ['reason' => ['value' => 'Reviewed']], 'acme', 'alice', $state)->fresh();
        $this->assertSame(TaskStatus::Completed, $completed->state);
        $this->assertSame('Reviewed', $completed->result['structuredContent']['reason']);
    }

    public function test_task_scope_hides_cross_tenant_and_cross_actor_rows(): void
    {
        Queue::fake();
        config()->set('mcp-pack.tasks.enabled', true);
        $manager = $this->app->make(TaskManagerContract::class);
        $task = $manager->create(InputRequiredTaskHandler::class, new McpRequest('tools/call', [], 'acme', null, 'alice', [], [], []));

        foreach ([['other', 'alice'], ['acme', 'mallory']] as [$tenant, $actor]) {
            try {
                $manager->get($task->getKey(), $tenant, $actor);
                $this->fail('Cross-scope task lookup should not resolve.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_failed_task_is_sanitized_encrypted_and_never_retried(): void
    {
        config()->set('mcp-pack.tasks.enabled', true);
        $manager = $this->app->make(TaskManagerContract::class);
        $task = $manager->create(FailingTaskHandler::class, new McpRequest('tools/call', [], 'acme', null, 'alice', [], [], []))->fresh();

        $this->assertSame(TaskStatus::Failed, $task->state);
        $this->assertSame('Task execution failed.', $task->error['message']);
        $raw = (string) DB::table('mcp_tasks')->where('id', $task->getKey())->value('error');
        $this->assertStringNotContainsString('Task execution failed.', $raw);
        $this->assertStringNotContainsString('sensitive handler failure', json_encode($task->toProtocolArray(), JSON_THROW_ON_ERROR));
    }

    public function test_recovery_requeues_unleased_work_and_pruning_is_idempotent(): void
    {
        Queue::fake();
        config()->set('mcp-pack.tasks.enabled', true);
        $manager = $this->app->make(TaskManagerContract::class);
        $task = $manager->create(InputRequiredTaskHandler::class, new McpRequest('tools/call', [], 'acme', null, 'alice', [], [], []));

        $this->assertSame(1, $manager->recover());
        Queue::assertPushed(RunMcpTask::class, 2);

        $task->expires_at = now()->subSecond();
        $task->save();
        $this->assertSame(1, $manager->prune());
        $this->assertSame(0, $manager->prune());
    }
}
