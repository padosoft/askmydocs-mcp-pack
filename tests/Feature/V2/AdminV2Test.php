<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\V2;

use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\TaskManagerContract;
use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;
use Padosoft\AskMyDocsMcpPack\Tests\Support\InputRequiredTaskHandler;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

final class AdminV2Test extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mcp-pack.admin_v2.enabled', true);
        $app['config']->set('mcp-pack.admin_v2.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('mcp-artifacts');
        config()->set('mcp-pack.artifacts.disk', 'mcp-artifacts');
    }

    public function test_artifact_validation_is_a_422_and_valid_content_is_created(): void
    {
        $this->postJson('/api/admin/mcp-pack/v2/artifacts', [
            'name' => 'bad.bin', 'mimeType' => 'application/octet-stream', 'contentBase64' => '***',
        ])->assertUnprocessable()->assertJsonValidationErrors('artifact');

        $this->postJson('/api/admin/mcp-pack/v2/artifacts', [
            'name' => '../report.txt', 'mimeType' => 'text/plain', 'content' => 'report',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'report.txt')
            ->assertJsonPath('data.size', 6);
    }

    public function test_task_state_conflict_is_a_409_response(): void
    {
        config()->set('mcp-pack.tasks.enabled', true);
        $task = $this->app->make(TaskManagerContract::class)->create(
            InputRequiredTaskHandler::class,
            new McpRequest('tools/call', [], null, null, null, [], [], []),
        )->fresh();

        $this->postJson('/api/admin/mcp-pack/v2/tasks/'.$task->getKey().'/input', [
            'inputResponses' => ['approval' => ['value' => true]],
            'requestState' => 'wrong-state',
        ])->assertConflict()->assertJsonPath('error', 'task_conflict');
    }

    public function test_openapi_and_capabilities_routes_are_available(): void
    {
        $this->getJson('/api/admin/mcp-pack/v2/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0');
        $this->getJson('/api/admin/mcp-pack/v2/capabilities')->assertOk()->assertJsonPath('protocolVersion', '2026-07-28');
    }
}
