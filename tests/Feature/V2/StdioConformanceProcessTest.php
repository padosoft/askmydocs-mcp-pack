<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\V2;

use Padosoft\AskMyDocsMcpPack\Tests\TestCase;
use Symfony\Component\Process\Process;

final class StdioConformanceProcessTest extends TestCase
{
    public function test_real_stdio_process_discovers_and_calls_a_fluent_tool(): void
    {
        $messages = [
            $this->request('discover', 'server/discover'),
            $this->request('list', 'tools/list'),
            $this->request('call', 'tools/call', ['name' => 'echo', 'arguments' => ['value' => 'wire-ok']]),
        ];
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2).'/Fixtures/conformance-server.php',
        ], dirname(__DIR__, 3));
        $process->setInput(implode("\n", array_map(
            static fn (array $message): string => json_encode($message, JSON_THROW_ON_ERROR),
            $messages,
        ))."\n");
        $process->setTimeout(15);
        $process->mustRun();

        $responses = array_values(array_filter(array_map(
            static fn (string $line): mixed => json_decode($line, true, 32, JSON_THROW_ON_ERROR),
            preg_split('/\R/', trim($process->getOutput())) ?: [],
        )));

        $this->assertSame('2026-07-28', $responses[0]['result']['protocolVersion']);
        $this->assertSame('echo', $responses[1]['result']['tools'][0]['name']);
        $this->assertSame(['echo' => 'wire-ok'], $responses[2]['result']['structuredContent']);
        $this->assertSame(['complete', 'complete', 'complete'], array_column(array_column($responses, 'result'), 'resultType'));
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function request(string $id, string $method, array $params = []): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ];
        $params['clientInfo'] = ['name' => 'process-conformance-test', 'version' => '1.0.0'];

        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
    }
}
