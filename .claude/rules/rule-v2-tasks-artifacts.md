# Rule: v2 Tasks and artifacts are tenant/principal scoped

- Never query `mcp_tasks` or `mcp_artifacts` by UUID alone on a user path. Match tenant and actor/principal again on every operation.
- Create the task row before queue dispatch. Preserve the atomic status graph and lock version checks.
- Encrypt task request, input, result and error payloads. `requestState` is authenticated, expiry-bound and single-use when configured.
- Artifact paths are generated server-side, immutable and private. Never use the submitted filename as a storage path.
- Verify artifact SHA-256 on read, reject executable MIME/content, emit attachments with `nosniff`, and keep signed URLs short-lived.
- Pruning and recovery commands must remain idempotent and safe to schedule concurrently.
