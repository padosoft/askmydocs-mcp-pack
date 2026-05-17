<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\ResolvesAdminContext;
use Padosoft\AskMyDocsMcpPack\Services\McpHandshakeService;

/**
 * v1.5.0 — `GET /tools` flat aggregator.
 *
 * Walks every enabled server visible to the active tenant, runs (or
 * reuses cached) handshake against each one, and surfaces a flat
 * `[{server_id, server_name, name, desc, destructive, calls_24h, p50, schema}]`
 * shape matching the `data.js` `ALL_TOOLS` reference. De-duplication
 * keys are `(server_id, tool_name)` — a server cannot reasonably
 * advertise two tools with the same name, but defensive dedupe keeps
 * the SPA's row-key contract intact.
 *
 * Filters:
 *  - `?q=<substr>` — case-insensitive substring against tool name +
 *                    description
 *  - `?server_id=<id>` — restrict to a specific server (still
 *                       tenant-scoped via `forTenant()`)
 *  - `?destructive=true|false` — show only destructive / read-only
 *                                tools (the heuristic mirrors the
 *                                SPA's own classification: a tool
 *                                advertising `destructive: true` in
 *                                its handshake metadata, OR a name
 *                                containing `write`/`create`/`delete`/
 *                                `merge`/`post` — the package
 *                                conservatively errs toward
 *                                "destructive" when the metadata is
 *                                ambiguous).
 *
 * Handshake failures degrade gracefully: a server that throws
 * `McpTransportException` during refresh is skipped (tools list
 * unavailable) and recorded under `meta.unreachable_servers[]` so
 * the SPA can render a partial-data banner instead of a 502 that
 * masks every healthy server's tools.
 */
final class ToolsController
{
    use ResolvesAdminContext;

    public function __construct(
        private readonly McpServerRegistryContract $registry,
        private readonly McpHandshakeService $handshake,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $blocked = $this->featureGate('tools');
        if ($blocked !== null) {
            return $blocked;
        }

        $tenantId = $this->resolveTenantId($request);
        $servers = $this->registry->forTenant($tenantId);

        $q = trim((string) $request->query('q', ''));
        $serverFilter = trim((string) $request->query('server_id', ''));
        $destructiveFilter = $this->parseTriBool($request->query('destructive'));

        $rows = [];
        $seen = []; // `(server_id, tool_name)` dedupe set
        $unreachable = [];

        foreach ($servers as $server) {
            if ($serverFilter !== '' && $server->id() !== $serverFilter) {
                continue;
            }

            $tools = $this->collectTools($server, $unreachable);
            $allowed = $server->allowedTools();
            if ($allowed !== []) {
                $tools = array_values(array_filter(
                    $tools,
                    static fn(array $t): bool => in_array((string) ($t['name'] ?? ''), $allowed, true),
                ));
            }

            foreach ($tools as $tool) {
                $name = (string) ($tool['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $dedupeKey = $server->id() . "\x1f" . $name;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $row = $this->flatten($server, $tool);

                if (! $this->matchesQuery($row, $q)) {
                    continue;
                }
                if ($destructiveFilter !== null && $row['destructive'] !== $destructiveFilter) {
                    continue;
                }

                $rows[] = $row;
            }
        }

        return new JsonResponse([
            'data' => $rows,
            'meta' => [
                'total' => count($rows),
                'server_count' => count($servers),
                'tenant_id' => $tenantId,
                'unreachable_servers' => $unreachable,
            ],
        ]);
    }

    /**
     * Collect the tools list for a single server, catching transport
     * failures and recording the server id under `$unreachable[]`.
     *
     * @param array<int,string> $unreachable
     * @return array<int,array<string,mixed>>
     */
    private function collectTools(McpServerContract $server, array &$unreachable): array
    {
        try {
            $payload = $this->handshake->refresh($server, force: false);
            /** @var array<int,array<string,mixed>> $tools */
            $tools = $payload['tools'] ?? [];
            return $tools;
        } catch (McpTransportException) {
            $unreachable[] = $server->id();
            return [];
        }
    }

    /**
     * Flatten a tool payload into the data-shape the SPA renders.
     * Optional metadata fields (calls_24h, p50, destructive, schema)
     * default to safe-empty values so the SPA never branches on
     * undefined.
     *
     * @param array<string,mixed> $tool
     * @return array<string,mixed>
     */
    private function flatten(McpServerContract $server, array $tool): array
    {
        $name = (string) ($tool['name'] ?? '');
        $desc = (string) ($tool['description'] ?? $tool['desc'] ?? '');
        $destructive = $this->detectDestructive($tool, $name);

        return [
            'server_id' => $server->id(),
            'server_name' => $server->name(),
            'name' => $name,
            'desc' => $desc,
            'destructive' => $destructive,
            'calls_24h' => (int) ($tool['calls_24h'] ?? 0),
            'p50' => (int) ($tool['p50'] ?? 0),
            'schema' => is_array($tool['schema'] ?? null) ? $tool['schema'] : new \stdClass(),
        ];
    }

    /**
     * Conservative destructive-tool classifier: an explicit
     * `destructive: true` wins; otherwise a name-pattern heuristic
     * flags tools whose name contains a mutating verb. The SPA uses
     * this to render the orange `destructive` chip.
     *
     * @param array<string,mixed> $tool
     */
    private function detectDestructive(array $tool, string $name): bool
    {
        if (isset($tool['destructive'])) {
            return filter_var($tool['destructive'], FILTER_VALIDATE_BOOLEAN);
        }

        $lower = strtolower($name);
        foreach (['write', 'create', 'delete', 'merge', 'post', 'send', 'remove', 'update'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Case-insensitive substring match against `name` + `desc`. Empty
     * query returns true (no filter).
     *
     * @param array<string,mixed> $row
     */
    private function matchesQuery(array $row, string $q): bool
    {
        if ($q === '') {
            return true;
        }
        $needle = strtolower($q);
        $hayName = strtolower((string) $row['name']);
        $hayDesc = strtolower((string) $row['desc']);
        return str_contains($hayName, $needle) || str_contains($hayDesc, $needle);
    }

    /**
     * Coerce the `?destructive=` query param into `true | false | null`.
     * `null` means "no filter"; anything else is the boolean to match.
     */
    private function parseTriBool(mixed $raw): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return is_bool($parsed) ? $parsed : null;
    }
}
