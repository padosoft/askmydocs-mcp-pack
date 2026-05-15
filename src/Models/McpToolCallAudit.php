<?php

namespace Padosoft\AskMyDocsMcpPack\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable audit row for every MCP tool invocation the orchestrator
 * runs. Compliant with the AskMyDocs `kb_canonical_audit` philosophy:
 * SHA-256 hashes instead of raw payloads, no `updated_at`.
 *
 * Hosts that need richer telemetry should subclass and add columns
 * via a follow-up migration — the orchestrator looks up the FQCN
 * through {@see config('mcp-pack.audit_model')}, so swapping the
 * model is a one-line config change.
 *
 * @property string      $tenant_id
 * @property string|null $actor
 * @property string      $mcp_server_id
 * @property string|null $mcp_server_name
 * @property int|null    $conversation_id
 * @property int|null    $message_id
 * @property string      $tool_name
 * @property string      $input_hash
 * @property string|null $result_hash
 * @property int         $duration_ms
 * @property string      $status
 * @property string|null $error_excerpt
 */
class McpToolCallAudit extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';
    public const STATUS_TRANSPORT_ERROR = 'transport_error';
    public const STATUS_UNAUTHORIZED = 'unauthorized';
    public const STATUS_TIMEOUT = 'timeout';

    protected $table = 'mcp_tool_call_audit';

    protected $guarded = ['id'];

    protected $casts = [
        'conversation_id' => 'integer',
        'message_id' => 'integer',
        'duration_ms' => 'integer',
    ];
}
