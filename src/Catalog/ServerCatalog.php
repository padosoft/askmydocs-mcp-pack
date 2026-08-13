<?php

namespace Padosoft\AskMyDocsMcpPack\Catalog;

use Illuminate\Contracts\Cache\Repository;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\CatalogContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\DefinitionContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\SubscriptionBrokerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\TenantCatalogContract;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ServerDefinition;

final class ServerCatalog implements CatalogContract
{
    /** @var array<string,array<string,DefinitionContract>> */
    private array $base = [];

    /** @var array<string,array<string,array<string,DefinitionContract>>> */
    private array $overlays = [];

    /** @var array<string,int> */
    private array $revisions = [];

    public function __construct(
        public readonly ServerDefinition $server,
        private readonly Repository $cache,
        private readonly SubscriptionBrokerContract $subscriptions,
    ) {
        foreach (['tools', 'resources', 'resourceTemplates', 'prompts', 'apps'] as $kind) {
            foreach ($server->{$kind} as $definition) {
                $this->base[$kind][$definition->key()] = $definition;
            }
        }
    }

    public function forTenant(?string $tenantId, ?string $principalId = null): TenantCatalogContract
    {
        return new TenantCatalog($this, $tenantId, $principalId);
    }

    /** @return list<DefinitionContract> */
    public function scopedAll(string $scope, string $kind): array
    {
        $items = array_replace($this->base[$kind] ?? [], $this->overlays[$scope][$kind] ?? []);
        ksort($items, SORT_STRING);

        return array_values($items);
    }

    public function scopedUpsert(string $scope, ?string $tenantId, ?string $principalId, DefinitionContract $definition): void
    {
        $this->overlays[$scope][$definition->kind()][$definition->key()] = $definition;
        $this->changed($scope, $tenantId, $principalId, $definition->kind());
    }

    public function scopedRemove(string $scope, ?string $tenantId, ?string $principalId, string $kind, string $key): bool
    {
        $overlay = $this->overlays[$scope][$kind][$key] ?? null;
        if ($overlay instanceof RemovedDefinition) {
            return false;
        }
        $exists = $overlay !== null || isset($this->base[$kind][$key]);
        if (! $exists) {
            return false;
        }
        if (isset($this->base[$kind][$key])) {
            $this->overlays[$scope][$kind][$key] = new RemovedDefinition($kind, $key);
        } else {
            unset($this->overlays[$scope][$kind][$key]);
        }
        $this->changed($scope, $tenantId, $principalId, $kind);

        return true;
    }

    public function revision(string $scope): int
    {
        return $this->revisions[$scope] ?? 1;
    }

    /** @return array{revision:int,digest:string,definitions:array<string,list<array<string,mixed>>>} */
    public function scopedSnapshot(string $scope): array
    {
        $key = "mcp-pack:v2:catalog:{$this->server->id}:{$scope}";
        if ($this->server->cacheScope->value !== 'no-store') {
            $cached = $this->cache->get($key);
            if (is_array($cached) && ($cached['revision'] ?? null) === $this->revision($scope)) {
                return $cached;
            }
        }
        $definitions = [];
        foreach (['apps', 'prompts', 'resourceTemplates', 'resources', 'tools'] as $kind) {
            $definitions[$kind] = array_map(static fn (DefinitionContract $item): array => $item->toArray(), array_values(array_filter(
                $this->scopedAll($scope, $kind), static fn (DefinitionContract $item): bool => ! $item instanceof RemovedDefinition,
            )));
        }
        $json = json_encode($definitions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $snapshot = ['revision' => $this->revision($scope), 'digest' => hash('sha256', $json), 'definitions' => $definitions];
        if ($this->server->cacheScope->value !== 'no-store' && $this->server->ttlMs > 0) {
            $this->cache->put($key, $snapshot, max(1, (int) ceil($this->server->ttlMs / 1000)));
        }

        return $snapshot;
    }

    private function changed(string $scope, ?string $tenantId, ?string $principalId, string $kind): void
    {
        $this->revisions[$scope] = $this->revision($scope) + 1;
        $this->cache->forget("mcp-pack:v2:catalog:{$this->server->id}:{$scope}");
        $this->subscriptions->publish($tenantId, 'notifications/'.$kind.'/list_changed', [
            'server' => $this->server->id,
            'kind' => $kind,
            'revision' => $this->revisions[$scope],
        ], $principalId);
    }
}
