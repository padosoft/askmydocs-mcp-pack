<?php

namespace Padosoft\AskMyDocsMcpPack\Resilience\Events;

/**
 * Emitted when a probe succeeded in HALF_OPEN and the breaker
 * returned to CLOSED.
 */
final class CircuitClosed
{
    public function __construct(
        public readonly string $serverId,
        public readonly string $toolName,
    ) {}
}
