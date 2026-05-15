<?php

namespace Padosoft\AskMyDocsMcpPack\Services;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;

/**
 * Concrete {@see McpToolContract} backed by a remote MCP server.
 *
 * Built by {@see McpToolCallingService::buildAuthorizedToolCatalog()}
 * from the payload returned by `tools/list`. `invoke()` delegates to
 * {@see ToolInvoker::invoke()} so the orchestrator's audit trail is
 * consistent whether the tool is fired from inside the multi-turn
 * loop OR through a direct `$tool->invoke($args)` call.
 */
final class RemoteMcpTool implements McpToolContract
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        private readonly string $name,
        private readonly array $payload,
        private readonly McpServerContract $server,
        private readonly ToolInvoker $invoker,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return (string) ($this->payload['description'] ?? '');
    }

    public function schema(): array
    {
        $schema = $this->payload['inputSchema'] ?? $this->payload['input_schema'] ?? $this->payload['parameters'] ?? [];
        if (! is_array($schema)) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }
        if (! isset($schema['type'])) {
            $schema['type'] = 'object';
        }
        if (! isset($schema['properties'])) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    public function isIdempotent(): bool
    {
        return (bool) ($this->payload['idempotent'] ?? false);
    }

    public function isReadOnly(): bool
    {
        return (bool) ($this->payload['readOnly'] ?? $this->payload['read_only'] ?? false);
    }

    public function invoke(array $arguments): mixed
    {
        return $this->invoker
            ->invoke($this->server, $this->name, $arguments)
            ->result;
    }

    public function server(): McpServerContract
    {
        return $this->server;
    }
}
