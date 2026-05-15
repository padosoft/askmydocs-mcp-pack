<?php

namespace Padosoft\AskMyDocsMcpPack\Services;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;

/**
 * Resolves and caches an MCP server's tool catalog.
 *
 * The first call to {@see refresh()} drives the JSON-RPC `initialize`
 * + `tools/list` round trip and stores the result in the Laravel cache
 * (default TTL 5 minutes). Subsequent calls return the cached catalog
 * unless `--force` is passed.
 *
 * Hosts that prefer to persist handshakes in a DB column (e.g.
 * AskMyDocs's `mcp_servers.handshake_response_json`) can subclass and
 * override {@see persist()} / {@see hydrate()}.
 */
class McpHandshakeService
{
    public function __construct(
        protected readonly int $ttlSeconds = 300,
    ) {}

    /**
     * @return array{capabilities:array<string,mixed>,tools:array<int,array<string,mixed>>}
     */
    public function refresh(McpServerContract $server, bool $force = false): array
    {
        if (! $force) {
            $cached = $this->hydrate($server);
            if ($cached !== null) {
                return $cached;
            }
        }

        $client = McpClient::forServer($server);

        try {
            $capabilities = $client->initialize();
            $tools = $client->listTools();
        } catch (\Throwable $e) {
            throw new McpTransportException(
                "Handshake failed for MCP server [{$server->id()}]: {$e->getMessage()}",
                previous: $e,
            );
        }

        $payload = [
            'capabilities' => $capabilities,
            'tools' => $tools,
        ];

        $this->persist($server, $payload);

        return $payload;
    }

    /** @return array{capabilities:array<string,mixed>,tools:array<int,array<string,mixed>>}|null */
    protected function hydrate(McpServerContract $server): ?array
    {
        $store = $this->cache();
        if ($store === null) {
            return null;
        }
        $value = $store->get($this->cacheKey($server));

        return is_array($value) ? $value : null;
    }

    /** @param array<string,mixed> $payload */
    protected function persist(McpServerContract $server, array $payload): void
    {
        $store = $this->cache();
        $store?->put($this->cacheKey($server), $payload, $this->ttlSeconds);
    }

    protected function cacheKey(McpServerContract $server): string
    {
        $tenant = $server->tenantId() ?? 'global';
        return "askmydocs-mcp-pack:handshake:{$tenant}:{$server->id()}";
    }

    protected function cache(): ?\Illuminate\Contracts\Cache\Repository
    {
        if (! function_exists('app') || ! app()->bound('cache')) {
            return null;
        }
        $manager = app('cache');
        return $manager instanceof \Illuminate\Cache\CacheManager ? $manager->store() : null;
    }
}
