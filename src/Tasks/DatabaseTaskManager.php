<?php

namespace Padosoft\AskMyDocsMcpPack\Tasks;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\CancellationRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\SubscriptionBrokerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\TaskManagerContract;
use Padosoft\AskMyDocsMcpPack\Models\McpTask;
use Padosoft\AskMyDocsMcpPack\Protocol\HandlerInvoker;
use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;
use Padosoft\AskMyDocsMcpPack\Protocol\McpResult;
use Padosoft\AskMyDocsMcpPack\Protocol\RequestSignals;
use Padosoft\AskMyDocsMcpPack\Protocol\RequestStateCipher;
use Padosoft\AskMyDocsMcpPack\Tasks\Jobs\RunMcpTask;
use Padosoft\AskMyDocsMcpPack\Validation\JsonSchemaValidator;

final class DatabaseTaskManager implements TaskManagerContract
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Queue $queue,
        private readonly HandlerInvoker $invoker,
        private readonly SubscriptionBrokerContract $subscriptions,
        private readonly RequestStateCipher $requestStates,
        private readonly JsonSchemaValidator $validator,
        private readonly CancellationRegistryContract $cancellations,
    ) {}

    public function operational(): bool
    {
        try {
            return Schema::hasTable('mcp_tasks') && $this->queue->size() >= 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function create(string $handler, McpRequest $request, ?int $ttlSeconds = null, ?array $outputSchema = null): McpTask
    {
        if (! $this->operational()) {
            throw new \RuntimeException('MCP Tasks table is not available.');
        }
        $task = McpTask::query()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $request->tenantId, 'actor_id' => $request->actorId(),
            'handler' => $handler, 'state' => TaskStatus::Working, 'request_payload' => $this->serializeRequest($request),
            'pending_inputs' => null, 'input_responses' => $request->inputResponses, 'result' => null, 'error' => null,
            'output_schema' => $outputSchema,
            'request_state' => $request->requestState, 'expires_at' => now()->addSeconds($ttlSeconds ?? (int) config('mcp-pack.tasks.ttl_seconds', 86_400)),
            'poll_interval_ms' => (int) config('mcp-pack.tasks.poll_interval_ms', 1000), 'cancel_requested' => false, 'lock_version' => 1,
            'lease_owner' => null, 'lease_expires_at' => null,
        ]);
        $this->queue->push(new RunMcpTask((string) $task->getKey()));
        $task->refresh();
        $this->publish($task);

        return $task;
    }

    public function get(string $uuid, ?string $tenantId, ?string $actorId): McpTask
    {
        return $this->scoped($uuid, $tenantId, $actorId)->firstOrFail();
    }

    public function update(string $uuid, array $inputResponses, ?string $tenantId, ?string $actorId, ?string $requestState = null): McpTask
    {
        $task = $this->db->transaction(function () use ($uuid, $inputResponses, $tenantId, $actorId, $requestState): McpTask {
            $task = $this->scoped($uuid, $tenantId, $actorId)->lockForUpdate()->firstOrFail();
            if ($task->state !== TaskStatus::InputRequired) {
                throw new \DomainException('Task is not waiting for input.');
            }
            if ($task->request_state !== null) {
                if ($requestState === null || ! hash_equals((string) $task->request_state, $requestState)) {
                    throw new \DomainException('A matching requestState is required.');
                }
            }
            $known = (array) ($task->input_responses ?? []);
            foreach ($inputResponses as $id => $value) {
                $key = is_string($id) ? $id : (string) data_get($value, 'id', $id);
                if (array_key_exists($key, $known) && $known[$key] !== $value) {
                    throw new \DomainException("Conflicting duplicate input response [{$key}].");
                }
                $known[$key] = $value;
            }
            $required = array_values(array_filter(array_map(static fn (array $item): ?string => is_string($item['id'] ?? null) ? $item['id'] : null, (array) $task->pending_inputs)));
            $complete = $required === [] || count(array_diff($required, array_keys($known))) === 0;
            $task->input_responses = $known;
            if ($complete) {
                if ($task->request_state !== null && $requestState !== null) {
                    $payload = (array) $task->request_payload;
                    $this->requestStates->consume($requestState, $tenantId, $actorId, (string) ($payload['method'] ?? 'tools/call'), (array) ($payload['arguments'] ?? []));
                }
                $task->state = TaskStatus::Working;
                $task->pending_inputs = null;
                $task->request_state = null;
            }
            $task->lock_version++;
            $task->save();

            return $task;
        }, 3);
        if ($task->state === TaskStatus::Working) {
            $this->queue->push(new RunMcpTask((string) $task->getKey()));
        }
        $this->publish($task);

        return $task;
    }

    public function cancel(string $uuid, ?string $tenantId, ?string $actorId): McpTask
    {
        $task = $this->db->transaction(function () use ($uuid, $tenantId, $actorId): McpTask {
            $task = $this->scoped($uuid, $tenantId, $actorId)->lockForUpdate()->firstOrFail();
            if (! $task->state->terminal()) {
                $task->cancel_requested = true;
                $task->state = TaskStatus::Cancelled;
                $task->lock_version++;
                $task->save();
            }

            return $task;
        }, 3);
        $this->cancellations->cancel($task->tenant_id, (string) $task->getKey(), $task->actor_id);
        $this->publish($task);

        return $task;
    }

    public function run(string $uuid): void
    {
        $lease = (string) Str::uuid();
        $claimed = McpTask::query()->whereKey($uuid)->where('state', TaskStatus::Working->value)->where('cancel_requested', false)->where('expires_at', '>', now())
            ->where(fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->update(['lease_owner' => $lease, 'lease_expires_at' => now()->addSeconds((int) config('mcp-pack.tasks.lease_seconds', 300)), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $task = McpTask::query()->whereKey($uuid)->where('lease_owner', $lease)->first();
        if ($task === null) {
            return;
        }
        $payload = (array) $task->request_payload;
        $request = new McpRequest(
            method: (string) $payload['method'], arguments: (array) $payload['arguments'], tenantId: $task->tenant_id,
            actor: null, principalId: $task->actor_id, clientInfo: (array) ($payload['clientInfo'] ?? []),
            clientCapabilities: (array) ($payload['clientCapabilities'] ?? []), meta: (array) ($payload['meta'] ?? []),
            inputResponses: (array) ($task->input_responses ?? []), requestState: $task->request_state,
            correlationId: (string) ($payload['correlationId'] ?? Str::uuid()),
            traceparent: is_string($payload['traceparent'] ?? null) ? $payload['traceparent'] : null,
            tracestate: is_string($payload['tracestate'] ?? null) ? $payload['tracestate'] : null,
            baggage: is_string($payload['baggage'] ?? null) ? $payload['baggage'] : null,
            signals: new RequestSignals($this->subscriptions, $this->cancellations, $task->tenant_id, $task->actor_id, (string) $task->getKey()),
        );
        try {
            $result = McpResult::normalise($this->invoker->invoke((string) $task->handler, $request));
            if (is_array($task->output_schema)) {
                if (! is_array($result['structuredContent'] ?? null)) {
                    throw new \RuntimeException('Task result omitted required structuredContent.');
                }
                $this->validator->validate($task->output_schema, $result['structuredContent']);
            }
            $this->finish($task, $result);
        } catch (\Throwable $e) {
            report($e);
            $this->transition($task, TaskStatus::Failed, ['error' => ['message' => 'Task execution failed.', 'correlationId' => $request->correlationId]]);
        }
    }

    /** @param array<string,mixed> $result */
    private function finish(McpTask $task, array $result): void
    {
        if (($result['resultType'] ?? null) === 'input_required') {
            $this->transition($task, TaskStatus::InputRequired, ['pending_inputs' => $result['requests'] ?? $result['inputRequests'] ?? [], 'request_state' => $result['requestState'] ?? null]);

            return;
        }
        $this->transition($task, TaskStatus::Completed, ['result' => $result]);
    }

    /** @param array<string,mixed> $attributes */
    private function transition(McpTask $task, TaskStatus $status, array $attributes): void
    {
        $encoded = [];
        $caster = new McpTask;
        foreach ($attributes as $key => $value) {
            $caster->setAttribute($key, $value);
            $encoded[$key] = $caster->getAttributes()[$key] ?? null;
        }
        $updated = McpTask::query()->whereKey($task->getKey())->where('state', TaskStatus::Working->value)->where('cancel_requested', false)->where('lock_version', $task->lock_version)
            ->update($encoded + ['state' => $status->value, 'lock_version' => $task->lock_version + 1, 'lease_owner' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
        if ($updated === 1) {
            $fresh = $task->fresh();
            if ($fresh !== null) {
                $this->publish($fresh);
            }
        }
    }

    public function prune(): int
    {
        $count = McpTask::query()->where('expires_at', '<=', now())->delete();
        $this->db->table('mcp_request_states')->where('expires_at', '<=', now())->orWhereNotNull('consumed_at')->delete();

        return $count;
    }

    public function recover(): int
    {
        $count = 0;
        McpTask::query()->where('state', TaskStatus::Working->value)->where('cancel_requested', false)->where('expires_at', '>', now())
            ->where(fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->pluck('id')->each(function (string $id) use (&$count): void {
                $this->queue->push(new RunMcpTask($id));
                $count++;
            });

        return $count;
    }

    /** @return Builder<McpTask> */
    private function scoped(string $uuid, ?string $tenantId, ?string $actorId): object
    {
        $query = McpTask::query()->whereKey($uuid)->where('expires_at', '>', now());
        $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
        $actorId === null ? $query->whereNull('actor_id') : $query->where('actor_id', $actorId);

        return $query;
    }

    /** @return array<string,mixed> */
    private function serializeRequest(McpRequest $request): array
    {
        return ['method' => $request->method, 'arguments' => $request->arguments, 'clientInfo' => $request->clientInfo, 'clientCapabilities' => $request->clientCapabilities, 'meta' => $request->meta, 'correlationId' => $request->correlationId, 'traceparent' => $request->traceparent, 'tracestate' => $request->tracestate, 'baggage' => $request->baggage];
    }

    private function publish(McpTask $task): void
    {
        $this->subscriptions->publish($task->tenant_id, 'notifications/tasks', ['task' => $task->toProtocolArray()], $task->actor_id);
    }
}
