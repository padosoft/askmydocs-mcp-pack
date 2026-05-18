<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Contracts\Concerns\HasIdentitySurface;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException;
use Padosoft\AskMyDocsMcpPack\Support\HostApiKey;
use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;
use Padosoft\AskMyDocsMcpPack\Support\HostTenant;
use Padosoft\AskMyDocsMcpPack\Support\HostUser;
use Padosoft\AskMyDocsMcpPack\Support\HostUserPreferences;

/**
 * v1.5.0 — drop-in identity bridge for W1.A controller tests. Each
 * identity method is overridable via a public property so tests
 * opt in to a per-scenario stub without subclassing.
 *
 * Default-behaviour rules:
 *   - `currentUser()` returns `$user` (default `null`).
 *   - `listTenants()`, `listApiKeys()`, `createApiKey()`,
 *     `revokeApiKey()` throw `HostFeatureNotImplementedException`
 *     when their corresponding property is `null` — matching
 *     {@see HasIdentitySurface}'s 501-via-throw default.
 *   - `savePreferences()` records the call into `$savedPreferences`
 *     and ALWAYS succeeds unless `$forceNotImplemented` is on. This
 *     deliberate asymmetry is because the SPA tests want to assert
 *     "the bridge was called with these prefs" — flip
 *     `$forceNotImplemented` to simulate the 501 path.
 *   - When `$forceNotImplemented` is `true`, every method throws —
 *     useful for asserting the 501 envelope at the controller layer.
 *
 * Implements BOTH the base contract and the identity sub-interface
 * so it can be bound to either container key.
 */
final class FakeIdentityBridge implements McpHostBridgeContract, McpHostBridgeIdentityContract
{
    use HasIdentitySurface;

    public ?HostUser $user = null;

    /** @var array<int,HostTenant>|null */
    public ?array $tenants = null;

    /** @var array<int,HostApiKey>|null */
    public ?array $apiKeys = null;

    public ?HostApiKey $createApiKeyResult = null;

    public ?bool $revokeApiKeyResult = null;

    /** @var array{int|string, array<string,mixed>}|null */
    public ?array $savedPreferences = null;

    /** @var array<int,int|string|null> */
    public array $listApiKeysCalledWith = [];

    /**
     * When `true`, every method throws
     * `HostFeatureNotImplementedException` so the test can assert
     * the 501 envelope from the controller side.
     */
    public bool $forceNotImplemented = false;

    public function chat(HostChatTurn $turn): HostChatResponse
    {
        return new HostChatResponse(content: 'fake');
    }

    public function supportsToolCalling(): bool
    {
        return true;
    }

    public function currentUser(): ?HostUser
    {
        if ($this->forceNotImplemented) {
            throw HostFeatureNotImplementedException::forFeature('currentUser');
        }
        return $this->user;
    }

    public function listTenants(): array
    {
        if ($this->forceNotImplemented || $this->tenants === null) {
            throw HostFeatureNotImplementedException::forFeature('listTenants');
        }
        return $this->tenants;
    }

    public function listApiKeys(int|string|null $userId = null): array
    {
        $this->listApiKeysCalledWith[] = $userId;
        if ($this->forceNotImplemented || $this->apiKeys === null) {
            throw HostFeatureNotImplementedException::forFeature('listApiKeys');
        }
        return $this->apiKeys;
    }

    public function createApiKey(array $attrs): HostApiKey
    {
        if ($this->forceNotImplemented || $this->createApiKeyResult === null) {
            throw HostFeatureNotImplementedException::forFeature('createApiKey');
        }
        return $this->createApiKeyResult;
    }

    public function revokeApiKey(string $id): bool
    {
        if ($this->forceNotImplemented || $this->revokeApiKeyResult === null) {
            throw HostFeatureNotImplementedException::forFeature('revokeApiKey');
        }
        return $this->revokeApiKeyResult;
    }

    public function savePreferences(int|string $userId, array $prefs): HostUserPreferences
    {
        if ($this->forceNotImplemented) {
            throw HostFeatureNotImplementedException::forFeature('savePreferences');
        }
        $this->savedPreferences = [$userId, $prefs];
        return new HostUserPreferences(userId: $userId, values: $prefs);
    }

    // ----- v1.5.0 W1.C — audit drilldown / replay / breaker reset -----

    /** @var array<string,array<string,mixed>> keyed by `(id)` */
    public array $auditRows = [];

    /** @var array<string,?string> map of audit id → owning tenant id */
    public array $auditTenants = [];

    /** @var array<int,array{0:int|string, 1:?string}> calls to `auditFor` */
    public array $auditForCalls = [];

    /** @var array<int,array{0:int|string, 1:?string}> calls to `replayAudit` */
    public array $replayAuditCalls = [];

    public ?array $replayAuditResult = null;

    /** @var array<int,array{0:string,1:string,2:?string}> calls to `resetBreaker` */
    public array $resetBreakerCalls = [];

    public ?bool $resetBreakerResult = null;

    public function auditFor(int|string $id, ?string $tenantId = null): ?array
    {
        $this->auditForCalls[] = [$id, $tenantId];
        if ($this->forceNotImplemented) {
            throw HostFeatureNotImplementedException::forFeature('auditFor');
        }
        $key = (string) $id;
        if (! isset($this->auditRows[$key])) {
            return null;
        }
        // R30: tenant-scope the lookup at the bridge level (mirrors
        // how a real host would `WHERE tenant_id = ?`).
        $owningTenant = $this->auditTenants[$key] ?? null;
        if ($tenantId !== null && $owningTenant !== null && $owningTenant !== $tenantId) {
            return null;
        }
        return $this->auditRows[$key];
    }

    public function replayAudit(int|string $id, ?string $token = null): array
    {
        $this->replayAuditCalls[] = [$id, $token];
        if ($this->forceNotImplemented || $this->replayAuditResult === null) {
            throw HostFeatureNotImplementedException::forFeature('replayAudit');
        }
        return $this->replayAuditResult;
    }

    public function resetBreaker(string $serverId, string $toolName, ?string $token = null): bool
    {
        $this->resetBreakerCalls[] = [$serverId, $toolName, $token];
        if ($this->forceNotImplemented || $this->resetBreakerResult === null) {
            throw HostFeatureNotImplementedException::forFeature('resetBreaker');
        }
        // R21: the production host would consume the token inside the
        // same `DB::transaction` as the breaker reset. The fake records
        // the call for assertions but does NOT throw on missing token
        // here — the controller's `consumeConfirmToken()` already did.
        return $this->resetBreakerResult;
    }

    // ----- v1.5.0 W1.C iter-1 — confirm-token surface ------------------

    /** @var array<string, array{scope:string,target_id:string,tenant_id:?string,expires_at:int,used_at:?int}> */
    public array $mintedTokens = [];

    /** When `true`, every `consumeConfirmToken()` call throws `InvalidConfirmTokenException::forConsumed()`. */
    public bool $forceConfirmTokenConsumed = false;

    /** When `true`, every `consumeConfirmToken()` call throws `InvalidConfirmTokenException::forExpired()`. */
    public bool $forceConfirmTokenExpired = false;

    public function mintConfirmToken(\Padosoft\AskMyDocsMcpPack\Support\McpAdminConfirmToken $token): void
    {
        $this->mintedTokens[$token->token] = [
            'scope' => $token->scope,
            'target_id' => $token->targetId,
            'tenant_id' => $token->tenantId,
            'expires_at' => $token->expiresAt,
            'used_at' => null,
        ];
    }

    public function consumeConfirmToken(string $token, string $scope, string $targetId, ?string $tenantId): void
    {
        if ($this->forceConfirmTokenExpired) {
            throw \Padosoft\AskMyDocsMcpPack\Exceptions\InvalidConfirmTokenException::forExpired($token, $scope);
        }
        if ($this->forceConfirmTokenConsumed) {
            throw \Padosoft\AskMyDocsMcpPack\Exceptions\InvalidConfirmTokenException::forConsumed($token, $scope);
        }
        if (! isset($this->mintedTokens[$token])) {
            throw \Padosoft\AskMyDocsMcpPack\Exceptions\InvalidConfirmTokenException::forForged($token, $scope);
        }
        $record = $this->mintedTokens[$token];
        if ($record['used_at'] !== null) {
            throw \Padosoft\AskMyDocsMcpPack\Exceptions\InvalidConfirmTokenException::forConsumed($token, $scope);
        }
        if ($record['scope'] !== $scope) {
            throw \Padosoft\AskMyDocsMcpPack\Exceptions\InvalidConfirmTokenException::forMismatch($token, 'scope');
        }
        if ($record['target_id'] !== $targetId) {
            throw \Padosoft\AskMyDocsMcpPack\Exceptions\InvalidConfirmTokenException::forMismatch($token, 'target_id');
        }
        // Strict tenant binding — no null ↔ non-null transitions.
        if ($record['tenant_id'] !== $tenantId) {
            throw \Padosoft\AskMyDocsMcpPack\Exceptions\InvalidConfirmTokenException::forMismatch($token, 'tenant_id');
        }
        if (time() >= $record['expires_at']) {
            throw \Padosoft\AskMyDocsMcpPack\Exceptions\InvalidConfirmTokenException::forExpired($token, $scope);
        }
        // R21: in-memory atomic — flip `used_at`. A second call with
        // the same token then hits the `used_at !== null` branch above.
        $this->mintedTokens[$token]['used_at'] = time();
    }
}
