<?php

namespace Padosoft\AskMyDocsMcpPack\Transports;

use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Symfony\Component\Process\Exception\ProcessFailedException;
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
 *   - boot_grace_ms: int — milliseconds to wait for the child to come
 *                    up before issuing the first request (default 250)
 *
 * The transport is single-shot per request: it spawns, sends, reads
 * one matching response, terminates. This trades per-call latency
 * for connection-pool simplicity — fine for low-throughput admin
 * tooling, NOT recommended for chat-time tool-calling under load.
 * Hosts that need persistent stdio should subclass and keep the
 * Process open across requests.
 */
class StdioJsonRpcTransport implements McpTransportContract
{
    /** @param array<string,mixed> $config */
    public function __construct(protected readonly array $config) {}

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        if (! $request->isRequest()) {
            throw new \InvalidArgumentException('StdioJsonRpcTransport::request() requires a JSON-RPC request message.');
        }

        $process = $this->makeProcess();
        try {
            $process->setInput($request->toJson() . "\n");
            $process->setTimeout($this->timeoutSeconds());
            $process->run();
        } catch (ProcessFailedException $e) {
            throw new McpTransportException("Stdio MCP transport process failed: {$e->getMessage()}", 0, $e);
        }

        if (! $process->isSuccessful()) {
            throw new McpTransportException(
                "Stdio MCP transport process exited non-zero ({$process->getExitCode()}): "
                . $process->getErrorOutput(),
            );
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            throw new McpTransportException('Stdio MCP transport produced empty output.');
        }

        return $this->parseResponseLine($output, $request->id);
    }

    public function notify(JsonRpcMessage $notification): void
    {
        if (! $notification->isNotification()) {
            throw new \InvalidArgumentException('StdioJsonRpcTransport::notify() requires a JSON-RPC notification.');
        }

        $process = $this->makeProcess();
        $process->setInput($notification->toJson() . "\n");
        $process->setTimeout($this->timeoutSeconds());
        $process->run();
        // notifications have no response — exit code is the only signal
    }

    public function isHealthy(): bool
    {
        $command = (string) ($this->config['command'] ?? '');
        if ($command === '') {
            return false;
        }

        // Cheap presence-check: does the executable exist somewhere on PATH?
        $finder = new \Symfony\Component\Process\ExecutableFinder();
        return $finder->find($command) !== null;
    }

    /**
     * Read one newline-delimited JSON line and parse it as the
     * response to `$expectedId`. If multiple lines came back, prefer
     * the line whose id matches.
     */
    private function parseResponseLine(string $output, string|int|null $expectedId): JsonRpcMessage
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

        throw new McpTransportException("Stdio MCP transport: no JSON-RPC response in output: {$output}");
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
