# MCP 2026-07-28 protocol and deployment

Every JSON-RPC request requires:

```json
{
  "_meta": {
    "io.modelcontextprotocol/protocolVersion": "2026-07-28",
    "io.modelcontextprotocol/clientCapabilities": {}
  }
}
```

Every result contains `resultType` (`complete`, `input_required`, or `task`) and `serverInfo`. List/read/discovery results also expose `ttlMs` and `cacheScope`. Private catalog cache keys include server, tenant and principal. Cursor values are authenticated and tied to the snapshot digest; a catalog change invalidates old cursors.

HTTP clients send `MCP-Protocol-Version` and `Mcp-Method`, plus `Mcp-Name` for tool calls and prompt reads. A JSON Schema property marked `"x-mcp-header": true` may be filled by its derived `Mcp-Param-<Property>` header; a string value may specify an exact header name. Header/body disagreement returns `-32020`. Missing capabilities return `-32021`; unsupported protocol versions return `-32022`.

Set `Accept: text/event-stream` for a streamed response. `subscriptions/listen` then remains open for the configured bounded interval, publishes notification frames and keepalives, and resumes from `after`. Handlers call `$request->progress()` and periodically check `$request->isCancelled()` for cooperative cancellation.

Production requirements:

- Use multiple stateless HTTP workers behind a normal load balancer; no sticky session is required.
- Back cache/subscriptions/cancellation with a shared cache store, not per-worker array cache.
- Send trace context only through `traceparent`, `tracestate` and `baggage`; the package validates format/length and does not write those values to audit logs.
- Run `mcp-pack:tasks:recover`, `mcp-pack:tasks:prune` and `mcp-pack:artifacts:prune` from Laravel Scheduler.
- Never expose the legacy HTTP+SSE client transport as the v2 server route.

Protocol fixtures are pinned in `mcp-pack.v2.spec_pins`: core `594754559cc928eae08e184c74a89508c1235fc2`, Apps `10195ad91851502134930e9b80ec2c04e277a720`, Tasks `2c1425d9a288b9b1f489430fe1e00bb392b47e48`.

CI contains two complementary conformance checks. The positive test starts the real stdio server process and sends fully formed `2026-07-28` discovery/list/call requests. MCP Inspector `2.2.0` is also pinned as a transport compatibility probe; that Inspector release still emits list requests without the mandatory new `_meta`, so CI asserts the server returns the explicit `-32022` diagnostic rather than treating that legacy-shaped probe as positive v2 conformance.
