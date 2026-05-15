<?php

namespace Padosoft\AskMyDocsMcpPack\Exceptions;

/**
 * Raised by `Resilience\ResilienceMediator` when an upstream call is
 * short-circuited because the per-(server, tool) breaker is OPEN.
 *
 * It deliberately extends `McpTransportException` so callers that
 * already handle "transport failed" (e.g. `ToolInvoker`) treat the
 * fast-fail path identically to a network error — same status code,
 * same audit row — without needing breaker-specific knowledge. The
 * recovery TTL is surfaced so callers / dashboards can display
 * "will retry in N seconds" without re-reading the cache.
 */
class CircuitOpenException extends McpTransportException
{
    public function __construct(
        string $message,
        public readonly string $serverId,
        public readonly string $toolName,
        public readonly int $retryAfterSeconds,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
