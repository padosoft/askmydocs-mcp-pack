# Rule: audit writes never block the user path

`ToolInvoker::audit()` swallows every exception. This is intentional:
an audit row dropped to disk failure (e.g. UNIQUE violation, write
timeout, missing column after a botched migration) MUST NOT crash the
chat turn the user is waiting on.

If you change the audit shape:

- Add new columns as nullable in the migration.
- Update `audit()` to forceFill only known keys.
- Never re-raise the swallow inside `audit()`.
- Surface failure through OpenTelemetry / a counter — not by throwing.

Mirrors AskMyDocs's `ChatLogManager::log()` discipline.
