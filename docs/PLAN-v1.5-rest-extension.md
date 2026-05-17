# PLAN — `padosoft/askmydocs-mcp-pack` v1.5.0 — REST surface extension

> **Cycle anchor.** This document is the binding plan for the v1.4 → v1.5
> bump. Every sub-PR (W1.A → W1.D) maps to one section below; deviations
> require updating this file first.

## Why this cycle

`padosoft/askmydocs-mcp-pack-admin` v1.0.x is a pixel-perfect React SPA
running on bundled fixtures (`resources/js/lib/data.ts`). To wire it to
live data, the parent package must expose **16 endpoints** the v1.4
admin surface does not yet ship. The reference data-model is the Claude
Design handoff at `Downloads/askmydocs-mcp-pack-web-panel/project/data.js`.

## Architectural constraint

The package is a **framework-agnostic library**. It does NOT bind to an
Eloquent `User` model, an Eloquent `Tenant` model, or any concrete
domain. Per-tenant + per-user behaviour goes through two contracts:

- **`McpServerRegistryContract`** — per-tenant catalog of servers.
  Hosts back this with their preferred storage. The v1.5 extension adds
  optional CRUD + pagination methods with default trait implementations
  so existing hosts (`NullMcpHostBridge`-shaped) keep compiling.

- **`McpHostBridgeContract`** — bridges to the host's chat model. v1.5
  extends it with optional `currentUser()`, `listTenants()`,
  `listApiKeys()`, `createApiKey()`, `revokeApiKey()`, `savePreferences()`,
  `auditFor()`, `replayAudit()`, `resetBreaker()` methods. The default
  `NullMcpHostBridge` implementation returns sentinel empty / "501 Not
  Implemented" markers so the SPA degrades gracefully when the host
  hasn't wired the bridge yet.

This keeps standalone-agnostic invariants intact (zero refs to host
domain models in `src/`) per the org-wide
[[feedback_packages_standalone_agnostic]] rule.

## Endpoint inventory (v1.5 delta vs v1.4)

✅ = shipped in v1.4 · 🆕 = added in v1.5

| Method | Path | Status |
|---|---|---|
| GET | `/me` | 🆕 |
| POST | `/me/preferences` | 🆕 |
| GET | `/tenants` | 🆕 |
| GET | `/servers` | ✅ |
| POST | `/servers` | 🆕 |
| GET | `/servers/{id}` | ✅ |
| PATCH | `/servers/{id}` | 🆕 |
| DELETE | `/servers/{id}` | 🆕 |
| POST | `/servers/{id}/handshake` | ✅ |
| GET | `/servers/{id}/tools` | ✅ |
| POST | `/servers/{id}/tools/{name}/invoke` | 🆕 |
| GET | `/tools` (flat) | 🆕 |
| GET | `/servers/{id}/resources` (tree) | 🆕 |
| GET | `/servers/{id}/resources/{uri}` (content) | 🆕 |
| GET | `/servers/{id}/prompts` | 🆕 |
| GET | `/servers/{id}/prompts/{name}` | 🆕 |
| GET | `/audit` (list, filterable) | ✅ |
| GET | `/audit/{id}` (drilldown) | 🆕 |
| POST | `/audit/{id}/replay` | 🆕 |
| GET | `/circuit-breaker` | ✅ |
| POST | `/circuit-breaker/{id}/reset` | 🆕 |
| GET | `/events` (SSE) | 🆕 |
| GET | `/api-keys` | 🆕 |
| POST | `/api-keys` | 🆕 |
| DELETE | `/api-keys/{id}` | 🆕 |
| GET | `/openapi.json` | 🆕 (final wave) |

**Net delta**: 6 v1.4 → 22 v1.5 (+16 endpoints).

## Sub-wave decomposition

### W1.A — Contracts + identity surface (smaller, lands first)

- Extend `McpHostBridgeContract` with the 9 new optional methods listed
  above. Wrap them in a `HasIdentitySurface` trait providing safe
  defaults so existing hosts compile.
- Update `NullMcpHostBridge` to return sentinel empty results.
- Add new value-objects: `HostUser`, `HostTenant`, `HostApiKey`,
  `HostUserPreferences`.
- New controllers (HTTP/Admin):
  - `MeController` — `GET /me` + `POST /me/preferences`
  - `TenantsController` — `GET /tenants`
  - `ApiKeysController` — `GET/POST/DELETE /api-keys`
- New FormRequest classes per POST/PATCH/DELETE.
- New config flags under `mcp-pack.admin.features.*` (gate each new
  feature so operators can disable per-section if they want).
- New migrations (publishable, not auto-loaded — opt-in):
  - `mcp_user_preferences` table — `(user_id, key, value, updated_at)`
  - `mcp_api_keys` table — `(id, name, scopes_json, hashed_token,
    last_used_at, created_at, created_by)` — only used by hosts that
    don't already have their own API-key store.
- PHPUnit:
  - ~25 new tests (`MeControllerTest`, `TenantsControllerTest`,
    `ApiKeysControllerTest`, `NullMcpHostBridgeIdentityTest`,
    `MeUpdatePreferencesRequestTest`, `CreateApiKeyRequestTest`)
- CHANGELOG entry stub for v1.5.0 (W1.A line).

### W1.B — Servers CRUD + extended registry

- Extend `McpServerRegistryContract` with optional `paginate()`,
  `create()`, `update()`, `delete()`. Add `HasMutableRegistry` trait
  default. Update `InMemoryMcpServerRegistry` for tests.
- Extend `ServersController` with `store()`, `update()`, `destroy()`
  methods.
- New FormRequest: `StoreServerRequest`, `UpdateServerRequest`.
- Filter parsing: `?tenant=&q=&status=&transport=&enabled=` on
  `index()`. Pagination: `?page=N&per_page=M` + `meta.{total, per_page,
  current_page, last_page}`.
- New `GET /tools` flat aggregator that walks the registry's enabled
  servers + dedupes by `(server_id, tool_name)`.
- PHPUnit: ~20 new tests + 1 architecture test ensuring the new
  optional methods do not break existing `NullMcpHostBridge` consumers.

### W1.C — Audit drilldown + replay + breaker reset

- `AuditController::show($id)` — single audit row + parsed
  `request_json`/`response_json`/`headers_json`/`timeline_json` +
  `meta.{pii_flags, cb_state_before, cb_state_after, cache_hit,
  retries}`. Reuses the existing `McpToolCallAudit` model.
- `AuditController::replay($id)` — re-fires the audited tool call via
  `ToolInvoker` with **atomic single-use semantics per R21** — replay
  tokens minted on POST, recorded atomic in the same `DB::transaction`
  closure as the invocation. New migration: `mcp_audit_replay_log`.
- `CircuitBreakerController::reset($id)` — calls `CircuitBreaker::reset()`
  with R21 atomic guard (single-use confirm token per breaker reset).
- `ServersController::invoke($id, $name)` — POST tool-call payload
  through `ToolInvoker`. The invocation logs to `mcp_tool_call_audit`
  exactly like normal tool calls. Honours R30 cross-tenant isolation.
- ~20 new tests.

### W1.D — Resources + Prompts + SSE + OpenAPI + tag

- New controllers `ResourcesController` + `PromptsController` querying
  the host bridge for cached `(server_id → tree)` data; falls back to
  the upstream `resources/list` + `prompts/list` JSON-RPC method via
  `McpClient` when the cache misses.
- `EventsSseController` — emits one Server-Sent-Event per
  `mcp_tool_call_audit` row newer than the last sent id. Polled at 1s
  cadence (configurable). Closes when client disconnects.
- `OpenApiController` — serves the canonical OpenAPI 3.1 JSON
  generated from a single `resources/openapi/v1.5.json` file kept in
  the package source.
- ~15 new tests.
- CHANGELOG final v1.5.0 entry.
- Tag `v1.5.0` GitHub Release.

## Acceptance gates (per sub-wave)

Each W1.x sub-PR must:

1. Pass the existing 7-cell CI matrix (PHP 8.3/8.4 × Laravel 11/12/13 +
   PHP 8.5 × Laravel 13).
2. Add new tests for every new endpoint + every new FormRequest
   validation path.
3. Honour [[canonical-awareness]], [[cross-tenant-isolation]] (R30),
   [[security-invariants-atomic-or-absent]] (R21), [[surface-failures-loudly]]
   (R14), [[input-escape-complete]] (R19) wherever the surface touches
   user input.
4. Get a clean Copilot review (R36 loop until 0 outstanding must-fix
   findings) before merge.
5. Final wave (W1.D) tags `v1.5.0-rcN` per R39, then `v1.5.0` GA at the
   closure commit.

## What v1.5 deliberately does NOT do

- **No SPA changes**. Wiring the SPA happens in
  `padosoft/askmydocs-mcp-pack-admin` v1.1.0 (W2–W5 of the parent
  cycle).
- **No host AskMyDocs changes**. The composer bump happens in W6 of
  the parent cycle.
- **No persisted server catalog by default**. Hosts that want
  Eloquent-backed servers wire their own implementation of the
  extended `McpServerRegistryContract`; the package's
  `InMemoryMcpServerRegistry` remains in-memory for tests.
- **No host RBAC**. Authorization is the operator's responsibility via
  the `middleware` config knob; the package surfaces `currentUser()` so
  the operator can call `Gate::forUser()` from their middleware.
