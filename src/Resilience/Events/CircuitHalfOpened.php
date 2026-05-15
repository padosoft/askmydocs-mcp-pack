<?php

namespace Padosoft\AskMyDocsMcpPack\Resilience\Events;

/**
 * Emitted when the recovery TTL elapsed and the breaker has
 * transitioned from OPEN to HALF_OPEN — one probe is allowed.
 */
final class CircuitHalfOpened
{
    public function __construct(
        public readonly string $serverId,
        public readonly string $toolName,
    ) {}
}
