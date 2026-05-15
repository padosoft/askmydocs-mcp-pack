<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Resilience;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Padosoft\AskMyDocsMcpPack\Resilience\CircuitBreaker;
use Padosoft\AskMyDocsMcpPack\Resilience\CircuitState;
use Padosoft\AskMyDocsMcpPack\Resilience\Events\CircuitClosed;
use Padosoft\AskMyDocsMcpPack\Resilience\Events\CircuitHalfOpened;
use Padosoft\AskMyDocsMcpPack\Resilience\Events\CircuitOpened;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    private function makeBreaker(int $threshold = 3, int $recovery = 1): CircuitBreaker
    {
        return new CircuitBreaker(
            cache: Cache::store(),
            events: $this->app['events'],
            failureThreshold: $threshold,
            recoverySeconds: $recovery,
        );
    }

    public function test_starts_closed_and_allows_calls(): void
    {
        $cb = $this->makeBreaker();

        $this->assertSame(CircuitState::CLOSED, $cb->state('srv', 'tool'));
        $this->assertTrue($cb->allowsCall('srv', 'tool'));
    }

    public function test_opens_after_threshold_failures_and_fires_event(): void
    {
        Event::fake([CircuitOpened::class]);
        $cb = $this->makeBreaker(threshold: 3);

        $cb->recordFailure('srv', 'tool', 'timeout');
        $cb->recordFailure('srv', 'tool', 'timeout');
        $this->assertSame(CircuitState::CLOSED, $cb->state('srv', 'tool'));
        $cb->recordFailure('srv', 'tool', 'timeout');

        $this->assertSame(CircuitState::OPEN, $cb->state('srv', 'tool'));
        $this->assertFalse($cb->allowsCall('srv', 'tool'));
        Event::assertDispatched(CircuitOpened::class, function (CircuitOpened $e): bool {
            return $e->serverId === 'srv'
                && $e->toolName === 'tool'
                && $e->failureCount === 3
                && $e->lastError === 'timeout';
        });
    }

    public function test_success_resets_failure_counter(): void
    {
        $cb = $this->makeBreaker(threshold: 3);

        $cb->recordFailure('srv', 'tool');
        $cb->recordFailure('srv', 'tool');
        $cb->recordSuccess('srv', 'tool');
        $cb->recordFailure('srv', 'tool');

        // Counter reset → still closed after 1 fresh failure.
        $this->assertSame(CircuitState::CLOSED, $cb->state('srv', 'tool'));
    }

    public function test_open_auto_transitions_to_half_open_after_ttl(): void
    {
        Event::fake([CircuitHalfOpened::class]);
        $cb = $this->makeBreaker(threshold: 1, recovery: 1);

        $cb->recordFailure('srv', 'tool');
        $this->assertSame(CircuitState::OPEN, $cb->state('srv', 'tool'));
        $this->assertGreaterThan(0, $cb->retryAfter('srv', 'tool'));

        // Roll the recovery window by re-writing the cache entry
        // with an older opened_at — deterministic, no real sleep.
        $reflection = new \ReflectionClass($cb);
        $cacheKeyMethod = $reflection->getMethod('cacheKey');
        $cacheKeyMethod->setAccessible(true);
        $key = $cacheKeyMethod->invoke($cb, 'srv', 'tool');
        $entry = Cache::get($key);
        $entry['opened_at'] = time() - 5;
        Cache::put($key, $entry, 3600);

        $this->assertSame(CircuitState::HALF_OPEN, $cb->state('srv', 'tool'));
        $this->assertTrue($cb->allowsCall('srv', 'tool'));
        Event::assertDispatched(CircuitHalfOpened::class);
    }

    public function test_half_open_success_closes_breaker_and_fires_event(): void
    {
        Event::fake([CircuitClosed::class]);
        $cb = $this->makeBreaker(threshold: 1, recovery: 1);

        $cb->recordFailure('srv', 'tool');
        $reflection = new \ReflectionClass($cb);
        $cacheKeyMethod = $reflection->getMethod('cacheKey');
        $cacheKeyMethod->setAccessible(true);
        $key = $cacheKeyMethod->invoke($cb, 'srv', 'tool');
        $entry = Cache::get($key);
        $entry['opened_at'] = time() - 5;
        Cache::put($key, $entry, 3600);

        // Trigger the half-open transition.
        $cb->state('srv', 'tool');
        $cb->recordSuccess('srv', 'tool');

        $this->assertSame(CircuitState::CLOSED, $cb->state('srv', 'tool'));
        Event::assertDispatched(CircuitClosed::class);
    }

    public function test_half_open_failure_re_opens_breaker_immediately(): void
    {
        // threshold=1 so the first failure OPENs the breaker; then we
        // back-date opened_at to roll the recovery TTL and force the
        // lazy OPEN→HALF_OPEN transition.
        $cb = $this->makeBreaker(threshold: 1, recovery: 1);

        $cb->recordFailure('srv', 'tool');
        $reflection = new \ReflectionClass($cb);
        $cacheKeyMethod = $reflection->getMethod('cacheKey');
        $cacheKeyMethod->setAccessible(true);
        $key = $cacheKeyMethod->invoke($cb, 'srv', 'tool');
        $entry = Cache::get($key);
        $entry['opened_at'] = time() - 5;
        Cache::put($key, $entry, 3600);

        $this->assertSame(CircuitState::HALF_OPEN, $cb->state('srv', 'tool'));
        $cb->recordFailure('srv', 'tool', 'probe failed');

        // HALF_OPEN failure re-OPENs immediately — the probe failure
        // IS the evidence we need, no need to retrigger the threshold.
        $this->assertSame(CircuitState::OPEN, $cb->state('srv', 'tool'));
    }

    public function test_state_is_isolated_per_tool(): void
    {
        $cb = $this->makeBreaker(threshold: 1, recovery: 60);

        $cb->recordFailure('srv', 'tool-a');

        $this->assertSame(CircuitState::OPEN, $cb->state('srv', 'tool-a'));
        $this->assertSame(CircuitState::CLOSED, $cb->state('srv', 'tool-b'));
    }
}
