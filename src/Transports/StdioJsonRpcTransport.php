<?php

namespace Padosoft\AskMyDocsMcpPack\Transports;

use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * JSON-RPC over stdio — spawns the MCP server as a child process and
 * pipes newline-delimited JSON over stdin/stdout. This is the
 * canonical MCP transport (Claude Desktop / Cursor / VS Code).
 *
 * Config keys (passed by {@see McpServerContract::transportConfig()}):
 *   - command: string — executable to spawn (e.g. `npx`)
 *   - args:    array<int,string> — argv (e.g. `['-y','@modelcontextprotocol/server-filesystem','/data']`)
 *   - cwd:     string|null — working directory
 *   - env:     array<string,string>|null — environment variables
 *   - timeout_ms: int — per-request timeout (default 10_000)
 *
 * The child process is persistent for the lifetime of this transport.
 * That is required by the session-based legacy revisions and also
 * avoids process-start overhead for independent 2026-07-28 requests.
 * Responses are correlated by JSON-RPC id while notifications and
 * unrelated output lines remain available to subsequent reads.
 */
class StdioJsonRpcTransport implements McpTransportContract
{
    private ?Process $process = null;

    private ?InputStream $input = null;

    private string $buffer = '';

    /** @param array<string,mixed> $config */
    public function __construct(protected readonly array $config) {}

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        if (! $request->isRequest()) {
            throw new \InvalidArgumentException('StdioJsonRpcTransport::request() requires a JSON-RPC request message.');
        }

        try {
            $process = $this->runningProcess();
            $this->input?->write($request->toJson()."\n");

            return $this->waitForResponse($request->id);
        } catch (\Throwable $e) {
            // Catches ProcessFailedException, ProcessTimedOutException,
            // and any other Symfony Process exception — keeps the
            // transport contract consistent.
            throw new McpTransportException("Stdio MCP transport process failed: {$e->getMessage()}", 0, $e);
        }

    }

    public function notify(JsonRpcMessage $notification): void
    {
        if (! $notification->isNotification()) {
            throw new \InvalidArgumentException('StdioJsonRpcTransport::notify() requires a JSON-RPC notification.');
        }

        try {
            $this->runningProcess();
            $this->input?->write($notification->toJson()."\n");
        } catch (\Throwable $e) {
            throw new McpTransportException("Stdio MCP transport notify failed: {$e->getMessage()}", 0, $e);
        }

    }

    public function isHealthy(): bool
    {
        $command = (string) ($this->config['command'] ?? '');
        if ($command === '') {
            return false;
        }

        // Cheap presence-check: does the executable exist somewhere on PATH?
        $finder = new ExecutableFinder;

        return $finder->find($command) !== null;
    }

    /**
     * Read one newline-delimited JSON line and parse it as the
     * response to `$expectedId`. If multiple lines came back, prefer
     * the line whose id matches.
     */
    private function parseResponseLine(string $output, string|int|null $expectedId): ?JsonRpcMessage
    {
        $lines = preg_split('/\r?\n/', $output, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($lines as $line) {
            $payload = json_decode($line, true);
            if (! is_array($payload)) {
                continue;
            }
            if (($payload['id'] ?? null) === $expectedId) {
                return JsonRpcMessage::fromArray($payload);
            }
        }

        // No matching id — return the last response-shaped line we saw.
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $payload = json_decode($lines[$i], true);
            if (is_array($payload) && isset($payload['jsonrpc'])) {
                return JsonRpcMessage::fromArray($payload);
            }
        }

        return null;
    }

    private function runningProcess(): Process
    {
        if ($this->process?->isRunning()) {
            return $this->process;
        }
        // Starting a replacement child: drop any partial line left over from a
        // previous process that exited mid-message, otherwise those stale bytes
        // would be glued in front of the new child's first response and make it
        // unparsable. The old input stream (if any) is closed for the same reason.
        $this->input?->close();
        $this->buffer = '';
        $this->input = new InputStream;
        $this->process = $this->makeProcess();
        $this->process->setInput($this->input);
        $this->process->setTimeout(null);
        $this->process->start();
        if (! $this->process->isRunning()) {
            throw new McpTransportException('Stdio MCP transport process did not start.');
        }

        return $this->process;
    }

    private function waitForResponse(string|int|null $expectedId): JsonRpcMessage
    {
        $deadline = microtime(true) + $this->timeoutSeconds();
        do {
            // Sample liveness BEFORE draining output: a child that answers and exits
            // between the two calls has already flushed everything, so reading after
            // the status check never loses its final line.
            $running = $this->process?->isRunning() ?? false;
            $this->buffer .= $this->process?->getIncrementalOutput() ?? '';
            $lines = preg_split('/\r?\n/', $this->buffer) ?: [];
            $this->buffer = array_pop($lines) ?? '';
            foreach ($lines as $line) {
                $response = $this->parseResponseLine($line, $expectedId);
                if ($response !== null && $response->id === $expectedId) {
                    return $response;
                }
            }
            if (! $running) {
                throw new McpTransportException('Stdio MCP transport exited before responding: '.($this->process?->getErrorOutput() ?? ''));
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        throw new McpTransportException('Stdio MCP transport timed out waiting for a matching response.');
    }

    public function close(): void
    {
        $this->input?->close();
        if ($this->process?->isRunning()) {
            $this->process->stop(1);
        }
        $this->process = null;
        $this->input = null;
        $this->buffer = '';
    }

    public function __destruct()
    {
        $this->close();
    }

    protected function makeProcess(): Process
    {
        $command = (string) ($this->config['command'] ?? '');
        if ($command === '') {
            throw new McpTransportException('Stdio MCP transport: command is missing from transport config.');
        }

        $args = $this->config['args'] ?? [];
        if (! is_array($args)) {
            $args = [];
        }

        $cwd = $this->config['cwd'] ?? null;
        $env = $this->config['env'] ?? null;
        if ($env !== null && ! is_array($env)) {
            $env = null;
        }

        return new Process(
            command: array_merge([$command], array_map('strval', $args)),
            cwd: is_string($cwd) ? $cwd : null,
            env: $env,
        );
    }

    private function timeoutSeconds(): float
    {
        $ms = (int) ($this->config['timeout_ms'] ?? 10_000);

        return max(0.5, $ms / 1000);
    }
}
