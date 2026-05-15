<?php

namespace Padosoft\AskMyDocsMcpPack\Resilience;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\AskMyDocsMcpPack\Resilience\Events\CircuitClosed;
use Padosoft\AskMyDocsMcpPack\Resilience\Events\CircuitHalfOpened;
use Padosoft\AskMyDocsMcpPack\Resilience\Events\CircuitOpened;

/**
 * Per-(serverId, toolName) circuit breaker with the classical
 * three-state machine and cache-backed persistence so the state is
 * shared across processes / queue workers / HTTP requests.
 *
 * State transitions:
 *
 *   CLOSED       — `recordFailure()` increments a strict CONSECUTIVE
 *                  failure counter (zeroed only by `recordSuccess`,
 *                  with no time-based decay — long quiet periods do
 *                  NOT shrink it, the entry only resets when the
 *                  cache key expires); when the counter reaches
 *                  `failureThreshold` the breaker OPENs with TTL
 *                  `recoverySeconds`.
 *
 *   OPEN         — `state()` returns OPEN until `openedAt + TTL`,
 *                  then auto-transitions to HALF_OPEN (one probe
 *                  allowed). Callers see this via `allowsCall()`.
 *
 *   HALF_OPEN    — the next `recordSuccess()` closes the breaker;
 *                  a `recordFailure()` re-OPENs it for another TTL.
 *
 * The cache layout is one key per (server, tool):
 *
 *   mcp_pack:cb:<serverId>:<toolName> = {
 *       state: 'closed' | 'open' | 'half_open',
 *       failure_count: int,        // consecutive, reset on success
 *       opened_at: int|null,       // unix ts, only when state=open
 *       last_error: string|null,
 *   }
 *
 * The cache TTL on the key is generous (3× recovery + 1h floor) so
 * the breaker survives quiet periods and the entry only expires
 * after extended inactivity — at which point CLOSED is the safe
 * default.
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly Dispatcher $events,
        private readonly int $failureThreshold = 5,
        private readonly int $recoverySeconds = 30,
        private readonly string $cachePrefix = 'mcp_pack:cb',
    ) {}

    /** Returns true when the breaker permits a real upstream call. */
    public function allowsCall(string $serverId, string $toolName): bool
    {
        return $this->state($serverId, $toolName) !== CircuitState::OPEN;
    }

    /**
     * Read-only snapshot of the breaker state for dashboards /
     * admin endpoints / logging. NEVER mutates the cache and NEVER
     * fires events — calling this from a panel or log handler will
     * NOT consume the half-open probe slot. Use this when you just
     * need to observe the state; call {@see state()} when you want
     * to participate in the state machine.
     */
    public function peekState(string $serverId, string $toolName): CircuitState
    {
        $entry = $this->load($serverId, $toolName);
        $state = CircuitState::from($entry['state']);
        if ($state !== CircuitState::OPEN) {
            return $state;
        }
        $openedAt = (int) ($entry['opened_at'] ?? 0);
        if ($openedAt > 0 && (time() - $openedAt) >= $this->recoverySeconds) {
            return CircuitState::HALF_OPEN;
        }
        return CircuitState::OPEN;
    }

    /**
     * Current state, applying the OPEN → HALF_OPEN auto-transition
     * lazily so callers never see a stale OPEN past the recovery
     * window. **Has a side effect**: when the recovery TTL has
     * elapsed this MUTATES the cache to HALF_OPEN and fires
     * `CircuitHalfOpened`. Intended for the resilience call path
     * (`allowsCall()` + the mediator); for read-only inspection
     * call {@see peekState()} instead.
     */
    public function state(string $serverId, string $toolName): CircuitState
    {
        $entry = $this->load($serverId, $toolName);
        $state = CircuitState::from($entry['state']);

        if ($state !== CircuitState::OPEN) {
            return $state;
        }

        $openedAt = (int) ($entry['opened_at'] ?? 0);
        if ($openedAt > 0 && (time() - $openedAt) >= $this->recoverySeconds) {
            // Auto-transition: the recovery window elapsed. Allow ONE
            // probe by moving to HALF_OPEN. The probe outcome
            // (success / failure) closes or re-opens the breaker.
            $entry['state'] = CircuitState::HALF_OPEN->value;
            $this->save($serverId, $toolName, $entry);
            $this->events->dispatch(new CircuitHalfOpened($serverId, $toolName));
            return CircuitState::HALF_OPEN;
        }

        return CircuitState::OPEN;
    }

    /** How many seconds until the OPEN breaker enters HALF_OPEN. */
    public function retryAfter(string $serverId, string $toolName): int
    {
        $entry = $this->load($serverId, $toolName);
        if ($entry['state'] !== CircuitState::OPEN->value) {
            return 0;
        }
        $openedAt = (int) ($entry['opened_at'] ?? 0);
        if ($openedAt === 0) {
            return $this->recoverySeconds;
        }
        $elapsed = time() - $openedAt;
        return (int) max(0, $this->recoverySeconds - $elapsed);
    }

    /**
     * Record a successful call. In HALF_OPEN this closes the
     * breaker; in CLOSED it just zeroes the failure counter.
     */
    public function recordSuccess(string $serverId, string $toolName): void
    {
        $entry = $this->load($serverId, $toolName);
        $previousState = CircuitState::from($entry['state']);

        $entry['state'] = CircuitState::CLOSED->value;
        $entry['failure_count'] = 0;
        $entry['opened_at'] = null;
        $entry['last_error'] = null;
        $this->save($serverId, $toolName, $entry);

        if ($previousState === CircuitState::HALF_OPEN) {
            $this->events->dispatch(new CircuitClosed($serverId, $toolName));
        }
    }

    /**
     * Record a failed call. Increments the consecutive-failure
     * counter and OPENs the breaker when the threshold is crossed
     * (CLOSED → OPEN) or immediately re-OPENs when the probe failed
     * (HALF_OPEN → OPEN).
     */
    public function recordFailure(string $serverId, string $toolName, ?string $error = null): void
    {
        $entry = $this->load($serverId, $toolName);
        $state = CircuitState::from($entry['state']);

        // HALF_OPEN probe failure immediately re-opens, regardless
        // of the consecutive-failure counter — the failing probe IS
        // the evidence we need. Preserve the cumulative failure
        // count from the previous OPEN so the `CircuitOpened` event
        // payload still tells operators how many failures led to the
        // original outage instead of resetting to "1" each probe.
        if ($state === CircuitState::HALF_OPEN) {
            $previousCount = (int) ($entry['failure_count'] ?? 1);
            $this->open($serverId, $toolName, $entry, max(1, $previousCount + 1), $error);
            return;
        }

        $count = (int) ($entry['failure_count'] ?? 0) + 1;
        $entry['failure_count'] = $count;
        $entry['last_error'] = $error;

        if ($count >= $this->failureThreshold) {
            $this->open($serverId, $toolName, $entry, $count, $error);
            return;
        }

        $this->save($serverId, $toolName, $entry);
    }

    /** @return array<string,mixed> */
    private function load(string $serverId, string $toolName): array
    {
        $entry = $this->cache->get($this->cacheKey($serverId, $toolName));
        if (! is_array($entry) || ! isset($entry['state'])) {
            return $this->defaultEntry();
        }
        return $entry;
    }

    /** @param array<string,mixed> $entry */
    private function save(string $serverId, string $toolName, array $entry): void
    {
        $this->cache->put(
            $this->cacheKey($serverId, $toolName),
            $entry,
            max(3 * $this->recoverySeconds, 3600),
        );
    }

    /** @param array<string,mixed> $entry */
    private function open(string $serverId, string $toolName, array $entry, int $failureCount, ?string $error): void
    {
        $entry['state'] = CircuitState::OPEN->value;
        $entry['opened_at'] = time();
        $entry['failure_count'] = $failureCount;
        $entry['last_error'] = $error;
        $this->save($serverId, $toolName, $entry);
        $this->events->dispatch(new CircuitOpened(
            serverId: $serverId,
            toolName: $toolName,
            failureCount: $failureCount,
            recoverySeconds: $this->recoverySeconds,
            lastError: $error,
        ));
    }

    private function cacheKey(string $serverId, string $toolName): string
    {
        // Lowercased + sha256-suffixed so exotic tool names (slashes,
        // non-ASCII, very long) never explode the cache-key length
        // limits some drivers enforce (memcached: 250 chars; redis is
        // lenient but consistency wins).
        $raw = $serverId . '|' . $toolName;
        return $this->cachePrefix . ':' . substr(hash('sha256', $raw), 0, 32);
    }

    /** @return array<string,mixed> */
    private function defaultEntry(): array
    {
        return [
            'state' => CircuitState::CLOSED->value,
            'failure_count' => 0,
            'opened_at' => null,
            'last_error' => null,
        ];
    }
}
