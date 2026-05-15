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
     * Prompt-argument descriptors per the MCP spec for `prompts/list`:
     * a list of `{name, description?, required?}` entries, NOT a
     * JSON Schema object. The spec is intentionally simpler than
     * the tool input schema — clients use these only to render
     * argument forms, not to validate types.
     *
     * Example:
     *   [
     *     ['name' => 'doc_id',  'description' => 'KB doc id',  'required' => true],
     *     ['name' => 'tone',    'description' => 'casual / formal', 'required' => false],
     *   ]
     *
     * @return array<int,array{name:string,description?:string,required?:bool}>
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
     * orchestrator wraps it in the expected `{messages:[…]}` envelope.
     *
     * @param  array<string,mixed>                                     $arguments
     * @return array<string,mixed>|array<int,array<string,mixed>>
     */
    public function render(array $arguments): array;
}
