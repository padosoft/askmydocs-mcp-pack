<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerMutableRegistryContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpServerNotFoundException;
use Padosoft\AskMyDocsMcpPack\Support\McpServerPage;

/**
 * v1.5.0 — drop-in mutable registry for W1.B controller tests.
 *
 * Mirrors the {@see FakeIdentityBridge} pattern: per-method behaviour
 * is steered by public properties so tests opt in to a per-scenario
 * stub without subclassing. Implements both contracts so it can be
 * bound to either container key.
 *
 * Defaults:
 *   - `forTenant()` returns whatever's in `$servers` filtered by the
 *     tenant id;
 *   - `find()` returns the first matching entry;
 *   - `paginate()`, `create()`, `update()`, `delete()` throw
 *     {@see HostFeatureNotImplementedException} when their backing
 *     property is `null` (matches the `HasMutableRegistry` default).
 *     Set the property to a concrete result to exercise the happy
 *     path.
 */
final class FakeMutableRegistry implements McpServerMutableRegistryContract
{
    /** @var array<int,McpServerContract> */
    public array $servers = [];

    public ?McpServerPage $paginateResult = null;

    public ?McpServerContract $createResult = null;

    public ?McpServerContract $updateResult = null;

    /** Tri-state: `true` / `false` for happy delete + idempotent-miss,
     *  `null` to throw the 501 default. */
    public ?bool $deleteResult = null;

    /** When `true`, `update()` throws `McpServerNotFoundException`
     *  instead of returning `$updateResult` — simulates a concurrent
     *  delete between the controller pre-check and the actual
     *  mutation. The controller catches it and answers 404. */
    public bool $updateThrowsServerNotFound = false;

    /** @var array<int,array{string, array<string,mixed>}> */
    public array $createCalls = [];

    /** @var array<int,array{string, array<string,mixed>}> */
    public array $updateCalls = [];

    /** @var array<int,string> */
    public array $deleteCalls = [];

    public bool $forceNotImplemented = false;

    public function forTenant(?string $tenantId): array
    {
        return array_values(array_filter(
            $this->servers,
            static fn(McpServerContract $s): bool =>
                $s->isEnabled()
                && ($s->tenantId() === $tenantId || $s->tenantId() === null),
        ));
    }

    public function find(string $id): ?McpServerContract
    {
        foreach ($this->servers as $s) {
            if ($s->isEnabled() && $s->id() === $id) {
                return $s;
            }
        }
        return null;
    }

    /**
     * Iter-1 (W1.B) — tenant-scoped lookup that includes disabled
     * rows when asked. Tests rely on this returning the disabled
     * server so PATCH/DELETE controller flows can exercise the
     * "operator flips disabled → enabled" path.
     */
    public function findForActiveTenant(?string $tenantId, string $id, bool $includeDisabled = true): ?McpServerContract
    {
        foreach ($this->servers as $s) {
            if ($s->id() !== $id) {
                continue;
            }
            if (! $includeDisabled && ! $s->isEnabled()) {
                continue;
            }
            if ($tenantId !== null && $s->tenantId() !== null && $s->tenantId() !== $tenantId) {
                continue;
            }
            return $s;
        }
        return null;
    }

    public function paginate(
        ?string $tenantId,
        array $filters = [],
        int $page = 1,
        int $perPage = 50,
    ): McpServerPage {
        if ($this->forceNotImplemented || $this->paginateResult === null) {
            throw HostFeatureNotImplementedException::forFeature('paginate');
        }
        return $this->paginateResult;
    }

    public function create(array $attributes): McpServerContract
    {
        if ($this->forceNotImplemented || $this->createResult === null) {
            throw HostFeatureNotImplementedException::forFeature('create');
        }
        // Capture the trusted tenant_id the controller injected for
        // R30 assertions.
        $this->createCalls[] = ['', $attributes];
        return $this->createResult;
    }

    public function update(string $id, array $attributes): McpServerContract
    {
        if ($this->updateThrowsServerNotFound) {
            throw McpServerNotFoundException::forId($id);
        }
        if ($this->forceNotImplemented || $this->updateResult === null) {
            throw HostFeatureNotImplementedException::forFeature('update');
        }
        $this->updateCalls[] = [$id, $attributes];
        return $this->updateResult;
    }

    public function delete(string $id): bool
    {
        if ($this->forceNotImplemented || $this->deleteResult === null) {
            throw HostFeatureNotImplementedException::forFeature('delete');
        }
        $this->deleteCalls[] = $id;
        return $this->deleteResult;
    }
}
