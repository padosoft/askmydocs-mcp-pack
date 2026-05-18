# Changelog

All notable changes to `padosoft/askmydocs-mcp-pack` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### v1.5.0 — REST surface extension (W1.D — Resources + Prompts + SSE + OpenAPI)

W1.D ships the final wave of the v1.5 REST extension. Six new
endpoints cover the per-server resources tree + single resource
content, the per-server prompts catalog + single prompt detail, a
Server-Sent-Events live stream of recent audit invocations, and the
canonical OpenAPI 3.1 self-description. Net REST surface delta vs
v1.4: +16 endpoints (the full 6 → 22 progression). v1.5.0 GA tag
follows once R36 closes on the PR.

#### Added — `McpHostBridgeIdentityContract` resources/prompts/SSE surface

Five new optional methods on the contract sub-interface; every
existing host bridge keeps compiling because the new methods inherit
sensible defaults from `HasIdentitySurface`, which throws
`HostFeatureNotImplementedException` (translated to HTTP 501 by the
admin controllers).

- `listResources(string $serverId, ?string $tenantId): array` — tree
  payload for `GET /servers/{id}/resources`.
- `resourceContent(string $serverId, string $uri, ?string $tenantId): ?array`
  — single resource content for `GET /servers/{id}/resources/{uri}`;
  `null` on missing / cross-tenant (R30: 404, indistinguishable).
- `listPrompts(string $serverId, ?string $tenantId): array` —
  prompt catalog for `GET /servers/{id}/prompts`.
- `promptDetail(string $serverId, string $name, ?string $tenantId): ?array`
  — single prompt for `GET /servers/{id}/prompts/{name}`; `null` on
  missing / cross-tenant.
- `recentAudit(int|string|null $sinceId, ?string $tenantId): array`
  — driver for the `GET /events` SSE stream's poll loop.

#### Added — new controllers under `src/Http/Admin/`

- `ResourcesController` — `GET /servers/{id}/resources` +
  `GET /servers/{id}/resources/{uri}`. The `{uri}` route segment is
  matched by regex `.+` (the SPA sends a URL-encoded URI, which
  Symfony's router `rawurldecode`s once before matching — `[^/]+`
  would reject any URI containing `/`). The controller calls
  `rawurldecode()` once defensively (no-op when the router already
  decoded), then forwards the literal URI to the bridge. R19 — host
  owns any further escaping.
- `PromptsController` — `GET /servers/{id}/prompts` +
  `GET /servers/{id}/prompts/{name}`. Same R30 + 404-on-cross-tenant
  semantics as `ResourcesController`.
- `EventsSseController` — `GET /events`, `Content-Type: text/event-stream`.
  Polls `recentAudit()` at `mcp-pack.admin.sse.poll_ms` cadence
  (default 1000ms, minimum 100ms) and emits one
  `event: invocation\ndata: <json>\n\n` frame per new row. Hard-caps
  each connection at `mcp-pack.admin.sse.max_seconds` (default 300s)
  so a hung client cannot starve PHP-FPM workers forever. Probes the
  bridge ONCE up-front so a missing impl surfaces as a normal 501
  JSON envelope instead of a 200 stream with no data. Streams via
  `Symfony\Component\HttpFoundation\StreamedResponse` directly.
- `OpenApiController` — `GET /openapi.json`. Serves the canonical
  OpenAPI 3.1 spec from a static file at `resources/openapi/v1.5.json`.
  Memoised in a static property so subsequent requests within the
  same worker are O(1). Validates the JSON parses at load time — a
  malformed spec is a packaging bug, surfaces as HTTP 500
  `openapi_spec_unavailable`.

#### Added — OpenAPI 3.1 specification (`resources/openapi/v1.5.json`)

Single static JSON file covering every one of the 22 endpoints
v1.5 ships across W1.A → W1.D. Component schemas mirror the
`Downloads/askmydocs-mcp-pack-web-panel/project/data.js` reference
data model: `HostUser`, `HostTenant`, `HostApiKey`, `McpServer`,
`McpServerPage`, `McpServerWrite`, `Tool`, `AuditRow`, `AuditDetail`,
`BreakerState`, `Resource`, `ResourceContentEnvelope`, `Prompt`,
`Event`, plus `Error` / `ValidationError` envelopes. The SPA's
OpenAPI viewer pane consumes this directly without any host code
changes.

#### Added — per-feature flags

- `mcp-pack.admin.features.resources` (default `true`)
- `mcp-pack.admin.features.prompts` (default `true`)
- `mcp-pack.admin.features.events_sse` (default `true`)
- `mcp-pack.admin.features.openapi` (default `true`)

All four follow the standing W1.A pattern: routes registered
UNCONDITIONALLY, the per-feature check happens inside the controller
via `ResolvesAdminContext::featureGate()`, flipping a flag to `false`
returns HTTP 403 `feature_disabled` (NOT 404). The SPA can
distinguish "operator turned this section off" from "route does not
exist on this package version".

#### Added — SSE knobs

- `mcp-pack.admin.sse.poll_ms` — poll cadence for `recentAudit()`
  (default 1000, minimum 100). Lower values stream more aggressively
  but spend more PHP-FPM cycles per worker; raise above 1000ms for
  audit-only views that don't need real-time fidelity.
- `mcp-pack.admin.sse.max_seconds` — hard-cap on each connection's
  lifetime (default 300, minimum 1). The SPA's `EventSource`
  auto-reconnects when the stream ends, so this is a worker-hygiene
  knob rather than a UX one.

#### Added — tests

41 new tests across 6 files (full v1.5 W1.D delta) — bringing the
suite from 284 to 324. New files:

- `tests/Feature/Http/Admin/ResourcesControllerTest.php` (12 tests)
- `tests/Feature/Http/Admin/PromptsControllerTest.php` (10 tests)
- `tests/Feature/Http/Admin/EventsSseControllerTest.php` (5 tests)
- `tests/Feature/Http/Admin/OpenApiControllerTest.php` (3 tests)
- `tests/Feature/Http/Admin/W1DRoutesRegisteredTest.php` (1 test —
  architecture-style guard that all 6 W1.D routes stay registered
  when every feature flag is `false`)
- `tests/Unit/Http/OpenApiSpecFileTest.php` (4 tests — schema-file
  integrity: parses as JSON, declares OpenAPI 3.1, contains all 22
  endpoint paths in lock-step with the route set, declares the
  required component schemas)

Extended `tests/Support/FakeIdentityBridge.php` with `listResources`,
`resourceContent`, `listPrompts`, `promptDetail`, `recentAudit` stubs
following the same observable-property pattern as the W1.A/W1.C
methods. Extended `tests/Unit/Defaults/NullMcpHostBridgeIdentityTest.php`
with 5 new asserts verifying each new trait default throws
`HostFeatureNotImplementedException`.

### v1.5.0 — REST surface extension (W1.A — identity surface)

The package is growing a 16-endpoint REST surface across four
sub-waves (W1.A → W1.D) so the standalone
`padosoft/askmydocs-mcp-pack-admin` SPA can run against live data.
W1.A ships the identity layer.

#### Added — `McpHostBridgeContract` identity surface

Nine new optional methods on the contract — every existing host
bridge keeps compiling because the new methods either inherit a
sensible default from the new
`Padosoft\AskMyDocsMcpPack\Contracts\Concerns\HasIdentitySurface`
trait, OR throw `HostFeatureNotImplementedException` which the
admin controllers translate into HTTP 501.

- `currentUser(): ?HostUser` — actor identity for `GET /me`.
- `listTenants(): array<HostTenant>` — tenant switcher payload.
- `listApiKeys(int|string|null $userId): array<HostApiKey>` —
  R30-scoped to the active user when `$userId !== null`.
- `createApiKey(array $attrs): HostApiKey` — plaintext token in
  `HostApiKey::$plaintext`, surfaced exactly once at create time.
- `revokeApiKey(string $id): bool` — `false` is mapped to HTTP 404
  by `ApiKeysController::destroy`.
- `savePreferences(int|string $userId, array $prefs): HostUserPreferences`
  — write path locked to `currentUser()->id` server-side (R30).
- `auditFor(int|string $id)` / `replayAudit()` / `resetBreaker()` —
  signatures shipped now, wired in W1.C.

#### Added — value objects under `src/Support/`

- `HostUser` — readonly, `fromArray()` / `toArray()`.
- `HostTenant` — readonly, with `primary` flag.
- `HostApiKey` — readonly; `toArray()` omits the plaintext, only
  `toCreateArray()` exposes it.
- `HostUserPreferences` — readonly, schema-less `values` bag.

#### Added — new controllers under `src/Http/Admin/`

- `MeController` — `GET /me` + `POST /me/preferences`.
  Returns HTTP 401 (`unauthenticated`) when no actor is bound.
- `TenantsController` — `GET /tenants` with `meta.active_tenant_id`.
- `ApiKeysController` — `GET/POST/DELETE /api-keys`. Scopes are
  validated against `^[a-z0-9.-]+$` (R19 — input-escape-complete:
  no `%`, no `_`, no SQL LIKE wildcards). DELETE returns 404 when
  the host reports `revokeApiKey(): false`.

#### Added — FormRequest classes under `src/Http/Admin/Requests/`

- `UpdatePreferencesRequest` — `preferences` REQUIRED array, key
  ≤ 128 chars, JSON-serialisable bag.
- `CreateApiKeyRequest` — `name` 1..150 no control chars,
  `scopes` non-empty list of `^[a-z0-9.-]+$` strings deduped.

#### Added — per-feature flags

- `mcp-pack.admin.features.{me,tenants,api_keys}` — each defaults
  to `true` when `admin.enabled=true`. Setting a flag to `false`
  returns HTTP 403 `feature_disabled` for that section.

#### Added — opt-in publishable migrations

Two new migrations under `database/migrations-optional/`,
published via `--tag=mcp-pack-migrations` (alongside the audit
table) OR `--tag=mcp-pack-identity-migrations` for identity-only
publication. Hosts with their own preferences / API-key stores
skip publication.

- `mcp_user_preferences` — `(user_id, key) unique, value JSON`.
- `mcp_api_keys` — `(hashed_token unique, name, scopes JSON,
  last_used_at, created_by)`.

#### Added — `HostFeatureNotImplementedException`

A new package exception extending `McpException`. The admin
controllers catch it and emit HTTP 501 (`feature_not_implemented`)
with the offending method name in the message — the SPA renders a
graceful degraded state instead of a generic 500.

#### Tests

42 new tests across 7 files; new total: 158 (was 116).

### v1.5.0 — REST surface extension (W1.B — Servers CRUD + ToolsController)

W1.B extends the v1.4 read-mostly REST surface with the write-side
endpoints the SPA's Servers tab consumes, plus the cross-server
`GET /tools` flat aggregator. The wire shape mirrors the
`Downloads/askmydocs-mcp-pack-web-panel/project/data.js` reference
data model.

#### Added — `McpServerMutableRegistryContract` sub-interface

The base `McpServerRegistryContract` is unchanged (BC). The new
`McpServerMutableRegistryContract` extends it with four optional
methods that the v1.5 admin write paths consume:

- `paginate(?string $tenantId, array $filters, int $page, int $perPage): McpServerPage`
  — filter+slice view for the admin table; filters `q`, `status`,
  `transport`, `enabled`.
- `create(array $attributes): McpServerContract` — host owns id
  minting + uniqueness. The controller injects the trusted
  `tenant_id` (R30) before delegating.
- `update(string $id, array $attributes): McpServerContract` —
  in-place patch. The controller pre-checks tenant boundary
  (defence in depth).
- `delete(string $id): bool` — idempotent. The controller wraps the
  call in a `DB::transaction` closure (R21).

A `HasMutableRegistry` trait under `src/Contracts/Concerns/`
provides safe 501-throwing defaults so existing hosts can adopt the
sub-interface with one `use` line and override only the methods
they actually want to expose.

The service provider resolves the sub-interface contract by checking
whether the bound `McpServerRegistryContract` already implements
the sub-interface, otherwise falling back to the package's
`InMemoryMcpServerRegistry` — which adopts the trait AND overrides
`paginate()` with a working in-memory filter+slice so tests of the
read path don't need a host. `create()` / `update()` / `delete()`
keep throwing 501 on the in-memory registry by design.

#### Added — value object: `McpServerPage`

`final readonly` carrier of `array<int,McpServerContract> $data`
plus `total / per_page / current_page / last_page`. Mirrors
Laravel's `LengthAwarePaginator::toArray()['meta']` shape so hosts
backing the registry with Eloquent can fill it directly. `meta()`
helper surfaces the bag the controller serialises.

#### Added — new + extended controllers

- `ServersController::store(StoreServerRequest)` — `POST /servers`
  returning 201 + `Location` header. R30: trusted tenant attribute
  replaces wire `tenant_id` before delegation.
- `ServersController::update(UpdateServerRequest, string $id)` —
  `PATCH /servers/{id}`. Tenant guard upstream of the host call.
- `ServersController::destroy(Request, string $id)` — `DELETE
  /servers/{id}` returning 204 / 404. Wrapped in `DB::transaction`
  per R21.
- `ServersController::index` — extended with `?page=&per_page=&q=&
  status=&transport=&enabled=` so pagination is reachable. When any
  of those query params is present the controller routes through
  `paginate()` and surfaces `meta.{total, per_page, current_page,
  last_page, tenant_id}`. When none is present the controller keeps
  the v1.4 `forTenant()` unpaginated read path so legacy clients
  don't see a shape change.
- `ToolsController::index` — `GET /tools` flat aggregator across
  every server visible to the active tenant. Filters: `?q=&server_id=&
  destructive=true|false`. Output: `data[]` of
  `{server_id, server_name, name, desc, destructive, calls_24h, p50, schema}`
  + `meta.{total, server_count, tenant_id, unreachable_servers[]}`.
  Servers whose handshake throws `McpTransportException` are
  skipped + recorded under `meta.unreachable_servers[]` so the SPA
  can render a partial-data banner instead of failing the whole
  page.

#### Added — FormRequest classes under `src/Http/Admin/Requests/`

- `StoreServerRequest` — `name` 1..150 matching
  `[A-Za-z0-9._\-\s]+`, `transport` ∈ `{http, sse, stdio}`, `url`
  required ≤ 2048, optional `description` ≤ 500 / `owner` ≤ 150 /
  `allowed_tools[]` regex-escaped, `enabled` boolean. Control
  characters rejected in `withValidator()` (defensive against
  log-injection). Wire `tenant_id` accepted by the rules but
  stripped from `payload()` so the controller can never honour it.
- `UpdateServerRequest` — all fields optional (PATCH semantics);
  same R19 escape gates as `StoreServerRequest`. Crucially,
  `tenant_id` on the wire returns 422 — preventing cross-tenant
  re-parenting via UPDATE.

#### Added — config flags (default `true`)

- `mcp-pack.admin.features.servers_write` /
  `MCP_PACK_ADMIN_FEATURE_SERVERS_WRITE`
- `mcp-pack.admin.features.tools` / `MCP_PACK_ADMIN_FEATURE_TOOLS`

Routes are registered UNCONDITIONALLY; the feature check happens
inside the controller via `ResolvesAdminContext::featureGate()` and
returns HTTP 403 `feature_disabled` so the SPA can distinguish
"operator turned section off" from "route does not exist on this
package version".

#### Tests

48 new tests across 5 files; new total: 209 (was 161).

- `McpServerPageTest` — 5 cases (slice arithmetic, edge cases)
- `HasMutableRegistryTraitTest` — 4 cases (501 default per method)
- `InMemoryRegistryTest` — 7 new pagination cases
- `ServersControllerTest` — 21 new write-path + pagination cases
  (R30 trusted-tenant injection, 422 on wire `tenant_id`, 403
  cross-tenant, 404 missing, 501 unwired, 403 feature flag)
- `ToolsControllerTest` — 9 cases (flat aggregation, dedupe,
  destructive filter, q-filter, server_id filter, allowed_tools
  honoured, unreachable-servers meta)

#### Sub-waves not yet shipped

- W1.D — Resources + Prompts + SSE + OpenAPI + `v1.5.0` tag.

### v1.5.0 — REST surface extension (W1.C — Tool invoke + Audit replay + Breaker reset)

W1.C closes the action surface for v1.5: the admin SPA can now
invoke tools, drill into a single audit row, replay a previous
tool call, and reset a circuit breaker — all under R30 cross-tenant
isolation and R21 atomic single-use confirm-token semantics for
destructive paths.

#### Added — controller endpoints

- **`POST /servers/{id}/tools/{name}/invoke`** —
  `ServersController::invoke()` runs a single tool through the
  existing `ToolInvoker`. The audit row is written by `ToolInvoker`
  exactly like normal tool calls. The tool's `destructive: true`
  metadata (declared by the upstream MCP server's `tools/list`
  handshake) triggers a `confirmation_required` 422 unless the
  request carries `confirm: true`. `McpToolNotAuthorizedException`
  maps to 403 `not_authorized`; `McpTransportException` and any
  error captured into `ToolCallResult` map to 502 `transport_error`
  (R14 — no 200-on-failure).
- **`GET /audit/{id}`** — `AuditController::show()` returns the
  rich drilldown payload (request / response / headers / timeline /
  meta) via the host bridge's new `auditFor(int|string $id, ?string
  $tenantId)` method. The controller passes the trusted tenant id;
  cross-tenant rows are invisible (404, not 403 — existence does
  not leak per R30).
- **`POST /audit/{id}/replay`** — `AuditController::replay()`
  re-fires the audited tool call under a two-call confirm-token
  protocol. First POST (no `confirm_token`) mints + caches the
  token + 202; second POST presents the token; the controller
  validates the mint-side marker then forwards to the host's
  `replayAudit($id, $token)` which atomically consumes + replays
  inside its own `DB::transaction` + `lockForUpdate()` closure.
- **`POST /circuit-breaker/{key}/reset`** —
  `CircuitBreakerController::reset()` mirrors the replay protocol.
  `{key}` is the URL-encoded `<server_id>:<tool_name>` compound.

#### Added — R21 confirm-token infrastructure

- `Padosoft\AskMyDocsMcpPack\Support\McpAdminConfirmToken` — readonly
  value object: `{token, scope, target_id, tenant_id, expires_at,
  used_at}`. Token plaintext is `tok_` + 32 hex chars (128 bits of
  entropy). Default TTL 120 s.
- `Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\MintsConfirmTokens`
  trait — shared mint + present + forget logic backed by the
  configured cache store. The package owns mint + present; the host
  owns atomic consume + `used_at` write inside a `DB::transaction`.
- Contract docblocks on `McpHostBridgeIdentityContract::replayAudit()`
  and `::resetBreaker()` document the host-side R21 contract
  explicitly (lock window MUST hold until `used_at` write commits).

#### Added — FormRequest classes

- `InvokeToolRequest` — `arguments` MUST be present + array,
  nesting ≤ 8 levels, every string leaf R19-scrubbed for control
  characters; `confirm` optional boolean.
- `ReplayAuditRequest` — optional `confirm_token` matching the
  exact `tok_[a-f0-9]{32}` regex (R19 — narrow shape).
- `ResetBreakerRequest` — same shape as the replay request.

#### Added — config flags (default `true`)

- `mcp-pack.admin.features.tool_invoke`
- `mcp-pack.admin.features.audit_show`
- `mcp-pack.admin.features.audit_replay`
- `mcp-pack.admin.features.breaker_reset`

Each backs an env var of the form
`MCP_PACK_ADMIN_FEATURE_<UPPER>`. Routes are registered
unconditionally; the per-feature gate happens inside the
controller via `ResolvesAdminContext::featureGate()` so the SPA
can distinguish "operator disabled this section" (403) from "this
package version does not implement the section" (404 — never
reached).

#### Changed — contract signature

- `McpHostBridgeIdentityContract::auditFor(int|string $id)` is now
  `auditFor(int|string $id, ?string $tenantId = null)`. The
  parameter is opt-in (default `null`) so existing implementations
  keep compiling; new hosts MUST scope the SELECT by the trusted
  tenant id passed in. `HasIdentitySurface` trait default updated
  to match.

#### Tests

PHPUnit total: 223 → 284 (+61) after the iter-1 fixes (originally +58
on the first push; the 9 Copilot findings added 3 R21-pinning tests
in the destructive-invoke flow, the rest are refactors that preserve
count).

- `ServersControllerInvokeTest` — 15 cases (happy, cross-tenant
  404, 404 tool not in allowlist, destructive mint, destructive
  consume, destructive 422 reuse, destructive 422 forged,
  transport 502, 502 on ToolCallResult.error, 403 not authorised,
  403 feature flag, route stays registered, 422 missing arguments,
  422 control char, 422 nesting depth).
- `AuditControllerShowReplayTest` — 16 cases (drilldown happy,
  404 missing, 404 cross-tenant, 403 feature flag, 501 unwired,
  replay mint happy, mint 404 cross-tenant, mint 404 missing,
  consume happy, consume 422 reuse, consume 422 forged, consume
  422 malformed, consume 404 audit-disappeared, 403 feature
  flag, 501 unwired, route registered).
- `CircuitBreakerControllerResetTest` — 12 cases (mint happy,
  mint 404 cross-tenant, 422 bad key, consume happy, consume 422
  reuse, consume 422 forged, consume 422 malformed, consume 422
  cross-target, 403 feature flag, 501 unwired, route registered,
  404 bogus server).
- `McpAdminConfirmTokenTest` — 8 cases (mint shape, entropy
  uniqueness, default TTL, TTL floor, expiry check, used flag,
  mint response shape, expired response clamps).
- `InvokeToolRequestTest` — 10 cases (rules validation surface).

## [1.4.0] — 2026-05-15

### Added — admin REST backend

A small read-mostly REST surface the standalone
`padosoft/askmydocs-mcp-pack-admin` SPA (post-v7.0 cycle) consumes.
The package itself ships NO frontend — auth is host-driven; the
package wires the routes only when `MCP_PACK_ADMIN_ENABLED=true`.

- **`GET /api/admin/mcp-pack/servers`** — list `McpServerContract`
  entries visible to the active tenant.
- **`GET /api/admin/mcp-pack/servers/{id}`** — show one server,
  with tenant-boundary enforcement (R30).
- **`POST /api/admin/mcp-pack/servers/{id}/handshake?force=0|1`** —
  trigger `McpHandshakeService::refresh()`; returns the cached
  capabilities + tool catalog, or **502** with
  `error.code=handshake_failed` on `McpTransportException`.
- **`GET /api/admin/mcp-pack/servers/{id}/tools`** — list tools
  from the cached handshake (the catalog filters by
  `allowedTools()` when the server configures it).
- **`GET /api/admin/mcp-pack/audit`** — paginated audit query with
  filters `server_id`, `tool_name`, `status`, `from`, `to`,
  `per_page` (clamped 1..200). Tenant-scoped automatically via the
  trusted middleware attribute / `data_get($user, 'tenant_id')`.
  Returns the configurable `mcp-pack.audit_model` so host
  subclasses surface their own columns transparently.
- **`GET /api/admin/mcp-pack/circuit-breaker?server={id}&tool={name}?`** —
  read-only inspection backed by `CircuitBreaker::peekState()` so
  dashboards never consume the half-open probe slot. Omit `tool`
  to sweep every entry in the server's `allowedTools()`.
- **`Http\Admin\ServersController`** / **`AuditController`** /
  **`CircuitBreakerController`** — three focused controllers,
  each resolving the active tenant from
  `$request->attributes->get('mcp_pack.tenant_id')` (R30: never
  from a client header).
- **New config block** `mcp-pack.admin.{enabled,prefix,middleware}`
  via `MCP_PACK_ADMIN_*` env vars. Default prefix
  `api/admin/mcp-pack`; default middleware `['api']`. Hosts wire
  Sanctum + RBAC + role gates by overriding `middleware`.

### Tenant-scoped lookups + accurate `cached` signal

- All admin lookups select from `forTenant($tenantId)` first
  (rather than `find($id)` + post-hoc boundary check), so two
  tenants reusing the same server id surface their OWN entry
  instead of seeing another tenant's row leak through to the
  404 path.
- `POST /servers/{id}/handshake` reports `cached: true` ONLY when
  the handshake cache really hit before the call (new
  `McpHandshakeService::peek()` probe before `refresh()`). The
  previous shape derived `cached` from the `force` flag alone,
  which lied about first-time non-cached handshakes.
- `GET /circuit-breaker` in sweep mode falls back to the
  handshake-cached catalog when `allowedTools()` is empty (the
  "all advertised tools" mode), so the sweep is not silently
  empty for servers that don't pre-filter.
- `AuditController` validates that the configured
  `mcp-pack.audit_model` is an Eloquent `Model` subclass before
  calling `::query()` — a misconfigured FQCN now surfaces a clean
  JSON 500 (`error.code = audit_model_missing`) instead of a
  fatal "method not found" error.

### Tests

- **116 tests / 316 assertions** all green (was 95/242 in v1.3.0).
  +21 tests across `ServersControllerTest` (9 cases),
  `AuditControllerTest` (5 cases), and
  `CircuitBreakerControllerTest` (7 cases) covering the trusted-
  tenant-attribute path, tenant-boundary enforcement, missing /
  unknown server 404, handshake force flag propagation,
  transport-failure 502, tool filtering by `allowedTools`, audit
  pagination + filters + per-page clamping, circuit-breaker
  explicit-tool + sweep modes, breaker tenant boundary, AND four
  iter-1 regression cases: tenant-scoped lookup under id reuse
  (servers + circuit-breaker), accurate `cached` reporting,
  sweep fallback to handshake cache, and non-Eloquent audit
  model surfacing a clean 500.

### Deferred to v1.5.0

CRUD writes on the registry (`POST /servers`, `PATCH /servers/{id}`,
`DELETE /servers/{id}`) require a writable
`McpServerRegistryWriteContract` that doesn't exist yet — every
host today persists servers in its own table (e.g. AskMyDocs's
`mcp_servers` Eloquent model). v1.5.0 will add the contract +
the corresponding routes.

### Compatibility

Drop-in extension on top of v1.3.x. Routes are disabled by
default; opt in via `MCP_PACK_ADMIN_ENABLED=true` once the
middleware stack is configured.

## [1.3.0] — 2026-05-15

### Added — resilience (circuit breaker + adaptive retry)

Opt-in resilience layer wrapped around every upstream tool call.
Both knobs are independent — enable the breaker without retries
for hard fail-fast, or enable retries without the breaker for
naïve retry-on-failure.

- **`Resilience\CircuitBreaker`** — per-`(serverId, toolName)`
  three-state machine (`CLOSED` / `OPEN` / `HALF_OPEN`) backed by
  the Laravel cache so state survives across processes and queue
  workers. `failureThreshold` consecutive failures OPEN the
  breaker; after `recoverySeconds` the breaker auto-transitions
  to `HALF_OPEN` and allows ONE probe; success → `CLOSED`,
  failure → `OPEN` again. State persists under a single cache key
  per (server, tool) so dashboards can inspect it directly.
- **`Resilience\RetryBudget`** — token-bucket per
  `(tenantId, serverId)`. Each retry consumes a token; bucket
  refills every `bucketWindowSeconds`. **R30**: cross-tenant
  isolation by design — a misbehaving tenant cannot exhaust
  another tenant's retry budget against the same upstream.
- **`Resilience\ResilienceMediator`** — wraps the upstream call
  with three layers: pre-check (short-circuit when breaker is
  `OPEN`), retry loop (exponential backoff capped at
  `maxBackoffMs`, gated by the budget), post-record (success /
  failure feeds the breaker). Non-transport exceptions bubble
  immediately — caller bugs are not retried.
- **`Exceptions\CircuitOpenException`** — extends
  `McpTransportException` so callers that already handle
  transport failures treat the fast-fail path identically. Carries
  `retryAfterSeconds` for dashboards / clients.
- **5 telemetry events** under `Resilience\Events\*`:
  `CircuitOpened`, `CircuitClosed`, `CircuitHalfOpened`,
  `RetryAttempted`, `RetryExhausted` (reason:
  `max_attempts` | `budget_depleted`).
- **`ToolInvoker`** routes through the mediator when at least one
  of `circuit_breaker.enabled` / `retry.enabled` is true.
  Constructor-injected, optional — when disabled the invoker
  behaves exactly as in v1.2.
- **New config block** `mcp-pack.resilience` driven by
  `MCP_PACK_CB_*` and `MCP_PACK_RETRY_*` env vars + an optional
  dedicated `MCP_PACK_RESILIENCE_CACHE_STORE` so breaker / budget
  state can route through a separate cache driver.

### Independent layer flags

The two resilience layers are independent at runtime, not just at
config time. The mediator constructor accepts `$breakerEnabled` /
`$retryEnabled` and the service provider wires them from
`mcp-pack.resilience.circuit_breaker.enabled` /
`mcp-pack.resilience.retry.enabled`. When retries are disabled the
loop runs exactly once and the first transport failure surfaces
immediately; when the breaker is disabled the pre-check and
post-record are skipped so a string of failures cannot trip a
circuit the operator did not enable.

### Tests

- **95 tests / 242 assertions** all green (was 70/173 in v1.2.0).
  +25 tests across `CircuitBreakerTest` (9 cases),
  `RetryBudgetTest` (5 cases), and `ResilienceMediatorTest`
  (11 cases) covering state transitions, threshold open, TTL roll
  to half-open, success-resets-counter, probe-fail re-opens,
  tenant + server budget isolation, window-rollover refill,
  retry-then-success, max-attempts exhausted, budget-depleted
  abort, open-circuit short-circuit, non-transport exceptions
  not retried, backoff capping, breaker-only does NOT retry
  transport failures, retry-only does NOT engage the breaker,
  `peekState()` is pure (no cache mutation, no events),
  HALF_OPEN re-open preserves cumulative failure count in the
  `CircuitOpened` event payload, `maxAttempts === 1` does NOT
  fire misleading `RetryExhausted` telemetry, and
  `CircuitOpenException` forwards `$code` + `$previous`.

### Compatibility

Drop-in extension on top of v1.2.x. Existing surfaces unchanged
when resilience is left disabled (the default). Hosts opt in via
`MCP_PACK_CB_ENABLED=true` and / or `MCP_PACK_RETRY_ENABLED=true`.

## [1.2.0] — 2026-05-15

### Added — first-class server-side

The package now operates in BOTH directions: as an MCP client
(v1.0+) AND as an MCP server. Remote agents (Claude Desktop /
Cursor / VS Code / any MCP client) can drive a Laravel app
through stdio or HTTP using a host-supplied tool catalog.

- **`McpServerExposureContract`** — host implements once to publish
  its tool / resource / prompt catalog (tenant-scoped). Default
  `NullMcpServerExposure` publishes nothing — production hosts
  override.
- **`ServerSide\JsonRpcRequestHandler`** — transport-agnostic
  dispatcher for `initialize` / `tools/list` / `tools/call` /
  `resources/list` / `resources/read` / `prompts/list` /
  `prompts/get`. Enforces `McpToolAuthorizerContract` per tool +
  maps every failure mode to JSON-RPC spec error codes (-32600
  invalid request, -32601 method not found, -32602 invalid params,
  -32603 internal, -32001 server-defined for auth / not-found,
  -32700 parse).
- **`ServerSide\StdioRunner`** — long-lived loop reading
  newline-delimited JSON from STDIN, writing responses to STDOUT.
  STDIN / STDOUT streams are injectable so tests drive it against
  `php://memory`.
- **`Http\McpServerHttpController`** — Laravel HTTP front-door
  (POST). Host wires Sanctum / RBAC / per-tenant middleware via
  `config('mcp-pack.server_side.http.middleware')`. Disabled by
  default — opt in once auth stack is correct.
- **`mcp-pack:serve`** artisan command — boots the stdio runner.
  Wire it from Claude Desktop config / Cursor / VS Code under
  `command: php`, `args: [/path/to/host/artisan, mcp-pack:serve]`.
- **New config block** `mcp-pack.server_side.http.{enabled,prefix,middleware}`
  driven by `MCP_PACK_SERVER_HTTP_*` env vars.

### Tests

- **70 tests / 171 assertions** all green (was 58/144 in v1.1.0).
  +12 new tests across `JsonRpcRequestHandlerTest` (8 cases) and
  `StdioRunnerTest` (4 cases) covering initialize / tools-list +
  auth filter / tools-call invocation + unknown-tool / missing-name
  param / unknown-method / notification-no-response / empty-catalog
  + stdio: single-request / round-trip / parse-error resilience /
  notification-drop.

### Compatibility

Drop-in extension on top of v1.1.x. Existing client-side surfaces
unchanged. HTTP route registration is gated behind
`MCP_PACK_SERVER_HTTP_ENABLED=true` — no host that doesn't opt in
sees any new route appear.



## [1.1.0] — 2026-05-15

### Added

- **SSE transport** — `Padosoft\AskMyDocsMcpPack\Transports\SseJsonRpcTransport`
  for HTTP+SSE remote MCP gateways. JSON-RPC requests are POSTed; the
  response is parsed from the SSE event stream (handles intermediate
  progress notifications, multi-line `data:` fields, and the final
  response frame matching the request id). `McpClient::transportFor()`
  now dispatches the `sse` transport string to this class.
- **`McpResourceContract`** + `McpClient::listResources()` +
  `McpClient::readResource(string $uri)` — JSON-RPC `resources/list`
  and `resources/read` per the MCP spec. Resources are PASSIVE
  (readable, not invocable).
- **`McpPromptContract`** + `McpClient::listPrompts()` +
  `McpClient::getPrompt(string $name, array $arguments = [])` —
  JSON-RPC `prompts/list` and `prompts/get`. Prompts are
  parameterised templates the host can prepend to the conversation
  as a starting point.
- 13 new tests across `tests/Feature/Services/McpClientResourcesPromptsTest.php`
  and `tests/Feature/Transports/SseJsonRpcTransportTest.php` —
  bringing the suite to 55 tests / 134 assertions.

### Compatibility

- Drop-in extension on top of v1.0.x — no contract changes on the
  existing surface. Consumers that only use tools continue to work
  unmodified.

## [1.0.1] — 2026-05-15

### Fixed

- The `create_mcp_tool_call_audit_table` migration now guards both
  `up()` and `down()` on the existing schema. `up()` returns early
  when `Schema::hasTable('mcp_tool_call_audit')` is already true
  (previously `php artisan migrate` failed with "table already
  exists" when the package was installed on top of a host that
  predated it). `down()` is symmetric: it skips the `dropIfExists`
  when the table carries any host-owned columns
  (`input_json_redacted`, `user_id`, `error_json`) so a future
  rollback cannot erase the operator's audit data.

  Hosts are expected to ALTER their existing table to add the
  `input_hash` + `actor` columns the package writes and to point
  `mcp-pack.audit_model` at a subclass that satisfies both schemas
  (Recipe 5 in the README).

## [1.0.0] — 2026-05-15

### Added

- Initial extraction from AskMyDocs v6.x (planned for v1.0.0).
- Five contracts: `McpToolContract`, `McpServerContract`,
  `McpServerRegistryContract`, `McpToolAuthorizerContract`,
  `McpHostBridgeContract`.
- Two transports: `HttpJsonRpcTransport`, `StdioJsonRpcTransport`.
- `McpToolCallingService` — multi-turn tool-calling loop with
  iteration budget.
- `McpHandshakeService` — caches `initialize` + `tools/list`.
- `ToolInvoker` — invokes upstream tools and writes audit rows.
- `McpToolCallAudit` Eloquent model + migration.
- `mcp-pack:ping` Artisan diagnostic.
- Default safe-by-default implementations: `NullMcpHostBridge`,
  `NullMcpToolAuthorizer`, `InMemoryMcpServerRegistry`.
- CI matrix: PHP 8.3 / 8.4 / 8.5 × Laravel 11 / 12 / 13.
