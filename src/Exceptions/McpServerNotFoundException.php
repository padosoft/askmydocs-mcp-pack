<?php

namespace Padosoft\AskMyDocsMcpPack\Exceptions;

/**
 * v1.5.0 — thrown by `McpServerMutableRegistryContract::update()` /
 * `delete()` when the target id does not exist (within the host's
 * tenant scope). The admin `ServersController` catches this and
 * answers HTTP 404 with the stable `not_found` error envelope; any
 * other exception bubbles up to the framework handler (500).
 *
 * The exception exists as a distinct type so hosts can signal
 * `not-found` cleanly across the contract boundary without having
 * to pre-check existence twice (once on the controller, once on the
 * host) — a check that is anyway racy under concurrent writes.
 *
 * Wrapping host-side absence into a typed exception also means the
 * controller does NOT need to special-case `update()` returning a
 * sentinel value (the v1.0 contract still mandates returning the
 * updated row on success, not nullable).
 */
final class McpServerNotFoundException extends McpException
{
    public static function forId(string $id, ?string $tenantId = null): self
    {
        $msg = "MCP server [{$id}] does not exist";
        if ($tenantId !== null && $tenantId !== '') {
            $msg .= " in tenant [{$tenantId}]";
        }
        $msg .= '.';

        return new self($msg);
    }
}
