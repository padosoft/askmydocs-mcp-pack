<?php

namespace Padosoft\AskMyDocsMcpPack\Transports;

use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
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
 * ## v1.0 limitation — single-shot per request
 *
 * The transport spawns a fresh child process for EACH JSON-RPC
 * request (initialize, tools/list, tools/call …) and closes stdin
 * right after the message goes out. This is correct for stateless
 * MCP servers (filesystem, public-API wrappers) but the canonical
 * MCP spec defines stdio as a persistent session where `initialize`
 * + `initialized` set up state that `tools/list` and `tools/call`
 * rely on. Stateful servers — including many official reference
 * implementations — will refuse to respond to a second message
 * because their internal state machine is back at the start.
 *
 * For v1.0 we recommend the HTTP transport for production
 * tool-calling workloads, OR subclassing this transport to keep the
 * Process open across requests. Persistent stdio sessions land in
 * v1.1 (see Roadmap in the README).
 *
 * Hosts that need persistent stdio today should subclass and
 * override {@see makeProcess()} / hold a single Process across
 * `request()` calls.
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
        } catch (\Throwable $e) {
            // Catches ProcessFailedException, ProcessTimedOutException,
            // and any other Symfony Process exception — keeps the
            // transport contract consistent.
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
        try {
            $process->setInput($notification->toJson() . "\n");
            $process->setTimeout($this->timeoutSeconds());
            $process->run();
        } catch (\Throwable $e) {
            throw new McpTransportException("Stdio MCP transport notify failed: {$e->getMessage()}", 0, $e);
        }

        if (! $process->isSuccessful()) {
            throw new McpTransportException(
                "Stdio MCP transport notify exited non-zero ({$process->getExitCode()}): "
                . $process->getErrorOutput(),
            );
        }
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
