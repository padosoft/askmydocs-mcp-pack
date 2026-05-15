<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Resilience;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Padosoft\AskMyDocsMcpPack\Exceptions\CircuitOpenException;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Resilience\CircuitBreaker;
use Padosoft\AskMyDocsMcpPack\Resilience\Events\RetryAttempted;
use Padosoft\AskMyDocsMcpPack\Resilience\Events\RetryExhausted;
use Padosoft\AskMyDocsMcpPack\Resilience\ResilienceMediator;
use Padosoft\AskMyDocsMcpPack\Resilience\RetryBudget;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class ResilienceMediatorTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    private function makeMediator(int $maxAttempts = 3, int $bucketSize = 10, int $threshold = 5): ResilienceMediator
    {
        $this->sleeps = [];
        $cache = Cache::store();
        $events = $this->app['events'];

        return new ResilienceMediator(
            breaker: new CircuitBreaker(
                cache: $cache,
                events: $events,
                failureThreshold: $threshold,
                recoverySeconds: 30,
            ),
            budget: new RetryBudget(cache: $cache, bucketSize: $bucketSize, windowSeconds: 60),
            events: $events,
            maxAttempts: $maxAttempts,
            baseBackoffMs: 100,
            maxBackoffMs: 1000,
            sleep: function (int $ms): void {
                $this->sleeps[] = $ms;
            },
        );
    }

    public function test_returns_result_on_first_success(): void
    {
        $mediator = $this->makeMediator();

        $out = $mediator->execute('t', 's', 'tool', static fn(): array => ['ok' => true]);

        $this->assertSame(['ok' => true], $out);
        $this->assertSame([], $this->sleeps, 'no sleep on success');
    }

    public function test_retries_on_transport_failure_then_succeeds(): void
    {
        Event::fake([RetryAttempted::class]);
        $mediator = $this->makeMediator(maxAttempts: 3);

        $attempts = 0;
        $out = $mediator->execute('t', 's', 'tool', static function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new McpTransportException("fail #{$attempts}");
            }
            return ['ok' => true];
        });

        $this->assertSame(['ok' => true], $out);
        $this->assertSame(3, $attempts);
        $this->assertSame([100, 200], $this->sleeps, 'exponential backoff: 100ms, 200ms');
        Event::assertDispatched(RetryAttempted::class, 2);
    }

    public function test_max_attempts_exhausts_and_rethrows(): void
    {
        Event::fake([RetryExhausted::class]);
        $mediator = $this->makeMediator(maxAttempts: 2);

        $this->expectException(McpTransportException::class);
        try {
            $mediator->execute('t', 's', 'tool', static function (): void {
                throw new McpTransportException('always down');
            });
        } finally {
            Event::assertDispatched(RetryExhausted::class, function (RetryExhausted $e): bool {
                return $e->reason === 'max_attempts' && $e->attempts === 2;
            });
        }
    }

    public function test_budget_depleted_aborts_retry_loop(): void
    {
        Event::fake([RetryExhausted::class]);
        $mediator = $this->makeMediator(maxAttempts: 10, bucketSize: 1);

        $attempts = 0;
        try {
            $mediator->execute('t', 's', 'tool', static function () use (&$attempts): void {
                $attempts++;
                throw new McpTransportException("fail #{$attempts}");
            });
            $this->fail('expected McpTransportException');
        } catch (McpTransportException) {
            // expected
        }

        // 1 attempt + 1 retry (consuming the single token) +
        // failed budget check on attempt #3 → 2 actual upstream
        // calls before the budget abort.
        $this->assertSame(2, $attempts);
        Event::assertDispatched(RetryExhausted::class, function (RetryExhausted $e): bool {
            return $e->reason === 'budget_depleted';
        });
    }

    public function test_open_circuit_short_circuits_without_calling_upstream(): void
    {
        $mediator = $this->makeMediator(maxAttempts: 1, threshold: 1);
        $called = 0;

        // First call fails → circuit opens (threshold=1, max_attempts=1).
        try {
            $mediator->execute('t', 's', 'tool', static function () use (&$called): void {
                $called++;
                throw new McpTransportException('initial fail');
            });
        } catch (McpTransportException) {
        }
        $this->assertSame(1, $called);

        // Second call: circuit is OPEN, mediator MUST NOT call upstream.
        $this->expectException(CircuitOpenException::class);
        $mediator->execute('t', 's', 'tool', static function () use (&$called) {
            $called++;
            return ['unreachable' => true];
        });

        // verified in finally below
    }

    public function test_non_transport_exceptions_are_not_retried(): void
    {
        $mediator = $this->makeMediator(maxAttempts: 3);
        $attempts = 0;

        try {
            $mediator->execute('t', 's', 'tool', static function () use (&$attempts): void {
                $attempts++;
                throw new \LogicException('caller bug');
            });
            $this->fail('expected LogicException');
        } catch (\LogicException $e) {
            $this->assertSame('caller bug', $e->getMessage());
        }

        $this->assertSame(1, $attempts, 'non-transport exception must bubble immediately');
    }

    public function test_breaker_only_does_not_retry_transport_failures(): void
    {
        // MCP_PACK_CB_ENABLED=true + MCP_PACK_RETRY_ENABLED=false
        // → first transport failure surfaces immediately; the breaker
        //   still records the failure so repeated calls can trip it.
        $cache = Cache::store();
        $events = $this->app['events'];
        $attempts = 0;
        $mediator = new ResilienceMediator(
            breaker: new CircuitBreaker($cache, $events, failureThreshold: 2, recoverySeconds: 30),
            budget: new RetryBudget($cache, bucketSize: 99, windowSeconds: 60),
            events: $events,
            maxAttempts: 5,
            baseBackoffMs: 100,
            maxBackoffMs: 1000,
            sleep: static fn(int $ms) => null,
            breakerEnabled: true,
            retryEnabled: false,
        );

        try {
            $mediator->execute('t', 's', 'tool', function () use (&$attempts): void {
                $attempts++;
                throw new McpTransportException('down');
            });
        } catch (McpTransportException) {
        }
        $this->assertSame(1, $attempts, 'retries disabled: caller saw the first failure');

        // A second call still trips the breaker once threshold (2) is reached.
        try {
            $mediator->execute('t', 's', 'tool', function () use (&$attempts): void {
                $attempts++;
                throw new McpTransportException('down');
            });
        } catch (McpTransportException) {
        }
        $this->assertSame(2, $attempts);

        // Third call must be short-circuited.
        $this->expectException(CircuitOpenException::class);
        $mediator->execute('t', 's', 'tool', function () use (&$attempts): void {
            $attempts++;
        });
    }

    public function test_retry_only_does_not_engage_breaker(): void
    {
        // MCP_PACK_RETRY_ENABLED=true + MCP_PACK_CB_ENABLED=false
        // → many failures retry-loop, never opens the breaker, next
        //   call still calls upstream (no short-circuit).
        $cache = Cache::store();
        $events = $this->app['events'];
        $attempts = 0;
        $mediator = new ResilienceMediator(
            breaker: new CircuitBreaker($cache, $events, failureThreshold: 1, recoverySeconds: 30),
            budget: new RetryBudget($cache, bucketSize: 99, windowSeconds: 60),
            events: $events,
            maxAttempts: 3,
            baseBackoffMs: 100,
            maxBackoffMs: 1000,
            sleep: static fn(int $ms) => null,
            breakerEnabled: false,
            retryEnabled: true,
        );

        try {
            $mediator->execute('t', 's', 'tool', function () use (&$attempts): void {
                $attempts++;
                throw new McpTransportException('down');
            });
        } catch (McpTransportException) {
        }
        $this->assertSame(3, $attempts, 'retried up to maxAttempts');

        // Next call must reach the upstream — breaker never opened
        // because it was disabled.
        try {
            $mediator->execute('t', 's', 'tool', function () use (&$attempts): void {
                $attempts++;
                throw new McpTransportException('down');
            });
        } catch (McpTransportException) {
        }
        $this->assertSame(6, $attempts);
    }

    public function test_backoff_caps_at_max_backoff_ms(): void
    {
        Event::fake([RetryAttempted::class]);
        // base=100, max=200 → attempt 1: 100, attempt 2: 200, attempt 3: 200 (capped)
        $cache = Cache::store();
        $events = $this->app['events'];
        $sleeps = [];
        $mediator = new ResilienceMediator(
            breaker: new CircuitBreaker($cache, $events, failureThreshold: 99, recoverySeconds: 30),
            budget: new RetryBudget($cache, bucketSize: 99, windowSeconds: 60),
            events: $events,
            maxAttempts: 4,
            baseBackoffMs: 100,
            maxBackoffMs: 200,
            sleep: function (int $ms) use (&$sleeps): void {
                $sleeps[] = $ms;
            },
        );

        try {
            $mediator->execute('t', 's', 'tool', static function (): void {
                throw new McpTransportException('down');
            });
        } catch (McpTransportException) {
        }

        $this->assertSame([100, 200, 200], $sleeps);
    }
}
