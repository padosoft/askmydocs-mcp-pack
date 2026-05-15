<?php

namespace Padosoft\AskMyDocsMcpPack\Resilience;

/**
 * The three classical circuit-breaker states.
 *
 *   - CLOSED    — normal operation; calls flow through.
 *   - OPEN      — calls are short-circuited; the breaker fails fast
 *                 until the recovery TTL elapses.
 *   - HALF_OPEN — after the TTL, ONE probe call is allowed; if it
 *                 succeeds the breaker returns to CLOSED, if it
 *                 fails the breaker re-OPENs for another TTL.
 */
enum CircuitState: string
{
    case CLOSED = 'closed';
    case OPEN = 'open';
    case HALF_OPEN = 'half_open';
}
