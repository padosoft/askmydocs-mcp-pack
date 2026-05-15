<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Resilience;

use Illuminate\Support\Facades\Cache;
use Padosoft\AskMyDocsMcpPack\Resilience\RetryBudget;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class RetryBudgetTest extends TestCase
{
    private function makeBudget(int $size = 3, int $window = 60): RetryBudget
    {
        return new RetryBudget(
            cache: Cache::store(),
            bucketSize: $size,
            windowSeconds: $window,
        );
    }

    public function test_starts_full_and_remaining_matches_bucket_size(): void
    {
        $budget = $this->makeBudget(size: 5);

        $this->assertSame(5, $budget->remaining('t1', 's1'));
    }

    public function test_consume_decrements_until_empty_then_fails(): void
    {
        $budget = $this->makeBudget(size: 3);

        $this->assertTrue($budget->tryConsume('t1', 's1'));
        $this->assertTrue($budget->tryConsume('t1', 's1'));
        $this->assertTrue($budget->tryConsume('t1', 's1'));
        $this->assertFalse($budget->tryConsume('t1', 's1'));
        $this->assertSame(0, $budget->remaining('t1', 's1'));
    }

    public function test_budgets_are_isolated_per_tenant(): void
    {
        $budget = $this->makeBudget(size: 1);

        $this->assertTrue($budget->tryConsume('t1', 's1'));
        $this->assertFalse($budget->tryConsume('t1', 's1'));
        // tenant-2 has its own untouched bucket.
        $this->assertTrue($budget->tryConsume('t2', 's1'));
    }

    public function test_budgets_are_isolated_per_server(): void
    {
        $budget = $this->makeBudget(size: 1);

        $this->assertTrue($budget->tryConsume('t1', 's1'));
        $this->assertFalse($budget->tryConsume('t1', 's1'));
        $this->assertTrue($budget->tryConsume('t1', 's2'));
    }

    public function test_window_rollover_refills_bucket(): void
    {
        $budget = $this->makeBudget(size: 2, window: 1);
        $this->assertTrue($budget->tryConsume('t1', 's1'));
        $this->assertTrue($budget->tryConsume('t1', 's1'));
        $this->assertFalse($budget->tryConsume('t1', 's1'));

        // Re-write the cache to simulate window expiry, no real sleep.
        $reflection = new \ReflectionClass($budget);
        $cacheKeyMethod = $reflection->getMethod('cacheKey');
        $cacheKeyMethod->setAccessible(true);
        $key = $cacheKeyMethod->invoke($budget, 't1', 's1');
        $entry = Cache::get($key);
        $entry['window_started_at'] = time() - 10;
        Cache::put($key, $entry, 60);

        $this->assertTrue($budget->tryConsume('t1', 's1'));
    }
}
