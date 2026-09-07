<?php

namespace Padosoft\AskMyDocsMcpPack\Protocol;

final readonly class CursorCodec
{
    public function __construct(private string $key) {}

    public function encode(int $offset, string $digest): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['offset' => $offset, 'digest' => $digest], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, $this->key);
    }

    /** @return array{offset:int,digest:string} */
    public function decode(string $cursor): array
    {
        [$payload, $mac] = array_pad(explode('.', $cursor, 2), 2, '');
        if ($payload === '' || ! hash_equals(hash_hmac('sha256', $payload, $this->key), $mac)) {
            throw new \InvalidArgumentException('Invalid or tampered cursor.');
        }
        $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/'), true) ?: '', true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! is_int($decoded['offset'] ?? null) || ! is_string($decoded['digest'] ?? null)) {
            throw new \InvalidArgumentException('Malformed cursor.');
        }

        return ['offset' => $decoded['offset'], 'digest' => $decoded['digest']];
    }
}
