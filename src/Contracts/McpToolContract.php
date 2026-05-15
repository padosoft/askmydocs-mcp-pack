<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

/**
 * A single MCP tool — the unit of work a model can invoke.
 *
 * Each tool publishes (a) a stable identifier, (b) a human description,
 * and (c) a JSON Schema for its arguments. The orchestrator
 * ({@see McpToolCallingService}) feeds (b) + (c) into the model's
 * tool catalog at request time, and dispatches `invoke($arguments)`
 * when the model returns a tool_call referencing (a).
 *
 * Tools SHOULD be deterministic per `(name, arguments)` pair when
 * `isIdempotent()` returns true. The v1.0 orchestrator does not
 * coalesce duplicate calls within a single multi-turn session — the
 * flag is informational, exposed to authorizers and host bridges that
 * want to make smarter prompting decisions. Automatic de-duplication
 * lands in v1.1 (see Roadmap in the README).
 */
interface McpToolContract
{
    /**
     * Stable tool identifier. Convention: lower-snake-case, namespaced
     * by domain (`kb_search`, `kb_document_by_slug`). The model sees
     * this string verbatim in its tool catalog.
     */
    public function name(): string;

    /**
     * Human description shown to the model. Keep it short and concrete
     * — the model uses it to decide WHEN to call the tool.
     */
    public function description(): string;

    /**
     * JSON Schema (draft-07 compatible) for the tool's arguments.
     * Returned as a plain PHP array, serialised to JSON by the
     * orchestrator at catalog-build time.
     *
     * @return array<string,mixed>
     */
    public function schema(): array;

    /**
     * Whether two calls with identical arguments return the same
     * result. Read-only retrieval tools should return true; tools
     * that mutate state MUST return false.
     *
     * The v1.0 orchestrator does not act on this flag automatically;
     * authorizers and host bridges may consult it to inform prompting
     * decisions.
     */
    public function isIdempotent(): bool;

    /**
     * Whether the tool reads but does NOT write any state. Used by
     * authorizers that gate writes behind a stricter permission.
     */
    public function isReadOnly(): bool;

    /**
     * Execute the tool with the model-supplied arguments. The return
     * value MUST be JSON-serialisable; the orchestrator wraps it in
     * a `ToolCallResult` before feeding it back into the next model
     * turn.
     *
     * @param  array<string,mixed>  $arguments
     */
    public function invoke(array $arguments): mixed;
}
