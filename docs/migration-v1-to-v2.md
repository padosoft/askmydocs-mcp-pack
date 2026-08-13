# Migration from v1 to v2

## Compatibility boundary

The v2 server speaks only MCP `2026-07-28`. It does not accept `initialize`, `notifications/initialized`, `Mcp-Session-Id`, or the deprecated server-side roots/sampling/logging methods. The v1 `JsonRpcRequestHandler`, host bridge, orchestrator and `/api/admin/mcp-pack` routes remain available as deprecated adapters through the v2 lifecycle.

The client remains dual-era: it sends `server/discover` first and falls back only when the modern method/version is explicitly unsupported. It starts legacy negotiation at `2025-11-25` and accepts supported revisions back through `2024-10-07`; network, TLS and authentication failures never trigger a protocol downgrade.

## Migration sequence

1. Update the package and publish the new config and migrations.
2. Run migrations before enabling Tasks or artifacts.
3. Re-register server primitives with `Mcp::server()` and the Fluent builders.
4. Move closure-based async tools to class-string handlers and add `->asTask()`.
5. Replace raw tool response arrays with `McpResult` when returning structured content, MRTR, tasks, resources or artifacts.
6. Move the HTTP route to the v2 controller by keeping `mcp-pack.server_side.http.enabled=true`, or register an explicit route with `->web()`.
7. Configure auth middleware to set trusted `mcp_pack.tenant_id` and `mcp_pack.principal_id` request attributes. Never derive tenant identity from client-controlled MCP headers.
8. Enable `MCP_PACK_TASKS_ENABLED` only after the table and queue connection are operational.
9. Enable the independent admin v2 surface with `MCP_PACK_ADMIN_V2_ENABLED=true`. Keep the v1 surface on until all companion clients have migrated.

## Public mapping

| v1 | v2 |
| --- | --- |
| `McpServerExposureContract` | `Mcp::server()` plus Fluent definitions |
| `initialize` handshake | `server/discover` on every stateless connection |
| `McpHandshakeService` | discovery-first `McpClient::negotiate()`; old class remains deprecated |
| raw tool arrays | `McpResult` factories and lossless `McpToolResult` client view |
| single-shot stdio | persistent stdio process |
| HTTP JSON-RPC without routing headers | stateless Streamable HTTP with validated MCP headers |

Dynamic changes go through `$manager->require($server)->forTenant($tenant, $principal)->upsert()` or `remove()`. They increment the scoped revision, invalidate its cache, publish `notifications/*/list_changed`, and never alter definitions already compiled for another tenant.
