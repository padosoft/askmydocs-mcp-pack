<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

use Padosoft\AskMyDocsMcpPack\Support\HostApiKey;
use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;
use Padosoft\AskMyDocsMcpPack\Support\HostTenant;
use Padosoft\AskMyDocsMcpPack\Support\HostUser;
use Padosoft\AskMyDocsMcpPack\Support\HostUserPreferences;

/**
 * The bridge between the package's orchestrator and the host's AI
 * provider stack.
 *
 * The pack is provider-agnostic by design — it does NOT bind
 * `openai`, `openrouter`, `anthropic`, or `gemini` SDKs. The host
 * implements this contract once (typically a 30-line wrapper around
 * the host's existing chat manager) and the orchestrator drives the
 * multi-turn tool-calling loop through it.
 *
 * Idempotency hint: `chat()` SHOULD be deterministic when the model
 * temperature is 0 and the host's caller passes `seed` through the
 * extras map. The v1.0 orchestrator does not coalesce duplicate
 * tool-call payloads automatically — the hint is for hosts that want
 * to layer their own dedupe / cache in front of the bridge.
 *
 * ## v1.5.0 — identity / audit-replay / breaker-reset surface
 *
 * The contract grew NINE new methods so the admin REST surface can
 * surface the actor, the tenant catalogue, API keys, user
 * preferences, audit drilldowns, replays, and breaker resets without
 * binding to host domain models in `src/`.
 *
 * Existing host bridges (pre-v1.5) MUST adopt one of two upgrade
 * paths when they bump the package:
 *
 *  1. `use Padosoft\AskMyDocsMcpPack\Contracts\Concerns\HasIdentitySurface;`
 *     — every new method throws
 *     {@see \Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException},
 *     which the admin controllers translate into HTTP 501. The host
 *     overrides only the methods it actually exposes.
 *  2. Implement every new method directly. Recommended only when the
 *     host wires the entire identity surface in one go.
 *
 * The shipped {@see \Padosoft\AskMyDocsMcpPack\Defaults\NullMcpHostBridge}
 * uses path 1, so a fresh install boots without the host having to
 * declare anything.
 */
interface McpHostBridgeContract
{
    /**
     * Drive ONE turn against the model. `$turn->tools` carries the
     * tool catalog (built by the orchestrator from the registry +
     * authorizer). The host's adapter MUST forward those tools to
     * the provider in OpenAI-style function-calling shape.
     *
     * @return HostChatResponse  the model's response, including any
     *         tool_calls it emitted. Tool execution is the
     *         orchestrator's job, NOT the bridge's.
     */
    public function chat(HostChatTurn $turn): HostChatResponse;

    /**
     * Whether the host's currently-configured provider supports
     * OpenAI-style tool calling. The orchestrator uses this to
     * short-circuit early (with a clear error) when the operator
     * tries to drive a tool-call session against a tool-incapable
     * provider (e.g. older Anthropic / Gemini).
     */
    public function supportsToolCalling(): bool;

    /**
     * v1.5.0 — identity of the actor driving the current request, as
     * resolved by the host's auth middleware. Returns `null` when no
     * actor is bound (anonymous / platform-global view).
     *
     * Hosts produce `HostUser` from whatever auth backend they use
     * (Sanctum, Passport, Keycloak, custom JWT) — the package never
     * imports a host `User` model.
     */
    public function currentUser(): ?HostUser;

    /**
     * v1.5.0 — list of tenants the active user can see. The host
     * decides the visibility rules (single-tenant returns one row;
     * multi-tenant returns the user's allowed tenants; global admin
     * returns every tenant).
     *
     * @return array<int,HostTenant>
     */
    public function listTenants(): array;

    /**
     * v1.5.0 — list API keys.
     *
     * R30: when `$userId` is non-null, the host MUST filter to keys
     * owned by that user; cross-user enumeration is a contract
     * violation. The controller passes `currentUser()->id` so the
     * default surface stays tenant- and user-safe.
     *
     * @param  int|string|null  $userId  null = sweep ALL keys (admin
     *                                   view); concrete id = scoped
     * @return array<int,HostApiKey>
     */
    public function listApiKeys(int|string|null $userId = null): array;

    /**
     * v1.5.0 — mint a new API key. The host returns the row with the
     * plaintext token surfaced exactly once via
     * {@see HostApiKey::$plaintext}. List + show calls MUST omit the
     * plaintext.
     *
     * @param  array<string,mixed>  $attrs  validated by
     *                                      `CreateApiKeyRequest`
     */
    public function createApiKey(array $attrs): HostApiKey;

    /**
     * v1.5.0 — revoke an API key. Returns `true` when revoked,
     * `false` when the key did not exist or was already revoked.
     *
     * R30: the host MUST verify the key belongs to the active user
     * (or that the actor has cross-user admin rights) BEFORE
     * revoking. The package controller does not enforce that — the
     * host's auth middleware does.
     */
    public function revokeApiKey(string $id): bool;

    /**
     * v1.5.0 — persist per-user preferences. The bag is schema-less;
     * hosts decide what they store. Returns the persisted shape.
     *
     * @param  array<string,mixed>  $prefs
     */
    public function savePreferences(int|string $userId, array $prefs): HostUserPreferences;

    /**
     * v1.5.0 (signature reserved for W1.C) — single audit row +
     * drilldown payload. Returns `null` when the row is not visible
     * to the active tenant.
     *
     * @return array<string,mixed>|null
     */
    public function auditFor(int|string $id): ?array;

    /**
     * v1.5.0 (signature reserved for W1.C) — re-fire the audited
     * tool call. Hosts MUST honour R21 single-use semantics on the
     * `$token` argument inside a `DB::transaction` closure.
     *
     * @return array<string,mixed>
     */
    public function replayAudit(int|string $id, ?string $token = null): array;

    /**
     * v1.5.0 (signature reserved for W1.C) — reset the circuit
     * breaker for `(serverId, toolName)` under an R21 single-use
     * token guard. Returns `true` when the breaker had state to
     * reset.
     */
    public function resetBreaker(string $serverId, string $toolName, ?string $token = null): bool;
}
