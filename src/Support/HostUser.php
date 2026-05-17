<?php

namespace Padosoft\AskMyDocsMcpPack\Support;

/**
 * v1.5.0 — provider-agnostic shape of the "currently signed-in user"
 * returned by {@see \Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract::currentUser()}.
 *
 * The package never imports a host `User` model — hosts construct
 * this value object from whatever auth backend they use (Sanctum,
 * Passport, Keycloak, custom JWT). Every field is documented so the
 * SPA can render `Me` consistently regardless of the host.
 *
 * Idempotent serialisation: `toArray()` produces the SAME shape the
 * `GET /me` endpoint emits, so hosts can return the array directly
 * from their bridge if they prefer.
 */
final readonly class HostUser
{
    /**
     * @param  array<int,string>  $permissions  flat list of permission strings
     *                                          (e.g. `mcp.servers.view`); the SPA
     *                                          uses these as feature flags.
     */
    public function __construct(
        public int|string $id,
        public string $email,
        public string $name,
        public ?string $initials = null,
        public ?string $tenantId = null,
        public array $permissions = [],
    ) {}

    /**
     * Named constructor for hosts that pass a flat array (matching the
     * `GET /me` wire shape verbatim). Defensive against missing keys
     * so a partial host implementation produces a stable shape instead
     * of a TypeError mid-request.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $idRaw = $payload['id'] ?? 0;
        $id = is_int($idRaw) || is_string($idRaw) ? $idRaw : (string) $idRaw;
        $permissionsRaw = $payload['permissions'] ?? [];
        $permissions = is_array($permissionsRaw)
            ? array_values(array_map('strval', $permissionsRaw))
            : [];

        return new self(
            id: $id,
            email: isset($payload['email']) ? (string) $payload['email'] : '',
            name: isset($payload['name']) ? (string) $payload['name'] : '',
            initials: isset($payload['initials']) ? (string) $payload['initials'] : null,
            tenantId: isset($payload['tenant_id']) && is_string($payload['tenant_id']) && $payload['tenant_id'] !== ''
                ? $payload['tenant_id']
                : null,
            permissions: $permissions,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'initials' => $this->initials,
            'tenant_id' => $this->tenantId,
            'permissions' => $this->permissions,
        ];
    }
}
