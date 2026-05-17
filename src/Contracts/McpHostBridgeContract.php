<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;

/**
 * The bridge between the package's orchestrator and the host's AI
 * provider stack.
 *
 * The pack is provider-agnostic by design — it does NOT bind
 * `openai`, `openrouter`, `anthropic`, or `gemini` SDKs. The host
 * implements this contract once (typically a 30-line wrapper around
 * the host's existing chat manager) and the orchestrator drives the
 * multi-turn tool-calling loop through it.
 *
 * Idempotency hint: `chat()` SHOULD be deterministic when the model
 * temperature is 0 and the host's caller passes `seed` through the
 * extras map. The v1.0 orchestrator does not coalesce duplicate
 * tool-call payloads automatically — the hint is for hosts that want
 * to layer their own dedupe / cache in front of the bridge.
 *
 * ## v1.5.0 — admin REST extension via sub-interfaces
 *
 * The v1.5 admin REST surface needs identity (`/me`, `/tenants`,
 * `/api-keys`) and (in W1.C) audit-replay + breaker-reset hooks. To
 * stay backwards-compatible with v1.0..v1.4 host bridges, those
 * methods live in {@see McpHostBridgeIdentityContract} which extends
 * this contract. Hosts opt in by implementing the sub-interface (or
 * by `use`-ing {@see Concerns\HasIdentitySurface} for safe defaults).
 *
 * Adding methods to a published interface is BC-breaking in PHP, so
 * this base contract STAYS at the v1.0 shape — `chat()` +
 * `supportsToolCalling()`. Existing v1.4 hosts keep compiling
 * unchanged after the upgrade to v1.5.
 */
interface McpHostBridgeContract
{
    /**
     * Drive ONE turn against the model. `$turn->tools` carries the
     * tool catalog (built by the orchestrator from the registry +
     * authorizer). The host's adapter MUST forward those tools to
     * the provider in OpenAI-style function-calling shape.
     *
     * @return HostChatResponse  the model's response, including any
     *         tool_calls it emitted. Tool execution is the
     *         orchestrator's job, NOT the bridge's.
     */
    public function chat(HostChatTurn $turn): HostChatResponse;

    /**
     * Whether the host's currently-configured provider supports
     * OpenAI-style tool calling. The orchestrator uses this to
     * short-circuit early (with a clear error) when the operator
     * tries to drive a tool-call session against a tool-incapable
     * provider (e.g. older Anthropic / Gemini).
     */
    public function supportsToolCalling(): bool;
}
