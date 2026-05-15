<?php

namespace Padosoft\AskMyDocsMcpPack\Resilience;

use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\AskMyDocsMcpPack\Exceptions\CircuitOpenException;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Resilience\Events\RetryAttempted;
use Padosoft\AskMyDocsMcpPack\Resilience\Events\RetryExhausted;

/**
 * Wraps a single upstream call (closure) with three layers of
 * resilience:
 *
 *   1. PRE-CHECK    — short-circuit via `CircuitBreaker::allowsCall`
 *                     if the breaker is OPEN. The caller sees a
 *                     `CircuitOpenException` (which extends
 *                     `McpTransportException`) without the upstream
 *                     ever being touched.
 *
 *   2. RETRY LOOP   — on `McpTransportException` the mediator
 *                     consults `RetryBudget::tryConsume(tenant,
 *                     server)`; if a token is available it sleeps
 *                     `baseBackoffMs * 2^(attempt-1)` (capped at
 *                     `maxBackoffMs`) and tries again, up to
 *                     `maxAttempts` (total tries = 1 + retries).
 *                     Non-transport exceptions are NOT retried.
 *
 *   3. POST-RECORD  — every outcome feeds the breaker:
 *                     `recordSuccess` on a clean return,
 *                     `recordFailure(error)` on the final failure.
 *
 * The `sleep` is injectable so tests don't actually wait. Pass
 * `static fn(int $ms) => null` to make the suite deterministic
 * without real backoff.
 *
 * @phpstan-type SleepFn callable(int $milliseconds): void
 */
final class ResilienceMediator
{
    /** @var callable(int): void */
    private $sleep;

    public function __construct(
        private readonly CircuitBreaker $breaker,
        private readonly RetryBudget $budget,
        private readonly Dispatcher $events,
        private readonly int $maxAttempts = 3,
        private readonly int $baseBackoffMs = 200,
        private readonly int $maxBackoffMs = 5000,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
    }

    /**
     * Run the callable under the resilience policy.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     * @throws McpTransportException
     */
    public function execute(
        string $tenantId,
        string $serverId,
        string $toolName,
        callable $call,
    ): mixed {
        if (! $this->breaker->allowsCall($serverId, $toolName)) {
            throw new CircuitOpenException(
                message: "Circuit OPEN for [{$serverId}/{$toolName}].",
                serverId: $serverId,
                toolName: $toolName,
                retryAfterSeconds: $this->breaker->retryAfter($serverId, $toolName),
            );
        }

        $attempts = 0;
        $lastError = null;

        while (true) {
            $attempts++;
            try {
                $result = $call();
                $this->breaker->recordSuccess($serverId, $toolName);
                return $result;
            } catch (McpTransportException $e) {
                $lastError = $e->getMessage();

                if ($attempts >= $this->maxAttempts) {
                    $this->breaker->recordFailure($serverId, $toolName, $lastError);
                    $this->events->dispatch(new RetryExhausted(
                        tenantId: $tenantId,
                        serverId: $serverId,
                        toolName: $toolName,
                        attempts: $attempts,
                        reason: 'max_attempts',
                        lastError: $lastError,
                    ));
                    throw $e;
                }

                if (! $this->budget->tryConsume($tenantId, $serverId)) {
                    $this->breaker->recordFailure($serverId, $toolName, $lastError);
                    $this->events->dispatch(new RetryExhausted(
                        tenantId: $tenantId,
                        serverId: $serverId,
                        toolName: $toolName,
                        attempts: $attempts,
                        reason: 'budget_depleted',
                        lastError: $lastError,
                    ));
                    throw $e;
                }

                $backoff = $this->backoffMs($attempts);
                $this->events->dispatch(new RetryAttempted(
                    tenantId: $tenantId,
                    serverId: $serverId,
                    toolName: $toolName,
                    attempt: $attempts,
                    backoffMs: $backoff,
                    lastError: $lastError,
                ));
                ($this->sleep)($backoff);
                // Loop and retry.
            }
            // Any non-transport throwable bubbles out unchanged —
            // we do not record breaker failures for application
            // exceptions (caller bugs, validation, etc).
        }
    }

    private function backoffMs(int $attempt): int
    {
        // attempt is 1-indexed; first retry waits exactly base.
        $raw = $this->baseBackoffMs * (1 << ($attempt - 1));
        return (int) min($raw, $this->maxBackoffMs);
    }
}
