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

    /**
     * Each layer is independent and gated by its own flag so config
     * is honoured strictly:
     *
     *   - $breakerEnabled = false → no pre-check, no post-record;
     *     the breaker is effectively absent from the call path even
     *     though the instance is still injected (cheap).
     *   - $retryEnabled   = false → `maxAttempts` is clamped to 1
     *     internally, so the first transport failure surfaces to
     *     the caller without consuming the retry budget.
     *
     * The two flags map 1:1 to `mcp-pack.resilience.circuit_breaker.enabled`
     * and `mcp-pack.resilience.retry.enabled`.
     */
    public function __construct(
        private readonly CircuitBreaker $breaker,
        private readonly RetryBudget $budget,
        private readonly Dispatcher $events,
        private readonly int $maxAttempts = 3,
        private readonly int $baseBackoffMs = 200,
        private readonly int $maxBackoffMs = 5000,
        ?callable $sleep = null,
        private readonly bool $breakerEnabled = true,
        private readonly bool $retryEnabled = true,
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
        if ($this->breakerEnabled && ! $this->breaker->allowsCall($serverId, $toolName)) {
            throw new CircuitOpenException(
                message: "Circuit OPEN for [{$serverId}/{$toolName}].",
                serverId: $serverId,
                toolName: $toolName,
                retryAfterSeconds: $this->breaker->retryAfter($serverId, $toolName),
            );
        }

        // When retries are disabled the loop runs exactly once and the
        // first transport failure is re-thrown — no token consumption,
        // no RetryAttempted events, no sleep.
        $effectiveMaxAttempts = $this->retryEnabled ? $this->maxAttempts : 1;
        $attempts = 0;
        $lastError = null;

        // Any non-transport throwable thrown by `$call()` bubbles
        // out of the catch unchanged — we do not record breaker
        // failures or consume the retry budget for application
        // exceptions (caller bugs, validation, etc). The breaker
        // can therefore stay in HALF_OPEN when a probe surfaced a
        // non-transport error; this is intentional: such errors
        // tell us NOTHING about the upstream's transport health,
        // so neither closing nor re-opening would be honest. The
        // next genuine transport call (success or transport
        // failure) advances the state machine.
        while (true) {
            $attempts++;
            try {
                $result = $call();
                if ($this->breakerEnabled) {
                    $this->breaker->recordSuccess($serverId, $toolName);
                }
                return $result;
            } catch (McpTransportException $e) {
                $lastError = $e->getMessage();

                if ($attempts >= $effectiveMaxAttempts) {
                    if ($this->breakerEnabled) {
                        $this->breaker->recordFailure($serverId, $toolName, $lastError);
                    }
                    // RetryExhausted is telemetry for the retry
                    // layer; suppress it when no retry was ever
                    // attempted (effectiveMaxAttempts === 1, i.e.
                    // retries disabled OR config sets it to 1) so
                    // dashboards counting retry exhaustions don't
                    // double-count first-shot failures.
                    if ($this->retryEnabled && $effectiveMaxAttempts > 1) {
                        $this->events->dispatch(new RetryExhausted(
                            tenantId: $tenantId,
                            serverId: $serverId,
                            toolName: $toolName,
                            attempts: $attempts,
                            reason: 'max_attempts',
                            lastError: $lastError,
                        ));
                    }
                    throw $e;
                }

                if (! $this->budget->tryConsume($tenantId, $serverId)) {
                    if ($this->breakerEnabled) {
                        $this->breaker->recordFailure($serverId, $toolName, $lastError);
                    }
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
        }
    }

    private function backoffMs(int $attempt): int
    {
        // attempt is 1-indexed; first retry waits exactly `base`.
        // Clamp the exponent at 30 so the bit-shift never overflows
        // PHP_INT_MAX on 32-bit-int builds (1 << 31 turns negative)
        // even if an operator configures a pathological maxAttempts.
        // The min() against $maxBackoffMs caps the practical value.
        $exponent = min(max(0, $attempt - 1), 30);
        $raw = $this->baseBackoffMs * (1 << $exponent);
        return (int) min($raw, $this->maxBackoffMs);
    }
}
