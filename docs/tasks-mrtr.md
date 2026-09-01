# Tasks and multi-round tool requests

MRTR is available from tool, resource and prompt handlers by returning `McpResult::make()->inputRequired($requests, $requestState)`. Use `RequestStateCipher::issue()` to produce authenticated continuation state bound to tenant, actor, method and input digest. `issue()` only creates the state; synchronous handlers must call `RequestStateCipher::consume()` when accepting the continuation to enforce single use. Database-backed async Tasks perform that consume automatically when their required input set becomes complete. Replay or cross-tenant use is then rejected atomically.

Async tools add `->asTask()` and use a container-resolvable class-string handler. The package creates the durable `mcp_tasks` row before dispatching `RunMcpTask`, encrypts request/input/result/error columns, and advertises the Tasks extension only when the flag, table and queue connection are operational.

Task lifecycle:

```text
working -> input_required -> working -> completed
    |             |             |----> failed
    |             |------------------> cancelled
    |--------------------------------> cancelled
```

Updates are row-locked and versioned. Duplicate input with the same value is idempotent; conflicting duplicate input fails. Cancellation is terminal and cooperative. Active handlers renew their worker lease before and after invocation and whenever they report progress or check cancellation; stale workers cannot commit after ownership changes. The queue job timeout is kept below the lease by `tasks.lease_safety_seconds`, so `php artisan mcp-pack:tasks:recover` cannot reclaim a normally running job. Long handlers should report progress or poll cancellation during multi-step work. Keep the queue connection's `retry_after` above `tasks.lease_seconds`.

Client helpers are `task()`, `getTask()`, `updateTask()`, `cancelTask()` and `waitForTask()`. The waiter honors server `pollIntervalMs`, caller timeout and maximum poll count.

Only `elicitation/create` is intended for new server MRTR flows. Roots, sampling and logging remain available solely when consuming legacy upstream servers.
