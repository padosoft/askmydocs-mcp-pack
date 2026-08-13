<?php

namespace Padosoft\AskMyDocsMcpPack\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Padosoft\AskMyDocsMcpPack\Tasks\TaskStatus;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $actor_id
 * @property string $handler
 * @property TaskStatus $state
 * @property array<string,mixed> $request_payload
 * @property list<array<string,mixed>>|null $pending_inputs
 * @property array<string,mixed>|null $input_responses
 * @property array<string,mixed>|null $result
 * @property array<string,mixed>|null $error
 * @property array<string,mixed>|null $output_schema
 * @property string|null $request_state
 * @property bool $cancel_requested
 * @property int $lock_version
 * @property int $poll_interval_ms
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $lease_expires_at
 */
final class McpTask extends Model
{
    use HasUuids;

    protected $table = 'mcp_tasks';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = [
        'state' => TaskStatus::class,
        'request_payload' => 'encrypted:array',
        'pending_inputs' => 'encrypted:array',
        'input_responses' => 'encrypted:array',
        'result' => 'encrypted:array',
        'error' => 'encrypted:array',
        'output_schema' => 'encrypted:array',
        'cancel_requested' => 'boolean',
        'lock_version' => 'integer',
        'poll_interval_ms' => 'integer',
        'expires_at' => 'immutable_datetime',
        'lease_expires_at' => 'immutable_datetime',
    ];

    /** @return array<string,mixed> */
    public function toProtocolArray(): array
    {
        $value = [
            'taskId' => (string) $this->getKey(),
            'status' => $this->state instanceof TaskStatus ? $this->state->value : (string) $this->state,
            'pollIntervalMs' => (int) $this->poll_interval_ms,
            'expiresAt' => $this->expires_at?->toAtomString(),
        ];
        if ($this->pending_inputs !== null) {
            $value['inputRequests'] = $this->pending_inputs;
        }
        if ($this->result !== null) {
            $value['result'] = $this->result;
        }
        if ($this->error !== null) {
            $value['error'] = $this->error;
        }

        return array_filter($value, static fn (mixed $item): bool => $item !== null);
    }
}
