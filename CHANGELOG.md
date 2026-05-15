# Changelog

All notable changes to `padosoft/askmydocs-mcp-pack` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

### Tests

- **111 tests / 298 assertions** all green (was 95/242 in v1.3.0).
  +16 tests across `ServersControllerTest` (7 cases),
  `AuditControllerTest` (4 cases), and
  `CircuitBreakerControllerTest` (5 cases) covering the trusted-
  tenant-attribute path, tenant-boundary enforcement, missing /
  unknown server 404, handshake force flag propagation,
  transport-failure 502, tool filtering by `allowedTools`, audit
  pagination + filters + per-page clamping, circuit-breaker
  explicit-tool + sweep modes, and breaker tenant boundary.

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
