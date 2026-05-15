<?php

namespace Padosoft\AskMyDocsMcpPack\Http\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Models\McpToolCallAudit;

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
 */
final class AuditController
{
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

    private function resolveTenantId(Request $request): ?string
    {
        $trustedAttribute = $request->attributes->get('mcp_pack.tenant_id');
        if (is_string($trustedAttribute) && $trustedAttribute !== '') {
            return $trustedAttribute;
        }
        $user = $request->user();
        if ($user === null) {
            return null;
        }
        $tenant = data_get($user, 'tenant_id');
        return is_string($tenant) && $tenant !== '' ? $tenant : null;
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
