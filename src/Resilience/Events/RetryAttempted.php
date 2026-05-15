<?php

namespace Padosoft\AskMyDocsMcpPack\Resilience\Events;

/**
 * Emitted on every retry attempt the mediator schedules after a
 * transport failure. The attempt counter is 1-indexed (`attempt=1`
 * means the FIRST retry, not the original call).
 */
final class RetryAttempted
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $serverId,
        public readonly string $toolName,
        public readonly int $attempt,
        public readonly int $backoffMs,
        public readonly ?string $lastError = null,
    ) {}
}
