<?php

namespace Padosoft\AskMyDocsMcpPack\Protocol;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

final readonly class RequestStateCipher
{
    public function __construct(private StringEncrypter $encrypter, private ConnectionInterface $db) {}

    /** @param array<string,mixed> $arguments */
    public function issue(McpRequest $request, array $arguments, int $ttlSeconds = 900, bool $singleUse = true): string
    {
        $payload = [
            'tenant' => $request->tenantId,
            'actor' => $request->actorId(),
            'method' => $request->method,
            'digest' => $this->digest($arguments),
            'nonce' => (string) Str::uuid(),
            'expiresAt' => now()->addSeconds($ttlSeconds)->getTimestamp(),
            'singleUse' => $singleUse,
        ];
        if ($singleUse) {
            $this->db->table('mcp_request_states')->insert([
                'nonce' => $payload['nonce'], 'tenant_hash' => hash('sha256', (string) $payload['tenant']),
                'actor_hash' => hash('sha256', (string) $payload['actor']), 'payload_digest' => $payload['digest'],
                'expires_at' => now()->addSeconds($ttlSeconds), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $this->encrypter->encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    public function decrypt(string $state): array
    {
        $payload = json_decode($this->encrypter->decryptString($state), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || (int) ($payload['expiresAt'] ?? 0) < time()) {
            throw new \InvalidArgumentException('requestState is invalid or expired.');
        }

        return $payload;
    }

    /** @param array<string,mixed> $arguments */
    public function consume(string $state, ?string $tenantId, ?string $actorId, string $method, array $arguments): array
    {
        $payload = $this->decrypt($state);
        $matches = hash_equals((string) $payload['tenant'], (string) $tenantId)
            && hash_equals((string) $payload['actor'], (string) $actorId)
            && hash_equals((string) $payload['method'], $method)
            && hash_equals((string) $payload['digest'], $this->digest($arguments));
        if (! $matches) {
            throw new \InvalidArgumentException('requestState does not match this tenant, actor, method or request.');
        }
        if (($payload['singleUse'] ?? false) === true) {
            $updated = $this->db->table('mcp_request_states')->where('nonce', $payload['nonce'])->whereNull('consumed_at')->where('expires_at', '>', now())
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new \InvalidArgumentException('requestState has already been consumed or expired.');
            }
        }

        return $payload;
    }

    /** @param array<string,mixed> $arguments */
    private function digest(array $arguments): string
    {
        return hash('sha256', json_encode($this->canonicalize($arguments), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
