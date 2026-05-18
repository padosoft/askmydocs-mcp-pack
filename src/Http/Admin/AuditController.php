<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\MintsConfirmTokens;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Concerns\ResolvesAdminContext;
use Padosoft\AskMyDocsMcpPack\Http\Admin\Requests\ReplayAuditRequest;
use Padosoft\AskMyDocsMcpPack\Models\McpToolCallAudit;
use Padosoft\AskMyDocsMcpPack\Support\McpAdminConfirmToken;

/**
 * v1.4.0 — paginated audit query surface over the
 * `mcp_tool_call_audit` table (or the host's subclass via
 * `mcp-pack.audit_model`).
 *
 * Filters (all optional, AND-combined):
 *   - `server_id`       — exact match on `mcp_server_id`
 *   - `tool_name`       — exact match on `tool_name`
 *   - `status`          — exact match on `status`
 *   - `from` / `to`     — ISO-8601 timestamps bounding `created_at`
 *
 * R30: the active tenant is resolved from the trusted middleware
 * attribute (`mcp_pack.tenant_id`) or the authenticated user, NEVER
 * from a client header. The query is scoped to that tenant.
 *
 * v1.5.0 W1.C adds two endpoints:
 *  - `show($id)` — single-row drilldown via the host bridge's
 *    `auditFor()` method (host owns the rich payload — request /
 *    response / headers / timeline / meta).
 *  - `replay($id)` — re-fires the audited tool call under an R21
 *    single-use confirm-token guard (two-call protocol: mint then
 *    consume).
 */
final class AuditController
{
    use ResolvesAdminContext;
    use MintsConfirmTokens;

    public function __construct(
        private readonly McpHostBridgeIdentityContract $identityBridge,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->__invoke($request);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $modelClass = $this->resolveAuditModelClass();
        if ($modelClass === null) {
            return new JsonResponse([
                'error' => [
                    'code' => 'audit_model_missing',
                    'message' => 'No audit model is configured. Set `mcp-pack.audit_model` to a valid Eloquent class.',
                ],
            ], 500);
        }

        $tenantId = $this->resolveTenantId($request);
        $perPage = $this->clampInt($request->integer('per_page', 25), 1, 200);

        $query = $modelClass::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // Tenant scope — null tenant is a global view; the host's
        // RBAC middleware decides whether the actor is allowed it.
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if (($serverId = $request->string('server_id')->toString()) !== '') {
            $query->where('mcp_server_id', $serverId);
        }
        if (($toolName = $request->string('tool_name')->toString()) !== '') {
            $query->where('tool_name', $toolName);
        }
        if (($status = $request->string('status')->toString()) !== '') {
            $query->where('status', $status);
        }
        if (($from = $request->string('from')->toString()) !== '') {
            $query->where('created_at', '>=', $from);
        }
        if (($to = $request->string('to')->toString()) !== '') {
            $query->where('created_at', '<=', $to);
        }

        $paginator = $query->paginate($perPage);

        return new JsonResponse([
            'data' => $paginator->getCollection()->map(
                static fn(Model $row): array => [
                    'id' => $row->getKey(),
                    'tenant_id' => $row->getAttribute('tenant_id'),
                    'actor' => $row->getAttribute('actor'),
                    'mcp_server_id' => $row->getAttribute('mcp_server_id'),
                    'mcp_server_name' => $row->getAttribute('mcp_server_name'),
                    'tool_name' => $row->getAttribute('tool_name'),
                    'input_hash' => $row->getAttribute('input_hash'),
                    'result_hash' => $row->getAttribute('result_hash'),
                    'duration_ms' => $row->getAttribute('duration_ms'),
                    'status' => $row->getAttribute('status'),
                    'error_excerpt' => $row->getAttribute('error_excerpt'),
                    'created_at' => optional($row->getAttribute('created_at'))->toIso8601String(),
                ],
            )->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'tenant_id' => $tenantId,
            ],
        ]);
    }

    /**
     * v1.5.0 W1.C — `GET /audit/{id}`. Returns the rich drilldown
     * payload (request / response / headers / timeline / meta) the
     * SPA's `AUDIT_DETAIL` fixture in `data.js` describes. The host
     * bridge owns the payload because the package only persists
     * SHA-256 hashes of the input/output — full raw payloads live in
     * the host's audit subclass (per the per-host augmentation
     * pattern documented in {@see McpToolCallAudit}).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $blocked = $this->featureGate('audit_show');
        if ($blocked !== null) {
            return $blocked;
        }

        $tenantId = $this->resolveTenantId($request);

        return $this->withHostBridge(function () use ($id, $tenantId): JsonResponse {
            $row = $this->identityBridge->auditFor($id, $tenantId);
            if ($row === null) {
                return $this->notFound("Audit row [{$id}] not found.");
            }
            return new JsonResponse(['data' => $row]);
        });
    }

    /**
     * v1.5.0 W1.C — `POST /audit/{id}/replay`. Two-call protocol:
     *  1. First POST (no `confirm_token`) — mint a token + 202.
     *  2. Second POST (with the token) — forward to the host bridge
     *     which atomically consumes + replays inside its own
     *     `DB::transaction` (R21 — see contract docblock on
     *     {@see McpHostBridgeIdentityContract::replayAudit()}).
     *
     * Cross-tenant safety: BEFORE minting we check that the audit row
     * is visible to the active tenant via `auditFor()`. A foreign-tenant
     * id surfaces as 404 at mint time, so an attacker can't even
     * harvest a token for a row they shouldn't see.
     */
    public function replay(ReplayAuditRequest $request, string $id): JsonResponse
    {
        $blocked = $this->featureGate('audit_replay');
        if ($blocked !== null) {
            return $blocked;
        }

        $tenantId = $this->resolveTenantId($request);
        $confirmToken = $request->confirmToken();

        if ($confirmToken === null) {
            // Mint path — verify visibility first so cross-tenant ids
            // don't even leak a usable token.
            return $this->withHostBridge(function () use ($id, $tenantId): JsonResponse {
                $row = $this->identityBridge->auditFor($id, $tenantId);
                if ($row === null) {
                    return $this->notFound("Audit row [{$id}] not found.");
                }
                return $this->mintConfirmToken(
                    scope: McpAdminConfirmToken::SCOPE_AUDIT_REPLAY,
                    targetId: (string) $id,
                    tenantId: $tenantId,
                );
            });
        }

        // Consume path — validate the package's mint marker, then
        // hand off to the host bridge which performs the atomic
        // `lockForUpdate` + `used_at` write + replay in one transaction.
        $invalid = $this->validateConfirmToken(
            scope: McpAdminConfirmToken::SCOPE_AUDIT_REPLAY,
            targetId: (string) $id,
            tenantId: $tenantId,
            token: $confirmToken,
        );
        if ($invalid !== null) {
            return $invalid;
        }

        return $this->withHostBridge(function () use ($id, $tenantId, $confirmToken): JsonResponse {
            // R30 belt-and-braces: re-check visibility on consume.
            $row = $this->identityBridge->auditFor($id, $tenantId);
            if ($row === null) {
                return $this->notFound("Audit row [{$id}] not found.");
            }
            $result = $this->identityBridge->replayAudit($id, $confirmToken);
            // R21: best-effort forget on success — the host's
            // `used_at` flag is the source of truth, this is the
            // package's defence-in-depth so the cache marker can't
            // be re-presented.
            $this->forgetConfirmToken(
                scope: McpAdminConfirmToken::SCOPE_AUDIT_REPLAY,
                targetId: (string) $id,
                token: $confirmToken,
            );
            return new JsonResponse(['data' => $result]);
        });
    }

    /** @return class-string<\Illuminate\Database\Eloquent\Model>|null */
    private function resolveAuditModelClass(): ?string
    {
        $configured = config('mcp-pack.audit_model');
        $class = is_string($configured) && $configured !== ''
            ? $configured
            : McpToolCallAudit::class;
        // The controller calls Eloquent APIs (`::query()`, attribute
        // accessors); reject anything that isn't a `Model` subclass
        // so a misconfigured FQCN surfaces a clean JSON 500 instead
        // of an `Error: Call to undefined method ::query()`.
        if (! class_exists($class)) {
            return null;
        }
        if (! is_subclass_of($class, Model::class) && $class !== Model::class) {
            return null;
        }
        return $class;
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function notFound(string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => ['code' => 'not_found', 'message' => $message],
        ], 404);
    }
}
