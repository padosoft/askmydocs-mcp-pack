<?php

namespace Padosoft\AskMyDocsMcpPack\Resilience\Events;

/**
 * Emitted when either the configured `max_attempts` is reached OR
 * the token-bucket budget per (tenant, server) is depleted. The
 * mediator surfaces the underlying transport exception to the
 * caller; this event is for telemetry only.
 */
final class RetryExhausted
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $serverId,
        public readonly string $toolName,
        public readonly int $attempts,
        public readonly string $reason, // 'max_attempts' | 'budget_depleted'
        public readonly ?string $lastError = null,
    ) {}
}
