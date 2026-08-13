# Rule: package contracts are part of the public API

The five contracts under `src/Contracts/` are **load-bearing public
API**. Renaming a method, changing a signature, or weakening a return
type is a **major version bump**:

- `McpToolContract`
- `McpServerContract`
- `McpServerRegistryContract`
- `McpToolAuthorizerContract`
- `McpHostBridgeContract`
- `McpTransportContract`

Version 2 adds contracts under `src/Contracts/V2/`. They are equally public. The legacy contracts remain deprecated adapters throughout v2 and may not be removed before v3.

## Allowed changes inside a minor bump

- Adding new methods with a default trait that bridges old impls
  (i.e. `interface` + `trait Compat` pattern).
- Tightening return types into a subtype.
- Adding optional constructor parameters to `*Contract` implementations
  as long as they have sensible defaults.

## Forbidden changes inside a minor bump

- Removing a method.
- Changing parameter types in a way that breaks variance.
- Widening a return type so existing callers may receive `null` they
  didn't expect.
- Throwing a new exception class from a method that previously returned
  `false`.

When in doubt, treat the change as major and cut a new branch.

## MCP v2 rules

- The v2 server speaks exactly `2026-07-28`; never restore `initialize` or sessions on the v2 entrypoints.
- Every v2 request validates namespaced protocol metadata; every result includes `resultType` and `serverInfo`.
- Fluent builders are mutable configuration objects only until `compile()`; catalog definitions are immutable.
- Async handlers must be serializable class strings. Closures remain synchronous only.
- Apps use `ui://` and `text/html;profile=mcp-app`. Artifacts are package abstractions exposed through stable resource content/link types.
- Draft features stay behind explicit flags and retain their pinned specification SHA.
