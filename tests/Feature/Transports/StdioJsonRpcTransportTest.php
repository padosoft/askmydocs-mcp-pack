<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Transports;

use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;
use Padosoft\AskMyDocsMcpPack\Transports\StdioJsonRpcTransport;
use Symfony\Component\Process\Process;

class StdioJsonRpcTransportTest extends TestCase
{
    public function test_replacement_process_does_not_inherit_partial_output_from_a_crashed_child(): void
    {
        $fixtures = dirname(__DIR__, 2).'/Fixtures';
        $transport = new class(['command' => PHP_BINARY, 'timeout_ms' => 30_000], $fixtures) extends StdioJsonRpcTransport
        {
            public int $spawns = 0;

            public function __construct(array $config, private readonly string $fixtures)
            {
                parent::__construct($config);
            }

            protected function makeProcess(): Process
            {
                $script = ++$this->spawns === 1 ? 'stdio-partial-then-exit.php' : 'stdio-echo-response.php';

                return new Process([PHP_BINARY, $this->fixtures.'/'.$script]);
            }
        };

        try {
            $transport->request(JsonRpcMessage::request(1, 'tools/list'));
            $this->fail('A child that exits mid-line must surface as a transport failure.');
        } catch (McpTransportException) {
            // expected: the first child wrote a partial line and exited
        }

        // The retry starts a fresh child; stale bytes from the crashed one must not
        // be glued in front of its first (valid) response line.
        $response = $transport->request(JsonRpcMessage::request(2, 'tools/list'));

        $this->assertSame(2, $transport->spawns);
        $this->assertSame(2, $response->id);
        $this->assertSame(['ok' => true], $response->result);
        $transport->close();
    }
}
