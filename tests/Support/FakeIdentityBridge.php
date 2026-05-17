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
}
