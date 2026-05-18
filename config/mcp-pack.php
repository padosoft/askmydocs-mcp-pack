<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Multi-turn tool-calling
    |--------------------------------------------------------------------------
    |
    | The orchestrator drives at most `max_iterations` model turns
    | before forcing a final answer with no tool budget. 3 is the
    | sweet spot for grounded QA — raise to 5+ only for agentic flows
    | that genuinely need deep chains.
    |
    */
    'tool_calling' => [
        'enabled' => env('MCP_PACK_TOOL_CALLING_ENABLED', false),
        'max_iterations' => env('MCP_PACK_TOOL_CALLING_MAX_ITERATIONS', 3),
        'default_tool_choice' => env('MCP_PACK_TOOL_CHOICE', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Handshake cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | The result of `initialize` + `tools/list` is cached per (tenant,
    | server) for this many seconds. Set to 0 to disable caching
    | entirely — useful in tests and when servers rotate their
    | capabilities aggressively.
    |
    */
    'handshake' => [
        'ttl_seconds' => env('MCP_PACK_HANDSHAKE_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit model
    |--------------------------------------------------------------------------
    |
    | FQCN of the Eloquent model the orchestrator writes audit rows
    | into. Override to subclass and add per-host columns.
    |
    */
    'audit_model' => env(
        'MCP_PACK_AUDIT_MODEL',
        \Padosoft\AskMyDocsMcpPack\Models\McpToolCallAudit::class,
    ),

    /*
    |--------------------------------------------------------------------------
    | v1.2.0 — Server-side surface
    |--------------------------------------------------------------------------
    |
    | The same package can expose THIS Laravel app AS an MCP server so
    | remote clients (Claude Desktop, Cursor, VS Code, …) can drive
    | `initialize` / `tools/list` / `tools/call` / `resources/*` /
    | `prompts/*` against it. Two front-doors:
    |
    |   - stdio: `php artisan mcp-pack:serve` — long-lived process
    |     wired by the MCP client via its `command` + `args` config.
    |     Trust boundary is the host's filesystem.
    |
    |   - HTTP: POST to the route registered under `http.prefix`. Host
    |     wires Sanctum / RBAC / per-tenant middleware via the
    |     `http.middleware` array. Disabled by default — opt in once
    |     the auth stack is correct.
    |
    | The host MUST bind `McpServerExposureContract` to publish its
    | own tool / resource / prompt catalog; the package ships a
    | `NullMcpServerExposure` default that publishes nothing.
    |
    */
    'server_side' => [
        'http' => [
            'enabled' => env('MCP_PACK_SERVER_HTTP_ENABLED', false),
            'prefix' => env('MCP_PACK_SERVER_HTTP_PREFIX', 'mcp'),
            'middleware' => array_values(array_filter(
                array_map('trim', explode(',', (string) env('MCP_PACK_SERVER_HTTP_MIDDLEWARE', 'api'))),
            )),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | v1.3.0 — Resilience (circuit breaker + adaptive retry)
    |--------------------------------------------------------------------------
    |
    | Opt-in resilience layer wrapped around every upstream tool call.
    | Both knobs are independent — enable the breaker without retries
    | for hard fail-fast, or enable retries without the breaker for
    | naïve retry-on-failure. The cache_store name (when set) routes
    | breaker + budget state through a dedicated cache driver so the
    | default application cache cannot evict it under memory pressure.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | v1.4.0 — Admin REST backend
    |--------------------------------------------------------------------------
    |
    | Registers a small read-mostly REST surface under the configured
    | prefix (default `api/admin/mcp-pack`):
    |
    |   GET  servers
    |   GET  servers/{id}
    |   POST servers/{id}/handshake
    |   GET  servers/{id}/tools
    |   GET  audit
    |   GET  circuit-breaker
    |
    | These routes are the backend the separate
    | `padosoft/askmydocs-mcp-pack-admin` SPA consumes — the package
    | itself ships NO frontend. Auth is host-driven: declare the
    | middleware stack here (Sanctum + RBAC + role gate) and the
    | controllers stay free of authorisation logic.
    |
    | Disabled by default — opt in once the auth stack is correct.
    |
    */
    'admin' => [
        'enabled' => env('MCP_PACK_ADMIN_ENABLED', false),
        'prefix' => env('MCP_PACK_ADMIN_PREFIX', 'api/admin/mcp-pack'),
        'middleware' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('MCP_PACK_ADMIN_MIDDLEWARE', 'api'))),
        )),

        /*
        | v1.5.0 — per-feature flags. Each defaults to `true` when
        | `admin.enabled=true`; operators flip a single flag to `false`
        | to hide a section from the SPA without forking the package.
        |
        | Behaviour when a flag is `false`: the route is STILL
        | registered (unconditional registration is intentional) and
        | the controller answers HTTP 403 `feature_disabled` via
        | `ResolvesAdminContext::featureGate()`. This way the SPA can
        | distinguish "the operator turned this section off" (403)
        | from "this package version does not implement the section"
        | (404 — never reached because routes are registered) — and
        | hot-flipping the flag at runtime works without rebooting
        | the workers.
        |
        |   - `me`             — `GET /me`, `POST /me/preferences`
        |   - `tenants`        — `GET /tenants`
        |   - `api_keys`       — `GET/POST/DELETE /api-keys`
        |   - `servers_write`  — `POST/PATCH/DELETE /servers/...`
        |                        (v1.5.0 W1.B — gates the CRUD writes;
        |                        the read paths under `/servers` stay
        |                        always-on)
        |   - `tools`          — `GET /tools` (v1.5.0 W1.B — flat
        |                        aggregator across every visible
        |                        server)
        |   - `tool_invoke`    — `POST /servers/{id}/tools/{name}/invoke`
        |                        (v1.5.0 W1.C — admin-side tool
        |                        invocation; honours destructive-tool
        |                        confirm guard)
        |   - `audit_show`     — `GET /audit/{id}` (v1.5.0 W1.C — rich
        |                        drilldown via host bridge)
        |   - `audit_replay`   — `POST /audit/{id}/replay` (v1.5.0
        |                        W1.C — two-call confirm-token
        |                        protocol, R21 atomic)
        |   - `breaker_reset`  — `POST /circuit-breaker/{key}/reset`
        |                        (v1.5.0 W1.C — two-call confirm-token
        |                        protocol, R21 atomic)
        |
        | W1.D adds more keys (resources, prompts, events, openapi).
        */
        'features' => [
            'me' => env('MCP_PACK_ADMIN_FEATURE_ME', true),
            'tenants' => env('MCP_PACK_ADMIN_FEATURE_TENANTS', true),
            'api_keys' => env('MCP_PACK_ADMIN_FEATURE_API_KEYS', true),
            'servers_write' => env('MCP_PACK_ADMIN_FEATURE_SERVERS_WRITE', true),
            'tools' => env('MCP_PACK_ADMIN_FEATURE_TOOLS', true),
            'tool_invoke' => env('MCP_PACK_ADMIN_FEATURE_TOOL_INVOKE', true),
            'audit_show' => env('MCP_PACK_ADMIN_FEATURE_AUDIT_SHOW', true),
            'audit_replay' => env('MCP_PACK_ADMIN_FEATURE_AUDIT_REPLAY', true),
            'breaker_reset' => env('MCP_PACK_ADMIN_FEATURE_BREAKER_RESET', true),

            /*
            | v1.5.0 W1.D — Resources / Prompts / SSE / OpenAPI.
            | All four gate-flags default to `true`; flipping to
            | `false` returns HTTP 403 `feature_disabled` so the SPA
            | hides the section without the route disappearing.
            */
            'resources' => env('MCP_PACK_ADMIN_FEATURE_RESOURCES', true),
            'prompts' => env('MCP_PACK_ADMIN_FEATURE_PROMPTS', true),
            'events_sse' => env('MCP_PACK_ADMIN_FEATURE_EVENTS_SSE', true),
            'openapi' => env('MCP_PACK_ADMIN_FEATURE_OPENAPI', true),
        ],

        /*
        | v1.5.0 W1.D — Server-Sent-Events stream knobs. The
        | controller polls `recentAudit()` at `poll_ms` cadence and
        | caps each connection at `max_seconds` so a hung client
        | cannot starve PHP-FPM workers forever. Defaults: 1s poll,
        | 5min connection lifetime.
        */
        'sse' => [
            'poll_ms' => (int) env('MCP_PACK_ADMIN_SSE_POLL_MS', 1000),
            'max_seconds' => (int) env('MCP_PACK_ADMIN_SSE_MAX_SECONDS', 300),
        ],
    ],

    'resilience' => [
        'circuit_breaker' => [
            'enabled' => env('MCP_PACK_CB_ENABLED', false),
            'failure_threshold' => (int) env('MCP_PACK_CB_FAILURE_THRESHOLD', 5),
            'recovery_seconds' => (int) env('MCP_PACK_CB_RECOVERY_SECONDS', 30),
        ],
        'retry' => [
            'enabled' => env('MCP_PACK_RETRY_ENABLED', false),
            'max_attempts' => (int) env('MCP_PACK_RETRY_MAX_ATTEMPTS', 3),
            'bucket_size' => (int) env('MCP_PACK_RETRY_BUCKET_SIZE', 20),
            'bucket_window_seconds' => (int) env('MCP_PACK_RETRY_BUCKET_WINDOW_SECONDS', 60),
            'base_backoff_ms' => (int) env('MCP_PACK_RETRY_BASE_BACKOFF_MS', 200),
            'max_backoff_ms' => (int) env('MCP_PACK_RETRY_MAX_BACKOFF_MS', 5000),
        ],
        // Optional dedicated cache store name (config/cache.php). When
        // null the default app cache is used.
        'cache_store' => env('MCP_PACK_RESILIENCE_CACHE_STORE'),
    ],

];
