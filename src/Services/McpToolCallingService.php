<?php

namespace Padosoft\AskMyDocsMcpPack\Services;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;
use Padosoft\AskMyDocsMcpPack\Support\HostChatResponse;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;
use Padosoft\AskMyDocsMcpPack\Support\HostMessage;

/**
 * Multi-turn tool-calling loop — the heart of the pack.
 *
 * Algorithm (one call to {@see chatWithTools()}):
 *
 *   1. Build the tool catalog from {@see McpServerRegistryContract}
 *      and filter through {@see McpToolAuthorizerContract}.
 *   2. Send the conversation + catalog to {@see McpHostBridgeContract::chat()}.
 *   3. If the model emits no `tool_calls`, return its content as the
 *      final answer.
 *   4. Otherwise: invoke each tool through {@see ToolInvoker}, append
 *      results to the conversation, and loop back to step 2.
 *   5. Hard-cap at `max_iterations` turns to prevent runaway loops.
 *
 * The service is provider-agnostic — every external dependency
 * (model, tool, RBAC, server discovery) is reached through a
 * contract. Hosts replace any of them via container binding.
 */
class McpToolCallingService
{
    public function __construct(
        protected readonly McpHostBridgeContract $host,
        protected readonly McpServerRegistryContract $registry,
        protected readonly McpToolAuthorizerContract $authorizer,
        protected readonly ToolInvoker $invoker,
        protected readonly McpHandshakeService $handshake,
        protected readonly int $maxIterations = 3,
    ) {}

    /**
     * @param  array<int,array<string,mixed>> $messages
     * @param  array<string,mixed>            $extras
     * @param  array<string,mixed>            $context  tenant_id, actor, conversation_id, message_id
     */
    public function chatWithTools(
        array $messages,
        ?string $tenantId = null,
        mixed $actor = null,
        array $extras = [],
        array $context = [],
    ): HostChatResponse {
        if (! $this->host->supportsToolCalling()) {
            return $this->host->chat(new HostChatTurn($messages, [], $tenantId, $extras));
        }

        $toolMap = $this->buildAuthorizedToolCatalog($tenantId, $actor);
        if ($toolMap === []) {
            return $this->host->chat(new HostChatTurn($messages, [], $tenantId, $extras));
        }

        $tools = array_map(static fn(array $entry): McpToolContract => $entry['tool'], $toolMap);
        $conversation = $messages;

        for ($i = 0; $i < $this->maxIterations; $i++) {
            $turn = new HostChatTurn($conversation, $tools, $tenantId, $extras);
            $response = $this->host->chat($turn);

            if (! $response->hasToolCalls()) {
                return $response;
            }

            $conversation[] = HostMessage::assistantWithToolCalls(
                content: (string) ($response->content ?? ''),
                toolCalls: $this->reshapeToolCallsForProvider($response->toolCalls),
            );

            foreach ($response->toolCalls as $call) {
                $name = (string) ($call['name'] ?? '');
                $entry = $toolMap[$name] ?? null;
                if ($entry === null) {
                    $conversation[] = HostMessage::tool(
                        toolCallId: (string) ($call['id'] ?? 'tool_unknown'),
                        toolName: $name,
                        content: (string) json_encode(['error' => "Tool [{$name}] is not configured for the current tenant."]),
                    );
                    continue;
                }

                $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];

                $result = $this->invoker->invoke(
                    server: $entry['server'],
                    toolName: $name,
                    arguments: $arguments,
                    context: $context + ['tenant_id' => $tenantId, 'actor' => $actor],
                );

                $conversation[] = HostMessage::tool(
                    toolCallId: (string) ($call['id'] ?? $result->toolCallId),
                    toolName: $name,
                    content: $result->toMessagePayload(),
                );
            }
        }

        // Hit max-iterations — let the model produce a final answer
        // with no further tool budget.
        return $this->host->chat(new HostChatTurn($conversation, [], $tenantId, $extras));
    }

    /**
     * @return array<string,array{tool:McpToolContract,server:McpServerContract}>
     */
    protected function buildAuthorizedToolCatalog(?string $tenantId, mixed $actor): array
    {
        $catalog = [];
        $servers = $this->registry->forTenant($tenantId);

        foreach ($servers as $server) {
            if (! $server->isEnabled()) {
                continue;
            }

            $allowList = $server->allowedTools();
            $allowAll = $allowList === [];

            try {
                $payload = $this->handshake->refresh($server);
            } catch (\Throwable) {
                continue;
            }

            foreach ($payload['tools'] as $toolPayload) {
                $name = (string) ($toolPayload['name'] ?? '');
                if ($name === '' || isset($catalog[$name])) {
                    continue;
                }
                if (! $allowAll && ! in_array($name, $allowList, true)) {
                    continue;
                }

                $tool = new RemoteMcpTool($name, $toolPayload, $server, $this->invoker);
                if (! $this->authorizer->authorize($actor, $tenantId, $tool)) {
                    continue;
                }

                $catalog[$name] = ['tool' => $tool, 'server' => $server];
            }
        }

        return $catalog;
    }

    /**
     * @param  array<int,array{id:string,name:string,arguments:array<string,mixed>}> $toolCalls
     * @return array<int,array{id:string,type:string,function:array{name:string,arguments:string}}>
     */
    protected function reshapeToolCallsForProvider(array $toolCalls): array
    {
        $reshaped = [];
        foreach ($toolCalls as $call) {
            $reshaped[] = [
                'id' => (string) ($call['id'] ?? ('tool_' . bin2hex(random_bytes(8)))),
                'type' => 'function',
                'function' => [
                    'name' => (string) ($call['name'] ?? ''),
                    'arguments' => (string) json_encode($call['arguments'] ?? [], JSON_UNESCAPED_UNICODE),
                ],
            ];
        }

        return $reshaped;
    }
}
