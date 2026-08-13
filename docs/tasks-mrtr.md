# Tasks and multi-round tool requests

MRTR is available from tool, resource and prompt handlers by returning `McpResult::make()->inputRequired($requests, $requestState)`. Use `RequestStateCipher::issue()` to produce authenticated continuation state bound to tenant, actor, method and input digest. Single-use states have an atomic database consume record; replay or cross-tenant use is rejected.

Async tools add `->asTask()` and use a container-resolvable class-string handler. The package creates the durable `mcp_tasks` row before dispatching `RunMcpTask`, encrypts request/input/result/error columns, and advertises the Tasks extension only when the flag, table and queue connection are operational.

Task lifecycle:

```text
working -> input_required -> working -> completed
    |             |             |----> failed
    |             |------------------> cancelled
    |--------------------------------> cancelled
```

Updates are row-locked and versioned. Duplicate input with the same value is idempotent; conflicting duplicate input fails. Cancellation is terminal and cooperative. Worker leases prevent simultaneous execution and `php artisan mcp-pack:tasks:recover` requeues unleased/expired-leased working tasks after a restart.

Client helpers are `task()`, `getTask()`, `updateTask()`, `cancelTask()` and `waitForTask()`. The waiter honors server `pollIntervalMs`, caller timeout and maximum poll count.

Only `elicitation/create` is intended for new server MRTR flows. Roots, sampling and logging remain available solely when consuming legacy upstream servers.
