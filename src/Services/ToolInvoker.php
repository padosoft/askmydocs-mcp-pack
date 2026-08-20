<?php

namespace Padosoft\AskMyDocsMcpPack\Services;

use Illuminate\Database\Eloquent\Model;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Models\McpToolCallAudit;
use Padosoft\AskMyDocsMcpPack\Resilience\ResilienceMediator;
use Padosoft\AskMyDocsMcpPack\Support\ToolCallResult;

/**
 * Executes a single tool call against the upstream MCP server and
 * writes an audit row.
 *
 * The audit row stores: tenant, actor, server id, tool name,
 * SHA-256 hashes of input + output (NOT raw payloads — privacy),
 * duration, status, and a redacted error excerpt. Hosts that need
 * full-payload audit can subclass and override {@see audit()}.
 */
class ToolInvoker
{
    public function __construct(
        private readonly ?ResilienceMediator $resilience = null,
    ) {}

    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $context  tenant_id, actor, conversation_id, message_id
     */
    public function invoke(
        McpServerContract $server,
        string $toolName,
        array $arguments,
        array $context = [],
    ): ToolCallResult {
        $start = microtime(true);
        $toolCallId = 'tool_'.bin2hex(random_bytes(8));
        $status = 'ok';
        $error = null;
        $result = null;
        $client = null;

        $tenantId = (string) ($context['tenant_id'] ?? $server->tenantId() ?? 'default');

        try {
            $client = McpClient::forServer($server);
            $call = static fn (): array => $client->callTool($toolName, $arguments);
            $result = $this->resilience === null
                ? $call()
                : $this->resilience->execute(
                    $tenantId,
                    $server->id(),
                    $toolName,
                    $call,
                    retryable: (bool) ($context['read_only'] ?? false) || (bool) ($context['idempotent'] ?? false),
                );
            $context['protocol_version'] ??= $client->negotiatedProtocol()?->protocolVersion;
            $context['result_type'] ??= is_string($result['resultType'] ?? null) ? $result['resultType'] : null;
            $taskId = data_get($result, 'taskId', data_get($result, 'task.taskId'));
            $context['task_id'] ??= is_string($taskId) ? $taskId : null;
            $context['artifact_ids'] ??= is_array($result['artifactIds'] ?? null) ? $result['artifactIds'] : null;
        } catch (McpTransportException $e) {
            $status = 'transport_error';
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            $status = 'error';
            $error = $e->getMessage();
        }

        $latencyMs = (microtime(true) - $start) * 1000;

        $this->audit($server, $toolName, $arguments, $result, $status, $error, $latencyMs, $context);

        return new ToolCallResult(
            toolCallId: $toolCallId,
            toolName: $toolName,
            result: $result,
            error: $error,
            latencyMs: $latencyMs,
        );
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>|null  $result
     * @param  array<string,mixed>  $context
     */
    protected function audit(
        McpServerContract $server,
        string $toolName,
        array $arguments,
        ?array $result,
        string $status,
        ?string $error,
        float $latencyMs,
        array $context,
    ): void {
        $modelClass = $this->resolveAuditModelClass();
        if ($modelClass === null) {
            return;
        }

        try {
            $modelClass::query()->create([
                // Coalesce null tenant id to the migration's 'default'
                // sentinel — the column is NOT NULL with default
                // 'default', and an explicit null would otherwise blow
                // up under strict-mode databases and silently drop the
                // audit row through the catch.
                'tenant_id' => $context['tenant_id'] ?? $server->tenantId() ?? 'default',
                'actor' => $this->actorId($context['actor'] ?? null),
                'mcp_server_id' => $server->id(),
                'mcp_server_name' => $server->name(),
                'conversation_id' => $context['conversation_id'] ?? null,
                'message_id' => $context['message_id'] ?? null,
                'tool_name' => $toolName,
                'input_hash' => hash('sha256', (string) json_encode($arguments, JSON_UNESCAPED_UNICODE)),
                'result_hash' => $result !== null
                    ? hash('sha256', (string) json_encode($result, JSON_UNESCAPED_UNICODE))
                    : null,
                'duration_ms' => (int) round($latencyMs),
                'status' => $status,
                'error_excerpt' => $error !== null ? $this->redactError($error) : null,
                'protocol_version' => $context['protocol_version'] ?? null,
                'result_type' => $context['result_type'] ?? null,
                'task_id' => $context['task_id'] ?? null,
                'artifact_ids' => isset($context['artifact_ids']) && is_array($context['artifact_ids']) ? $context['artifact_ids'] : null,
            ]);
        } catch (\Throwable) {
            // Audit logging MUST NEVER break the user path. Swallow
            // and let the orchestrator continue.
        }
    }

    /**
     * Honour the `mcp-pack.audit_model` override so hosts can subclass
     * the audit model and add per-host columns without forking the
     * pack.
     *
     * @return class-string<Model>|null
     */
    protected function resolveAuditModelClass(): ?string
    {
        $configured = null;
        if (function_exists('config')) {
            $configured = config('mcp-pack.audit_model');
        }
        $class = is_string($configured) && $configured !== ''
            ? $configured
            : McpToolCallAudit::class;

        return class_exists($class) ? $class : null;
    }

    private function actorId(mixed $actor): ?string
    {
        $value = is_object($actor) && method_exists($actor, 'getAuthIdentifier')
            ? $actor->getAuthIdentifier()
            : data_get($actor, 'id', $actor);

        return is_scalar($value) ? (string) $value : null;
    }

    private function redactError(string $error): string
    {
        $patterns = [
            '/\bBearer\s+[^\s,;]+/i' => 'Bearer [REDACTED]',
            '/\b(sk|pk|rk)_[A-Za-z0-9_-]{8,}\b/' => '[REDACTED_KEY]',
            // key=value, key: value and "key":"value" shapes for token-ish names,
            // including OAuth parameter names (access_token, refresh_token, id_token,
            // client_secret) so an upstream OAuth error never lands in error_excerpt.
            '/((?:[\w.-]*(?:token|secret|password|passwd|api[_-]?key|credential))\s*["\']?\s*[:=]\s*["\']?)([^\s&"\',;]+)/i' => '$1[REDACTED]',
        ];

        return mb_substr((string) preg_replace(array_keys($patterns), array_values($patterns), $error), 0, 500);
    }
}
