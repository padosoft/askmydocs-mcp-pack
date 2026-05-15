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

];
