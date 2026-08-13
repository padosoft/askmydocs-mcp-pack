<?php

namespace Padosoft\AskMyDocsMcpPack\Fluent;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Padosoft\AskMyDocsMcpPack\Catalog\ServerCatalog;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\SubscriptionBrokerContract;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ServerDefinition;

final class McpManager
{
    /** @var array<string,ServerCatalog> */
    private array $servers = [];

    /** @var array<string,string> */
    private array $localAliases = [];

    public function __construct(private readonly CacheFactory $cache, private readonly SubscriptionBrokerContract $subscriptions) {}

    public function server(string $id): Server
    {
        return Server::make($id, $this);
    }

    public function register(ServerDefinition $definition): ServerCatalog
    {
        return $this->servers[$definition->id] ??= new ServerCatalog($definition, $this->cache->store(config('mcp-pack.v2.cache_store')), $this->subscriptions);
    }

    public function find(string $id): ?ServerCatalog
    {
        return $this->servers[$id] ?? null;
    }

    public function require(string $id): ServerCatalog
    {
        return $this->find($id) ?? throw new \OutOfBoundsException("MCP server [{$id}] is not registered.");
    }

    /** @return list<ServerCatalog> */
    public function all(): array
    {
        $servers = $this->servers;
        ksort($servers);

        return array_values($servers);
    }

    public function local(string $alias, string $serverId): void
    {
        $this->localAliases[$alias] = $serverId;
    }

    public function resolveLocal(string $alias): ?ServerCatalog
    {
        $id = $this->localAliases[$alias] ?? $alias;

        return $this->find($id);
    }
}
