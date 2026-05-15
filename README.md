# padosoft/askmydocs-mcp-pack

[![Latest Version](https://img.shields.io/packagist/v/padosoft/askmydocs-mcp-pack.svg?style=flat-square)](https://packagist.org/packages/padosoft/askmydocs-mcp-pack)
[![PHP Version](https://img.shields.io/packagist/php-v/padosoft/askmydocs-mcp-pack.svg?style=flat-square)](https://packagist.org/packages/padosoft/askmydocs-mcp-pack)
[![Laravel Version](https://img.shields.io/badge/laravel-11.x%20%7C%2012.x%20%7C%2013.x-FF2D20.svg?style=flat-square)](https://laravel.com)
[![License](https://img.shields.io/packagist/l/padosoft/askmydocs-mcp-pack.svg?style=flat-square)](LICENSE)
[![Tests](https://github.com/padosoft/askmydocs-mcp-pack/actions/workflows/tests.yml/badge.svg)](https://github.com/padosoft/askmydocs-mcp-pack/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/padosoft/askmydocs-mcp-pack.svg?style=flat-square)](https://packagist.org/packages/padosoft/askmydocs-mcp-pack)

> **Framework-agnostic [Model Context Protocol](https://modelcontextprotocol.io) plumbing for Laravel.**
> Contracts, multi-turn tool-calling orchestrator, stdio + HTTP transports, audit trail, RBAC hooks.
> Powers [AskMyDocs](https://github.com/lopadova/AskMyDocs) and reusable in any Laravel AI app.

---

## 🚀 AI vibe-coding pack included

Every Padosoft package ships a `.claude/` folder with curated skills, rules,
and commands so Claude Code, Cursor, Copilot, and any other LLM agent
can drive the package productively from day one. The pack documents the
extension points the framework guarantees as stable (contracts,
events, config keys) and the ones that are intentionally private —
so AI agents stop guessing and start composing.

```bash
# Drop into a fresh consumer project
composer require padosoft/askmydocs-mcp-pack
cp -r vendor/padosoft/askmydocs-mcp-pack/.claude ./
# Then ask Claude Code: "wire the orchestrator into my host bridge"
```

---

## Table of contents

1. [Why this package?](#why-this-package)
2. [Features at a glance](#features-at-a-glance)
3. [Comparison vs alternatives](#comparison-vs-alternatives)
4. [Installation](#installation)
5. [Quick start (3 minutes)](#quick-start-3-minutes)
6. [Architecture](#architecture)
7. [Core concepts](#core-concepts)
8. [Configuration reference](#configuration-reference)
9. [Recipes](#recipes)
10. [Extension points](#extension-points)
11. [Testing](#testing)
12. [Compatibility matrix](#compatibility-matrix)
13. [Roadmap](#roadmap)
14. [Changelog](#changelog)
15. [License](#license)

---

## Why this package?

[MCP](https://modelcontextprotocol.io) is the open standard Anthropic
released in November 2024 for **LLM ⇆ tool** wire-format
interoperability. Within months it was adopted by Cursor, Claude
Desktop, VS Code, Cline, Continue, Sourcegraph Cody, OpenAI's Realtime
API, and a long tail of editor extensions and agentic frameworks.

What MCP gives you:

- A **JSON-RPC 2.0 contract** for `initialize`, `tools/list`,
  `tools/call`, `resources/*`, `prompts/*`.
- Transport choice — **stdio** (child process) for desktop tools and
  **HTTP/SSE** for cloud gateways.
- A **growing public catalog** of servers (filesystem, GitHub, Slack,
  Postgres, Notion, Sentry, …) you can plug into any client.

What MCP does **not** give you (and what this pack adds):

- A **multi-turn tool-calling loop** that drives the model → tools →
  model → tools cycle with budget caps and audit trail.
- **RBAC / tenant gates** in front of every tool invocation.
- An opinionated **audit table** with SHA-256 input/output hashes,
  duration, status, and error excerpts — the kind of trail an EU
  AI-Act audit will ask for.
- **Provider-agnostic** integration: the orchestrator does NOT bind
  the OpenAI / Anthropic / Gemini SDK. You implement a 30-line
  `McpHostBridgeContract` against your existing chat manager, and the
  pack handles the rest.

That is exactly the shape AskMyDocs needed for v7.0. We extracted it
so the next Laravel AI app does not have to reinvent it.

---

## Features at a glance

| ✓ | Capability                                                                                                 |
| - | ---------------------------------------------------------------------------------------------------------- |
| 🔌 | **Two transports out of the box** — `stdio` (Symfony Process) and `http` (Guzzle via Laravel HTTP client). |
| 🧠 | **Multi-turn tool-calling orchestrator** — bounded by `max_iterations`, with deterministic message reshaping. |
| 🛡️ | **Tenant-scoped tool catalog** — `forTenant($id)` filters by tenant; cross-tenant leakage is structurally impossible. |
| 🚦 | **Per-call RBAC** — `McpToolAuthorizerContract` gates every tool BEFORE it appears in the catalog. |
| 🧾 | **Hash-only audit trail** — `mcp_tool_call_audit` rows store SHA-256 of input + result, NOT raw payloads. |
| 🔄 | **Cached handshakes** — `initialize` + `tools/list` are cached per (tenant, server) for 5 min by default. |
| 🧪 | **Stub-friendly tests** — `McpClient::useTransportResolver()` swaps the transport with a one-line closure. |
| 📦 | **Zero-AI-SDK lock-in** — pluggable host bridge; works with any provider. |
| 📊 | **Production telemetry** — every tool call carries `duration_ms`, status, and error excerpt. |
| 🧰 | **Artisan diagnostics** — `php artisan mcp-pack:ping` walks the registry and prints a per-server status table. |

---

## Comparison vs alternatives

| Feature                                | **askmydocs-mcp-pack** | `laravel/mcp` (Laravel first-party) | `php-llm/mcp-sdk` (community) | Roll-your-own           |
| -------------------------------------- | ---------------------- | ----------------------------------- | ----------------------------- | ----------------------- |
| MCP **client** support (call upstream) | ✅ stdio + http        | ❌ server-only                       | ✅ stdio + http                | DIY                     |
| MCP **server** support (expose tools)  | ⚠️ via host           | ✅                                   | ✅                             | DIY                     |
| Multi-turn tool-calling **loop**       | ✅                     | ❌                                   | ❌                             | DIY (~300 LOC)          |
| Provider-agnostic host bridge          | ✅                     | n/a                                 | ❌ (OpenAI-coupled)            | DIY                     |
| Tenant boundary built-in               | ✅ `forTenant($id)`    | ❌                                   | ❌                             | DIY                     |
| Audit trail with hashes                | ✅ migration shipped   | ❌                                   | ❌                             | DIY (~ADR + migration)  |
| RBAC hook before tool exposure         | ✅ contract            | ❌                                   | ❌                             | DIY (middleware?)       |
| Cached handshake                       | ✅ 5min default        | ❌                                   | ❌                             | DIY                     |
| Stub transport for tests               | ✅ one-line closure    | ❌                                   | partial                        | DIY                     |
| .claude/ vibe-coding pack              | ✅                     | ❌                                   | ❌                             | DIY                     |
| License                                | MIT                    | MIT                                 | MIT                           | n/a                     |

`laravel/mcp` is excellent for **exposing** Laravel as an MCP server —
this pack and `laravel/mcp` are complementary, not competing. Use both
together: `laravel/mcp` to expose your KB as a server, and this pack
to **consume** other MCP servers from inside your chat flow.

---

## Installation

```bash
composer require padosoft/askmydocs-mcp-pack
```

Publish config + migrations (optional — both load automatically):

```bash
php artisan vendor:publish --tag=mcp-pack-config
php artisan vendor:publish --tag=mcp-pack-migrations
php artisan migrate
```

Service provider is **auto-discovered** via `composer.json::extra.laravel.providers`.

---

## Quick start (3 minutes)

### 1. Implement the host bridge

This is the one piece you must write — about 30 lines of glue against
your existing chat provider:

```php
<?php

namespace App\Mcp;

use App\Ai\AiManager;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;

final class MyHostBridge implements McpHostBridgeContract
{
    public function __construct(private readonly AiManager $ai) {}

    public function chat(HostChatTurn $turn): HostChatResponse
    {
        // Translate $turn->tools into your provider's tool-calling shape.
        $providerTools = array_map(
            fn($tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->schema(),
                ],
            ],
            $turn->tools,
        );

        $response = $this->ai->chatWithHistory('', $turn->messages, [
            'tools' => $providerTools,
            'tool_choice' => 'auto',
        ] + $turn->extras);

        return new HostChatResponse(
            content: $response->content,
            toolCalls: $this->normalizeToolCalls($response->toolCalls),
            provider: $response->provider,
            model: $response->model,
        );
    }

    public function supportsToolCalling(): bool
    {
        return in_array($this->ai->provider()->name(), ['openai', 'openrouter'], true);
    }

    private function normalizeToolCalls(?array $raw): array
    {
        return collect($raw ?? [])->map(fn($c) => [
            'id' => $c['id'],
            'name' => $c['function']['name'] ?? $c['name'],
            'arguments' => is_string($c['function']['arguments'] ?? '')
                ? json_decode($c['function']['arguments'], true) ?? []
                : ($c['arguments'] ?? []),
        ])->all();
    }
}
```

### 2. Bind it in `AppServiceProvider`

```php
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;

$this->app->singleton(McpHostBridgeContract::class, App\Mcp\MyHostBridge::class);
```

### 3. Register at least one MCP server

In-memory (config-driven):

```php
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServer;
use Padosoft\AskMyDocsMcpPack\Defaults\InMemoryMcpServerRegistry;

$this->app->singleton(McpServerRegistryContract::class, function () {
    $registry = new InMemoryMcpServerRegistry();

    $registry->add(new InMemoryMcpServer(
        id: 'fs',
        name: 'Filesystem',
        transport: 'stdio',
        tenantId: null, // platform-global
        transportConfig: [
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/data'],
            'timeout_ms' => 10_000,
        ],
        allowedTools: ['read_file', 'list_directory'],
    ));

    return $registry;
});
```

Or back it with your own Eloquent model — see [Recipes](#recipes).

### 4. Drive a chat turn

```php
use Padosoft\AskMyDocsMcpPack\Services\McpToolCallingService;
use Padosoft\AskMyDocsMcpPack\Support\HostMessage;

$svc = app(McpToolCallingService::class);

$response = $svc->chatWithTools(
    messages: [
        HostMessage::system('You are AskMyDocs. Use tools when grounded retrieval helps.'),
        HostMessage::user('What did the deploy runbook change in March?'),
    ],
    tenantId: 'acme',
    actor: auth()->id(),
    context: ['conversation_id' => 42, 'message_id' => 7],
);

return $response->content;
```

Behind the scenes the orchestrator:

1. Looks up enabled servers for tenant `acme`.
2. Handshakes each one (cached for 5 min).
3. Filters tools through your `McpToolAuthorizerContract`.
4. Hands the catalog to your `MyHostBridge::chat()`.
5. If the model asks for a tool: invokes it through `tools/call`,
   appends the result, and loops back.
6. Audits every call into `mcp_tool_call_audit`.

### 5. Sanity-check

```bash
php artisan mcp-pack:ping --tenant=acme
```

```
+-----+------------+-----------+--------+--------+---------+-------+
| id  | name       | transport | tenant | status | #tools  | error |
+-----+------------+-----------+--------+--------+---------+-------+
| fs  | Filesystem | stdio     | acme   | ok     | 11      |       |
+-----+------------+-----------+--------+--------+---------+-------+
```

---

## Architecture

```
┌────────────────────────────────────────────────────────────────────────┐
│  Your controller                                                       │
│  └─► McpToolCallingService::chatWithTools()                            │
│        │                                                               │
│        ├─► McpServerRegistryContract::forTenant($id)  ─── tenant gate  │
│        ├─► McpHandshakeService::refresh()              ─── cached      │
│        ├─► McpToolAuthorizerContract::authorize()      ─── RBAC gate   │
│        │                                                               │
│        ├─► McpHostBridgeContract::chat($turn)          ─── YOUR CODE   │
│        │       (turn = messages + tool catalog + tenant + extras)      │
│        │                                                               │
│        ├─► (loop until model returns no tool_calls or budget hits)     │
│        │                                                               │
│        ├─► ToolInvoker::invoke()                                       │
│        │       └─► McpClient::callTool() ── JSON-RPC tools/call ────┐  │
│        │       └─► McpToolCallAudit::create()  ─── audit row           │
│        │                                                               │
│        └─► returns HostChatResponse(content, toolCalls, usage)         │
└────────────────────────────────────────────────────────────────────────┘
                                                                   │
                                                  ┌────────────────▼────────────────┐
                                                  │  Upstream MCP server            │
                                                  │  (stdio child process OR        │
                                                  │   HTTP gateway)                 │
                                                  └─────────────────────────────────┘
```

Five contracts, three transports, one orchestrator. The blast-radius
of swapping any one of them is bounded by the contract.

---

## Core concepts

### `McpServerContract`

A single MCP endpoint your host can talk to. Carries:

- `id()` — stable identifier scoped per tenant.
- `transport()` — `stdio` or `http`.
- `tenantId()` — `null` = platform-global; a string = scoped to that tenant.
- `transportConfig()` — `{command, args, cwd, env}` for stdio; `{endpoint, headers, timeout_ms}` for http.
- `allowedTools()` — empty array = "all tools the server advertises"; otherwise a per-server allow-list.

Default implementation: `InMemoryMcpServer`. Production: subclass it
on top of your Eloquent model.

### `McpServerRegistryContract`

Per-tenant catalog of `McpServerContract` entries. The orchestrator
always asks `forTenant($id)` — never a global `all()`. Cross-tenant
leakage is structurally impossible.

Default implementation: `InMemoryMcpServerRegistry`. Production: back
it with your own `McpServer` Eloquent model.

### `McpHostBridgeContract`

The 30-line wrapper around your existing chat manager (OpenAI,
Anthropic, OpenRouter, Gemini, …). The pack does NOT bind any AI SDK
— this is what keeps it provider-agnostic.

### `McpToolAuthorizerContract`

RBAC gate. Called BEFORE the tool appears in the model's catalog, so
denied tools never even reach the prompt token budget.

Default implementation: `NullMcpToolAuthorizer` (allows everything —
fine for prototypes, MUST be replaced in production).

### `McpToolContract`

The unit of work. Most consumers don't implement this directly —
`RemoteMcpTool` is built from the upstream server's `tools/list`
response and used by the orchestrator. You implement it only if you
need to expose an **in-process** tool with no upstream MCP server
(uncommon).

---

## Configuration reference

`config/mcp-pack.php`:

| Key                                      | Env var                                | Default | Purpose                                          |
| ---------------------------------------- | -------------------------------------- | ------- | ------------------------------------------------ |
| `tool_calling.enabled`                   | `MCP_PACK_TOOL_CALLING_ENABLED`        | `false` | Master kill-switch.                              |
| `tool_calling.max_iterations`            | `MCP_PACK_TOOL_CALLING_MAX_ITERATIONS` | `3`     | Hard cap on tool-calling loops per chat turn.    |
| `tool_calling.default_tool_choice`       | `MCP_PACK_TOOL_CHOICE`                 | `auto`  | OpenAI-style hint passed to the bridge.          |
| `handshake.ttl_seconds`                  | `MCP_PACK_HANDSHAKE_TTL`               | `300`   | How long to cache `initialize` + `tools/list`.   |
| `audit_model`                            | `MCP_PACK_AUDIT_MODEL`                 | `McpToolCallAudit::class` | Override to subclass the audit model. |

---

## Recipes

### Recipe 1 — back the registry with an Eloquent model

```php
final class EloquentMcpServerRegistry implements McpServerRegistryContract
{
    public function forTenant(?string $tenantId): array
    {
        return McpServer::query()
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->get()
            ->map(fn($m) => new InMemoryMcpServer(
                id: (string) $m->id,
                name: $m->name,
                transport: $m->transport,
                tenantId: $m->tenant_id,
                transportConfig: $m->transport_config ?? [],
                allowedTools: $m->allowed_tools ?? [],
            ))
            ->all();
    }

    public function find(string $id): ?McpServerContract
    {
        $m = McpServer::query()->where('id', $id)->where('enabled', true)->first();
        return $m === null ? null : new InMemoryMcpServer(/* same wrap as above */);
    }
}

$this->app->singleton(McpServerRegistryContract::class, EloquentMcpServerRegistry::class);
```

### Recipe 2 — Spatie-permission-backed authorizer

```php
final class SpatieMcpToolAuthorizer implements McpToolAuthorizerContract
{
    public function authorize(mixed $actor, ?string $tenantId, McpToolContract $tool): bool
    {
        if (! $actor instanceof User) { return false; }
        if (! $actor->hasAnyRole(['admin', 'super-admin'])) { return false; }

        $permission = $tool->isReadOnly() ? "mcp.{$tool->name()}.read" : "mcp.{$tool->name()}.write";

        return $actor->hasPermissionTo($permission);
    }
}
```

### Recipe 3 — Claude Desktop / Cursor MCP server over stdio

```php
new InMemoryMcpServer(
    id: 'github',
    name: 'GitHub MCP',
    transport: 'stdio',
    tenantId: 'acme',
    transportConfig: [
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-github'],
        'env' => ['GITHUB_PERSONAL_ACCESS_TOKEN' => env('GH_PAT')],
        'timeout_ms' => 15_000,
    ],
    allowedTools: ['search_repositories', 'get_file_contents'],
);
```

### Recipe 4 — remote MCP gateway over HTTPS

```php
new InMemoryMcpServer(
    id: 'cloud-kb',
    name: 'Cloud KB Gateway',
    transport: 'http',
    tenantId: 'acme',
    transportConfig: [
        'endpoint' => 'https://mcp.example.com/rpc',
        'headers' => ['Authorization' => 'Bearer ' . env('MCP_TOKEN')],
        'timeout_ms' => 5_000,
        'health_path' => '/healthz',
    ],
);
```

### Recipe 5 — coexist with a host-owned audit table

If your host already owns a `mcp_tool_call_audit` table that pre-dates
this pack, the package migration is a no-op
(`Schema::hasTable('mcp_tool_call_audit')` guards both `up()` and
`down()`). To keep the host's operator-forensics columns (raw redacted
payload, user-FK, error blob, …) AND satisfy the package contract,
ship ONE additive host migration and one model subclass:

```php
// database/migrations/...add_input_hash_and_actor_to_mcp_tool_call_audit.php
Schema::table('mcp_tool_call_audit', function (Blueprint $table) {
    $table->char('input_hash', 64)->nullable()->after('input_json_redacted');
    $table->string('actor', 100)->nullable()->after('user_id');
    // (also relax any NOT NULL host columns the package does not write)
});

// Backfill existing rows so SHA-256 lookups match pre- and post-pack:
DB::table('mcp_tool_call_audit')
    ->whereNull('input_hash')
    ->orderBy('id')
    ->chunkById(500, function ($rows) {
        foreach ($rows as $row) {
            $payload = is_array($row->input_json_redacted)
                ? json_encode($row->input_json_redacted, JSON_UNESCAPED_UNICODE)
                : $row->input_json_redacted;
            DB::table('mcp_tool_call_audit')
                ->where('id', $row->id)
                ->update(['input_hash' => hash('sha256', (string) $payload)]);
        }
    });
```

```php
// app/Models/McpToolCallAudit.php — subclass + bridging hook
class McpToolCallAudit extends \Padosoft\AskMyDocsMcpPack\Models\McpToolCallAudit
{
    protected $table = 'mcp_tool_call_audit';

    protected $fillable = [
        // package contract
        'tenant_id', 'actor', 'mcp_server_id', 'tool_name',
        'input_hash', 'result_hash', 'duration_ms', 'status', 'error_excerpt',
        // host-legacy columns kept for admin SPA
        'user_id', 'input_json_redacted', 'error_json',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            // Bridge actor↔user_id so legacy joins still work.
            if ($row->user_id === null && is_string($row->actor) && ctype_digit($row->actor)) {
                $row->user_id = (int) $row->actor;
            }
            if (($row->actor === null || $row->actor === '') && $row->user_id !== null) {
                $row->actor = (string) $row->user_id;
            }
        });
    }
}
```

```php
// config/mcp-pack.php — point the package at the host subclass
return ['audit_model' => \App\Models\McpToolCallAudit::class];
```

Now every package `ToolInvoker::audit()` row fills BOTH schemas; legacy
host writes continue to work; the host's existing admin UI and
operator-forensics queries keep rendering the same way they always did.

---

## Extension points

| Hook                                | Default                             | Override when…                                              |
| ----------------------------------- | ----------------------------------- | ----------------------------------------------------------- |
| `McpHostBridgeContract`             | `NullMcpHostBridge` (throws)        | Always — wire your provider stack.                          |
| `McpServerRegistryContract`         | `InMemoryMcpServerRegistry`         | You want DB-backed admin UI for server CRUD.                |
| `McpToolAuthorizerContract`         | `NullMcpToolAuthorizer` (allow-all) | Always in production — wire RBAC + tenant policy.           |
| `McpToolCallingService`             | Bound via SP                        | Subclass for custom logging / retry / circuit-breaker logic.|
| `McpHandshakeService`               | Bound via SP                        | Subclass to persist handshakes in a DB column.              |
| `McpToolCallAudit`                  | Built-in model                      | Subclass + override `mcp-pack.audit_model` config.          |
| `McpClient::useTransportResolver()` | `null` (uses transport from server) | In tests — swap to a stub transport.                        |

---

## Testing

The pack ships its own PHPUnit + Orchestra Testbench setup. To run
its tests:

```bash
composer install
vendor/bin/phpunit
```

To **test your own host** using the pack's stubs:

```php
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Tests\Support\StubMcpTransport;

$transport = (new StubMcpTransport())
    ->scriptInitialize()
    ->scriptListTools([['name' => 'kb_search', 'description' => '...', 'inputSchema' => []]])
    ->scriptToolCall('kb_search', ['hits' => [['title' => 'Doc A']]]);

McpClient::useTransportResolver(fn() => $transport);

// drive your chat flow — every JSON-RPC call hits the stub.
```

End-to-end Playwright coverage in **AskMyDocs** exercises:

- chat UI with MCP tools enabled → tool-call summary card renders
- admin SPA `/admin/mcp-servers` → server CRUD + `ping` action
- audit log shows tool calls with status, duration, server name

---

## Compatibility matrix

| PHP   | Laravel | Status              |
| ----- | ------- | ------------------- |
| 8.3   | 11.x    | ✅ tested in CI     |
| 8.3   | 12.x    | ✅ tested in CI     |
| 8.3   | 13.x    | ✅ tested in CI     |
| 8.4   | 11.x    | ✅ tested in CI     |
| 8.4   | 12.x    | ✅ tested in CI     |
| 8.4   | 13.x    | ✅ tested in CI     |
| 8.5   | 13.x    | ✅ tested in CI     |

---

## Roadmap

| Version | Status                       | Highlights                                                       |
| ------- | ---------------------------- | ---------------------------------------------------------------- |
| v1.0.0  | ✅ shipped 2026-05-15        | Contracts + orchestrator + stdio/http transports + audit + ping. |
| v1.0.1  | ✅ shipped 2026-05-15        | Defensive `up()`/`down()` guards on the audit-table migration so the package coexists with a host-owned `mcp_tool_call_audit`. Recipe 5 walks the coexistence pattern. |
| v1.1    | ⏳ next                      | `SseJsonRpcTransport` for remote HTTP+SSE gateways; JSON-RPC `resources/list` + `resources/read`; JSON-RPC `prompts/list` + `prompts/get`. |
| v1.2    | ⏳ planned                   | First-class **server-side** — same package exposes a Laravel app AS an MCP server (stdio long-lived runner + HTTP+SSE route + JSON-RPC handler routing `initialize` / `tools/list` / `tools/call` to host-supplied tool catalog + auth + RBAC). |
| v1.3    | ⏳ planned                   | Per-tool circuit breaker (`open` / `half-open` / `closed` with TTL recovery) + adaptive retry budget (token-bucket per (tenant, server) with exponential backoff) + telemetry events. |
| v1.4    | ⏳ planned                   | **Admin backend surface** — REST routes registered by the package SP at `/api/admin/mcp-pack/*` (configurable prefix): servers CRUD + handshake + tools list + paginated audit + circuit-breaker state. Middleware-driven auth (host wires Sanctum + RBAC). OpenAPI 3.1 spec + Postman collection. **NO React/Vue code** — this is the backend the separate `padosoft/askmydocs-mcp-pack-admin` SPA consumes. |
| ─       | ─                           | ─ |
| post-v7.0 cycle | 📅 separate package | **`padosoft/askmydocs-mcp-pack-admin`** — standalone React SPA companion. Same pattern as `padosoft/laravel-flow-admin` / `padosoft/laravel-pii-redactor-admin`. Cross-mountable under `/admin/mcp/` in any Laravel host that depends on this package + v1.4. Ships in its own repo with its own R36 cycle once AskMyDocs's v7.0/W6 host integration is green. |

The v1.1 → v1.4 cycle ships **before** the AskMyDocs host adopts the
package. Consumers willing to ride v1.0 today are welcome to do so —
the public API surface is stable and won't break before v2 — but
AskMyDocs's own host integration is intentionally deferred to land
over the complete v1.4 feature set (orchestrator + transports +
server-side + circuit breaker + admin REST routes) in a single
integration cycle rather than four partial passes. See
[lopadova/AskMyDocs roadmap](https://github.com/lopadova/AskMyDocs#roadmap)
for the host-side milestones (v7.0/W2 → W6).

---

## Changelog

### v1.0.0 — *planned*

- Initial release extracted from AskMyDocs v6.x.
- `McpToolContract`, `McpServerContract`, `McpServerRegistryContract`,
  `McpToolAuthorizerContract`, `McpHostBridgeContract`.
- `HttpJsonRpcTransport` + `StdioJsonRpcTransport`.
- `McpToolCallingService` multi-turn loop with budget cap.
- `McpHandshakeService` with cached handshakes.
- `ToolInvoker` with SHA-256 audit trail.
- `mcp_tool_call_audit` migration.
- `mcp-pack:ping` Artisan diagnostic.
- `NullMcpHostBridge` + `NullMcpToolAuthorizer` + `InMemoryMcpServerRegistry` defaults.

---

## License

MIT © Padosoft. See [LICENSE](LICENSE).
