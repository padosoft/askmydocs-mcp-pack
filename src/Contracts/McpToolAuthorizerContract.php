<?php

namespace Padosoft\AskMyDocsMcpPack\Contracts;

/**
 * RBAC + tenant gate for tool invocations.
 *
 * Hosts wire this against their preferred policy stack (Gate facade,
 * Spatie permissions, custom claim parser). The orchestrator consults
 * the authorizer BEFORE dispatching a tool call AND when building the
 * tool catalog — so denied tools are never shown to the model in the
 * first place.
 *
 * The pack ships a {@see \Padosoft\AskMyDocsMcpPack\Defaults\NullMcpToolAuthorizer}
 * that allows everything. Production hosts MUST replace it.
 */
interface McpToolAuthorizerContract
{
    /**
     * Whether `$actor` (host-defined: typically a user-id string or
     * `null` for system contexts) is allowed to see/use `$tool` in the
     * given tenant context.
     *
     * Implementations SHOULD return false for write-tools when the
     * actor only carries read scopes — the orchestrator uses
     * {@see McpToolContract::isReadOnly()} as a hint, but the final
     * decision is the authorizer's.
     */
    public function authorize(mixed $actor, ?string $tenantId, McpToolContract $tool): bool;
}
