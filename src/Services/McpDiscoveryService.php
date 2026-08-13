<?php

namespace Padosoft\AskMyDocsMcpPack\Services;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;

/** Discovery-first protocol negotiation and tenant-scoped catalog caching. */
class McpDiscoveryService
{
    public function __construct(protected readonly int $ttlSeconds = 300) {}

    /** @return array{era?:string|null,protocolVersion?:string|null,capabilities:array<string,mixed>,tools:array<int,array<string,mixed>>}|null */
    public function peek(McpServerContract $server): ?array
    {
        return $this->hydrate($server);
    }

    /** @return array{era:string|null,protocolVersion:string|null,capabilities:array<string,mixed>,tools:array<int,array<string,mixed>>} */
    public function refresh(McpServerContract $server, bool $force = false): array
    {
        if (! $force && ($cached = $this->hydrate($server)) !== null) {
            return $cached;
        }

        $client = McpClient::forServer($server);
        try {
            $capabilities = $client->initialize();
            $tools = $client->listTools();
        } catch (\Throwable $e) {
            throw new McpTransportException("Discovery failed for MCP server [{$server->id()}]: {$e->getMessage()}", previous: $e);
        }

        $payload = [
            'era' => $client->negotiatedProtocol()?->era->value,
            'protocolVersion' => $client->negotiatedProtocol()?->protocolVersion,
            'capabilities' => $capabilities,
            'tools' => $tools,
        ];
        $this->persist($server, $payload);

        return $payload;
    }

    /** @return array{era?:string|null,protocolVersion?:string|null,capabilities:array<string,mixed>,tools:array<int,array<string,mixed>>}|null */
    protected function hydrate(McpServerContract $server): ?array
    {
        $value = $this->cache()?->get($this->cacheKey($server));

        return is_array($value) ? $value : null;
    }

    /** @param array<string,mixed> $payload */
    protected function persist(McpServerContract $server, array $payload): void
    {
        $this->cache()?->put($this->cacheKey($server), $payload, $this->ttlSeconds);
    }

    protected function cacheKey(McpServerContract $server): string
    {
        return 'askmydocs-mcp-pack:discovery:'.($server->tenantId() ?? 'global').':'.$server->id();
    }

    protected function cache(): ?Repository
    {
        if (! function_exists('app') || ! app()->bound('cache')) {
            return null;
        }
        $manager = app('cache');

        return $manager instanceof CacheManager ? $manager->store() : null;
    }
}
