<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\ServerSide;

use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpToolAuthorizer;
use Padosoft\AskMyDocsMcpPack\ServerSide\JsonRpcRequestHandler;
use Padosoft\AskMyDocsMcpPack\ServerSide\StdioRunner;
use Padosoft\AskMyDocsMcpPack\Tests\Support\AnonymousTool;
use Padosoft\AskMyDocsMcpPack\Tests\Support\InMemoryServerExposure;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class StdioRunnerTest extends TestCase
{
    public function test_run_handles_a_single_initialize_request(): void
    {
        $output = $this->driveRunner(
            new InMemoryServerExposure(),
            [json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])],
        );

        $this->assertCount(1, $output);
        $this->assertSame(1, $output[0]['id']);
        $this->assertArrayHasKey('serverInfo', $output[0]['result']);
    }

    public function test_run_handles_a_tools_call_round_trip(): void
    {
        $exposure = new InMemoryServerExposure(toolList: [
            new AnonymousTool('greet', fn(array $args) => 'hi ' . ($args['name'] ?? '?')),
        ]);

        $output = $this->driveRunner($exposure, [
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']),
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'greet', 'arguments' => ['name' => 'Lor']]]),
        ]);

        $this->assertCount(2, $output);
        $this->assertSame('greet', $output[0]['result']['tools'][0]['name']);
        $this->assertSame('hi Lor', $output[1]['result']['content'][0]['text']);
    }

    public function test_run_emits_parse_error_for_invalid_json_but_keeps_reading(): void
    {
        $output = $this->driveRunner(new InMemoryServerExposure(), [
            'not json',
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']),
        ]);

        $this->assertCount(2, $output);
        $this->assertSame(-32700, $output[0]['error']['code']);
        // JSON-RPC §5.1: when the request id cannot be detected the
        // response `id` MUST be null AND present so clients can tell
        // a parse-failure response apart from a notification or a
        // dropped frame.
        $this->assertArrayHasKey('id', $output[0]);
        $this->assertNull($output[0]['id']);
        $this->assertArrayHasKey('serverInfo', $output[1]['result']);
    }

    public function test_run_drops_notification_without_response(): void
    {
        $output = $this->driveRunner(new InMemoryServerExposure(), [
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']),
        ]);

        // Notification produces no output line — only the initialize response.
        $this->assertCount(1, $output);
        $this->assertSame(1, $output[0]['id']);
    }

    /**
     * @param  array<int,string> $stdinLines
     * @return array<int,array<string,mixed>>
     */
    private function driveRunner(InMemoryServerExposure $exposure, array $stdinLines): array
    {
        $stdin = fopen('php://memory', 'r+');
        $stdout = fopen('php://memory', 'r+');
        foreach ($stdinLines as $line) {
            fwrite($stdin, $line . "\n");
        }
        rewind($stdin);

        $handler = new JsonRpcRequestHandler($exposure, new NullMcpToolAuthorizer());
        (new StdioRunner($handler, $stdin, $stdout))->run();

        rewind($stdout);
        $lines = [];
        while (! feof($stdout)) {
            $line = fgets($stdout);
            if (! is_string($line)) {
                break;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }
        return $lines;
    }
}
