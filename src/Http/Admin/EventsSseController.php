<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\ResolvesAdminContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * v1.5.0 W1.D — `GET /events` (Server-Sent Events stream).
 *
 * The controller polls the host bridge's `recentAudit()` at the
 * configured cadence (`mcp-pack.admin.sse.poll_ms`, default 1000ms)
 * and emits one `event: invocation\ndata: <json>\n\n` frame per new
 * row. Each connection is hard-capped at
 * `mcp-pack.admin.sse.max_seconds` (default 300s) so a hung client
 * cannot starve PHP-FPM workers forever.
 *
 * R30: the trusted tenant id from the `mcp_pack.tenant_id`
 * middleware attribute is forwarded to the bridge on every poll. A
 * null tenant means the actor is allowed to see every tenant's
 * stream (host's RBAC decision).
 *
 * The first poll fires immediately (no initial sleep) so the SPA
 * sees any recent activity as soon as the connection is established.
 *
 * Test posture: the `single_frame` test path stops reading after the
 * first frame and uses an artificially short `max_seconds` so the
 * `StreamedResponse` callback returns within the test budget.
 */
final class EventsSseController
{
    use ResolvesAdminContext;

    public function __construct(
        private readonly McpHostBridgeIdentityContract $identityBridge,
    ) {}

    public function __invoke(Request $request): Response
    {
        $blocked = $this->featureGate('events_sse');
        if ($blocked !== null) {
            return $blocked;
        }

        // We probe the bridge ONCE up-front so a missing impl
        // surfaces as a normal 501 JSON envelope (not as a 200
        // text/event-stream that never sends a frame). After this
        // probe the streaming loop runs without further 501 risk.
        try {
            $initialRows = $this->identityBridge->recentAudit(
                sinceId: null,
                tenantId: $this->resolveTenantId($request),
            );
        } catch (HostFeatureNotImplementedException $e) {
            return new JsonResponse([
                'error' => [
                    'code' => 'feature_not_implemented',
                    'message' => $e->getMessage(),
                ],
            ], 501);
        }

        $tenantId = $this->resolveTenantId($request);
        $pollMs = max(100, (int) config('mcp-pack.admin.sse.poll_ms', 1000));
        $maxSeconds = max(1, (int) config('mcp-pack.admin.sse.max_seconds', 300));
        $bridge = $this->identityBridge;

        return new StreamedResponse(
            function () use ($initialRows, $tenantId, $pollMs, $maxSeconds, $bridge): void {
                $startedAt = microtime(true);
                $cursor = null;

                // Emit the initial poll's rows BEFORE entering the
                // wait loop so the SPA sees recent activity at
                // connect-time.
                foreach ($initialRows as $row) {
                    $this->emit($row);
                    $cursor = $this->advanceCursor($cursor, $row);
                }

                while (true) {
                    if ($this->elapsedExceeds($startedAt, $maxSeconds)) {
                        return;
                    }
                    if (connection_aborted() !== 0) {
                        return;
                    }

                    // 1s default poll cadence. `usleep` takes
                    // microseconds; 1000ms * 1000 = 1_000_000.
                    usleep($pollMs * 1000);

                    if ($this->elapsedExceeds($startedAt, $maxSeconds)) {
                        return;
                    }

                    try {
                        $rows = $bridge->recentAudit(
                            sinceId: $cursor,
                            tenantId: $tenantId,
                        );
                    } catch (HostFeatureNotImplementedException) {
                        // Mid-stream bridge revocation — close the
                        // stream silently. The browser will
                        // auto-reconnect and hit the up-front 501.
                        return;
                    }

                    foreach ($rows as $row) {
                        $this->emit($row);
                        $cursor = $this->advanceCursor($cursor, $row);
                    }

                    if (connection_aborted() !== 0) {
                        return;
                    }
                }
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function emit(array $row): void
    {
        $payload = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        echo "event: invocation\n";
        echo "data: {$payload}\n\n";
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function advanceCursor(int|string|null $cursor, array $row): int|string|null
    {
        if (! isset($row['id'])) {
            return $cursor;
        }
        $rowId = $row['id'];
        if (is_numeric($rowId) && is_numeric($cursor)) {
            return (int) $rowId > (int) $cursor ? (int) $rowId : $cursor;
        }
        // Non-numeric ids: trust the host to filter — adopt the
        // last-seen id as cursor.
        return $rowId;
    }

    private function elapsedExceeds(float $startedAt, int $maxSeconds): bool
    {
        return (microtime(true) - $startedAt) >= $maxSeconds;
    }
}
