# Changelog

All notable changes to `padosoft/askmydocs-mcp-pack` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.1] — 2026-05-15

### Fixed

- The `create_mcp_tool_call_audit_table` migration now guards on
  `Schema::hasTable('mcp_tool_call_audit')`. When a host already
  manages its own audit table (e.g. AskMyDocs v5.0+, which shipped a
  v5.0 audit migration before extracting this pack), running
  `php artisan migrate` after `composer require` previously failed
  with "table already exists". The guard makes the migration a no-op
  in that scenario; hosts ALTER their existing table to add the
  `input_hash` + `actor` columns the package writes and point
  `mcp-pack.audit_model` at a subclass that satisfies both schemas.


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
