<?php

namespace Padosoft\AskMyDocsMcpPack\ServerSide;

use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

/**
 * v1.2.0 — long-lived stdio runner. Reads newline-delimited JSON-RPC
 * messages from STDIN, dispatches each through
 * {@see JsonRpcRequestHandler}, and writes responses to STDOUT
 * (newline-delimited per the MCP stdio profile).
 *
 * The class is intentionally small so the artisan `mcp-pack:serve`
 * command can boot it without a Symfony Console hairball. STDIN /
 * STDOUT streams are injected so tests can drive the runner against
 * `php://memory` resources.
 *
 * Loop termination:
 *   - EOF on STDIN → graceful exit
 *   - JSON parse failure → emits a `-32700` parse-error response and
 *     keeps reading
 *   - Inner handler exception → caught by the handler and surfaced
 *     as a JSON-RPC error response; runner continues
 *
 * The runner does NOT enforce auth (stdio is local-only by spec).
 * The host can wrap by passing a custom `$context` array per turn
 * if needed.
 */
final class StdioRunner
{
    /**
     * @param resource|null $stdin
     * @param resource|null $stdout
     */
    public function __construct(
        private readonly JsonRpcRequestHandler $handler,
        private $stdin = null,
        private $stdout = null,
    ) {
        $this->stdin ??= STDIN;
        $this->stdout ??= STDOUT;
    }

    /** @param array<string,mixed> $context */
    public function run(array $context = []): void
    {
        while (! feof($this->stdin)) {
            $line = fgets($this->stdin);
            if ($line === false) {
                // EOF or read error — exit cleanly. The MCP client is
                // expected to terminate the process; this is the
                // graceful path.
                return;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $response = $this->dispatch($line, $context);
            if ($response !== null) {
                fwrite($this->stdout, $response->toJson() . "\n");
                fflush($this->stdout);
            }
        }
    }

    /** @param array<string,mixed> $context */
    private function dispatch(string $line, array $context): ?JsonRpcMessage
    {
        try {
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // JSON-RPC 2.0 §5.1 — when an id cannot be detected the
            // response `id` MUST be null. Using 0 here misled clients
            // because they could not correlate the parse-error
            // response to any request they made.
            return JsonRpcMessage::errorResponse(null, -32700, "Parse error: {$e->getMessage()}");
        }
        if (! is_array($decoded)) {
            return JsonRpcMessage::errorResponse(null, -32600, 'Invalid request: payload is not a JSON object.');
        }

        $message = JsonRpcMessage::fromArray($decoded);
        return $this->handler->handle($message, $context);
    }
}
