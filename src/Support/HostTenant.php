<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

/**
 * v1.5.0 — provider-agnostic shape of a tenant entry returned by
 * {@see \Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract::listTenants()}.
 *
 * The package does not bind to a host `Tenant` model — hosts produce
 * this list from whatever multi-tenant strategy they use
 * (`spatie/laravel-multitenancy`, hand-rolled `tenants` table,
 * subdomain mapping). `primary` marks the tenant the active user
 * defaults to in the SPA's tenant switcher.
 */
final readonly class HostTenant
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $primary = false,
    ) {}

    /** @param  array<string,mixed>  $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: isset($payload['id']) ? (string) $payload['id'] : '',
            name: isset($payload['name']) ? (string) $payload['name'] : '',
            primary: (bool) ($payload['primary'] ?? false),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'primary' => $this->primary,
        ];
    }
}
