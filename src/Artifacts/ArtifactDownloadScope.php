<?php

namespace Padosoft\AskMyDocsMcpPack\Artifacts;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\ArtifactManagerContract;

/**
 * Encrypted tenant/actor scope carried inside signed artifact download URLs.
 *
 * The signed route is a bearer capability, but the download path must still
 * resolve the row through {@see ArtifactManagerContract::read()}
 * with the same tenant/actor pair the URL was minted for — never by UUID alone
 * (see `.claude/rules/rule-v2-tasks-artifacts.md`). Encrypting the pair keeps
 * identifiers out of the URL while the route signature guarantees integrity.
 */
final class ArtifactDownloadScope
{
    public function __construct(private readonly StringEncrypter $encrypter) {}

    public function encode(?string $tenantId, ?string $actorId): string
    {
        return $this->encrypter->encryptString(json_encode(['t' => $tenantId, 'a' => $actorId], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{0:?string,1:?string}|null `null` when the token is missing, tampered or malformed
     */
    public function decode(mixed $token): ?array
    {
        if (! is_string($token) || $token === '') {
            return null;
        }
        try {
            $payload = json_decode($this->encrypter->decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($payload) || ! array_key_exists('t', $payload) || ! array_key_exists('a', $payload)) {
            return null;
        }
        $tenantId = $payload['t'];
        $actorId = $payload['a'];
        if (($tenantId !== null && ! is_string($tenantId)) || ($actorId !== null && ! is_string($actorId))) {
            return null;
        }

        return [$tenantId, $actorId];
    }
}
