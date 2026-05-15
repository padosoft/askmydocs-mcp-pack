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
