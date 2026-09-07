<?php

namespace Padosoft\AskMyDocsMcpPack\Catalog;

use Illuminate\Contracts\Cache\Repository;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\CatalogContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\DefinitionContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\SubscriptionBrokerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\TenantCatalogContract;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ServerDefinition;
use Padosoft\AskMyDocsMcpPack\Protocol\CacheScope;

/**
 * Compiled server catalog: immutable base definitions plus tenant/principal-scoped
 * overlays applied through {@see TenantCatalogContract::upsert()} / `remove()`.
 *
 * Overlays and their revision counters live in THIS PHP process only. Compiled
 * definitions carry handlers (closures for synchronous tools), which cannot be
 * serialised into a shared store, so dynamic changes are deliberately not
 * replicated across workers. Hosts running several stateless workers must apply
 * dynamic mutations deterministically on every worker (e.g. from a service
 * provider or per-request middleware) so that each worker computes the same
 * overlays and revision. Only the derived snapshot is shared through the cache,
 * and it is keyed by revision so workers on different revisions never overwrite
 * each other's entry.
 */
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
        // The principal is part of the catalog scope only for private-cache servers.
        // Public / no-store catalogs are shared by every principal of a tenant, so
        // normalise here: programmatic `forTenant($tenant, $principal)->upsert()` and
        // request-time lookups must always resolve to the same scope.
        if ($this->server->cacheScope !== CacheScope::Private) {
            $principalId = null;
        }

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
        $revision = $this->revision($scope);
        $key = $this->snapshotKey($scope, $revision);
        if ($this->server->cacheScope->value !== 'no-store') {
            $cached = $this->cache->get($key);
            if (is_array($cached) && ($cached['revision'] ?? null) === $revision) {
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
        $snapshot = ['revision' => $revision, 'digest' => hash('sha256', $json), 'definitions' => $definitions];
        if ($this->server->cacheScope->value !== 'no-store' && $this->server->ttlMs > 0) {
            $this->cache->put($key, $snapshot, max(1, (int) ceil($this->server->ttlMs / 1000)));
        }

        return $snapshot;
    }

    /**
     * Snapshot cache keys are qualified by revision. Overlays and revisions are
     * process-local (see the class docblock), so two workers may legitimately sit on
     * different revisions of the same scope; keying by revision keeps them from
     * overwriting each other's cached snapshot in the shared store.
     */
    private function snapshotKey(string $scope, int $revision): string
    {
        return "mcp-pack:v2:catalog:{$this->server->id}:{$scope}:r{$revision}";
    }

    private function changed(string $scope, ?string $tenantId, ?string $principalId, string $kind): void
    {
        $previous = $this->revision($scope);
        $this->revisions[$scope] = $previous + 1;
        $this->cache->forget($this->snapshotKey($scope, $previous));
        $this->subscriptions->publish($tenantId, 'notifications/'.$kind.'/list_changed', [
            'server' => $this->server->id,
            'kind' => $kind,
            'revision' => $this->revisions[$scope],
        ], $principalId);
    }
}
