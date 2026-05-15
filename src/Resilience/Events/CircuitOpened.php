<?php

namespace Padosoft\AskMyDocsMcpPack\Resilience\Events;

/**
 * Emitted when a per-(server, tool) breaker transitions from
 * CLOSED → OPEN because consecutive failures crossed the threshold.
 * Hosts subscribe to wire alerting / dashboards.
 */
final class CircuitOpened
{
    public function __construct(
        public readonly string $serverId,
        public readonly string $toolName,
        public readonly int $failureCount,
        public readonly int $recoverySeconds,
        public readonly ?string $lastError = null,
    ) {}
}
