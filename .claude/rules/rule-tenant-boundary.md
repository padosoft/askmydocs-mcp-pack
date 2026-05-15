# Rule: tenant boundary is structural, not advisory

`McpServerRegistryContract::forTenant($id)` is the ONLY API the
orchestrator uses to enumerate servers. There is no `all()` /
`global()` shortcut, by design.

When you add features that touch the registry:

- They MUST take `?string $tenantId` as a parameter.
- `null` means "platform-global" — visible to every tenant.
- A non-null id means "scoped to that tenant" — invisible to others.
- A server with `tenantId() === 'acme'` MUST NEVER appear in
  `forTenant('globex')` results.

Test posture: when you add a new orchestrator branch, add a feature
test that seeds two tenants with overlapping server ids and asserts
isolation.
