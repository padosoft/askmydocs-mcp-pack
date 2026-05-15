<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

/**
 * A server-supplied prompt template — the second half of the MCP
 * spec's "context surfaces" (resources read existing content,
 * prompts render parameterised templates the model can adopt as
 * starting points).
 *
 * The orchestrator surfaces prompts to the host bridge through the
 * tool catalog the same way it surfaces tools: the host decides
 * whether to prepend a rendered prompt to the conversation, whether
 * to expose the template name to the user as a slash-command, etc.
 *
 * The package stays neutral on UX — it only guarantees the wire
 * shape matches the spec's `prompts/list` + `prompts/get`.
 */
interface McpPromptContract
{
    /**
     * Stable prompt name. Convention: lower-snake-case, namespaced
     * by domain (`kb_explain_failure`, `audit_summary`).
     */
    public function name(): string;

    /**
     * Human description shown to the model + admin SPA. Keep it
     * short and concrete so the model can decide WHEN to apply the
     * template.
     */
    public function description(): string;

    /**
     * JSON Schema (draft-07 compatible) for the template's
     * arguments — same shape as `McpToolContract::schema()`.
     *
     * @return array<string,mixed>
     */
    public function arguments(): array;

    /**
     * Render the prompt with the supplied arguments. The return
     * value MUST match the MCP `prompts/get` result shape:
     *
     *   {
     *     description?: string,
     *     messages: [
     *       { role: 'user'|'assistant'|'system', content: {type:'text', text:string} | {type:'image', ...} },
     *       ...
     *     ]
     *   }
     *
     * Implementations MAY return a flat list of messages — the
     * orchestrator wraps it in the expected envelope.
     *
     * @param  array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function render(array $arguments): array;
}
