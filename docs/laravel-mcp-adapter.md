# Optional laravel/mcp adapter

The autonomous core is the default and has no `laravel/mcp` dependency. `LaravelMcpAdapter` resolves first-party classes at runtime and refuses to start unless the installed package reports `ProtocolVersion::LATEST === 2026-07-28`.

Enable the compatibility check with `MCP_PACK_LARAVEL_MCP_ADAPTER=true`. Use `web()`/`local()` to register an explicitly authored first-party `Laravel\Mcp\Server` subclass, or export Fluent server/primitive/result arrays for a focused host adapter. Older tagged versions fail with a diagnostic exception instead of silently running a legacy protocol.

This boundary is intentional: first-party `laravel/mcp` can own its transport and primitive classes, while AskMyDocs MCP Pack continues to own tenant-scoped catalogs, orchestration, Tasks, artifacts, audit and client negotiation.
