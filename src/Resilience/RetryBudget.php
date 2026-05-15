<?php

namespace Padosoft\AskMyDocsMcpPack\Resilience;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Token-bucket retry budget per (tenantId, serverId).
 *
 * Each retry consumes one token. The bucket refills back to
 * `bucketSize` over a sliding `windowSeconds` window: when the
 * stored timestamp is older than the window, the bucket resets to
 * full before the consume attempt — this approximates a fixed
 * window with leaky semantics without needing a background refill
 * job.
 *
 * The cache layout is one key per (tenant, server):
 *
 *   mcp_pack:rb:<tenantId>:<serverId> = {
 *       tokens: int,
 *       window_started_at: int,
 *   }
 *
 * Cross-tenant isolation is the whole point of keying on tenantId
 * — a misbehaving tenant cannot exhaust another tenant's retry
 * budget against the same upstream server.
 */
final class RetryBudget
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $bucketSize = 20,
        private readonly int $windowSeconds = 60,
        private readonly string $cachePrefix = 'mcp_pack:rb',
    ) {}

    /**
     * Attempt to consume one token. Returns true if the budget had
     * room; false when depleted (the caller MUST stop retrying and
     * surface the underlying error).
     *
     * **Concurrency note**: this is the canonical check-then-act
     * pattern (`get` → mutate → `put`) and is NOT atomic across
     * concurrent workers. Under racing decrements two workers can
     * read the same value and write the same decremented result,
     * which means a concurrent decrement can be LOST (double-spend)
     * — not just over-counted. For workloads where this matters,
     * point `mcp-pack.resilience.cache_store` at a Redis store and
     * a future iteration can switch to `Cache::decrement()` for
     * server-side atomic semantics.
     */
    public function tryConsume(string $tenantId, string $serverId): bool
    {
        $key = $this->cacheKey($tenantId, $serverId);
        $entry = $this->cache->get($key);

        $now = time();
        if (! is_array($entry) || ! isset($entry['tokens'], $entry['window_started_at'])) {
            $entry = ['tokens' => $this->bucketSize, 'window_started_at' => $now];
        }

        // Refill on window roll-over.
        if (($now - (int) $entry['window_started_at']) >= $this->windowSeconds) {
            $entry = ['tokens' => $this->bucketSize, 'window_started_at' => $now];
        }

        if ((int) $entry['tokens'] <= 0) {
            // Persist the empty bucket so subsequent calls see the
            // same drained state until the window rolls over.
            $this->cache->put($key, $entry, $this->windowSeconds * 2);
            return false;
        }

        $entry['tokens'] = (int) $entry['tokens'] - 1;
        $this->cache->put($key, $entry, $this->windowSeconds * 2);
        return true;
    }

    public function remaining(string $tenantId, string $serverId): int
    {
        $entry = $this->cache->get($this->cacheKey($tenantId, $serverId));
        if (! is_array($entry) || ! isset($entry['tokens'])) {
            return $this->bucketSize;
        }
        return (int) $entry['tokens'];
    }

    private function cacheKey(string $tenantId, string $serverId): string
    {
        $raw = $tenantId . '|' . $serverId;
        return $this->cachePrefix . ':' . substr(hash('sha256', $raw), 0, 32);
    }
}
