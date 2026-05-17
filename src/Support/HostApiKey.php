<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

/**
 * v1.5.0 — provider-agnostic shape of an API-key row returned by
 * {@see \Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract::listApiKeys()}
 * and {@see \Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract::createApiKey()}.
 *
 * The plaintext token is ONLY surfaced once — at create time — via
 * the `plaintext` property. List + show payloads omit it (the host
 * stores only the hash). The SPA renders the plaintext exactly once
 * after creation, then forgets it.
 */
final readonly class HostApiKey
{
    /**
     * @param  array<int,string>  $scopes
     * @param  string|null        $plaintext  ONLY set when returned by
     *                                        `createApiKey()`. List + show
     *                                        responses leave it `null`.
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $scopes,
        public ?string $createdBy = null,
        public ?string $createdAt = null,
        public ?string $lastUsedAt = null,
        public ?string $plaintext = null,
    ) {}

    /** @param  array<string,mixed>  $payload */
    public static function fromArray(array $payload): self
    {
        $scopesRaw = $payload['scopes'] ?? [];
        $scopes = is_array($scopesRaw)
            ? array_values(array_map('strval', $scopesRaw))
            : [];

        return new self(
            id: isset($payload['id']) ? (string) $payload['id'] : '',
            name: isset($payload['name']) ? (string) $payload['name'] : '',
            scopes: $scopes,
            createdBy: isset($payload['created_by']) ? (string) $payload['created_by'] : null,
            createdAt: isset($payload['created_at']) ? (string) $payload['created_at'] : null,
            lastUsedAt: isset($payload['last_used_at']) ? (string) $payload['last_used_at'] : null,
            plaintext: isset($payload['plaintext']) ? (string) $payload['plaintext'] : null,
        );
    }

    /**
     * Wire shape for list + show. The plaintext is intentionally
     * omitted — see class docblock.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scopes' => $this->scopes,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt,
            'last_used_at' => $this->lastUsedAt,
        ];
    }

    /**
     * Wire shape for the CREATE response — embeds the plaintext token
     * exactly once. Callers MUST display it to the operator and warn
     * that it will never be shown again.
     *
     * @return array<string,mixed>
     */
    public function toCreateArray(): array
    {
        return $this->toArray() + ['plaintext' => $this->plaintext];
    }
}
