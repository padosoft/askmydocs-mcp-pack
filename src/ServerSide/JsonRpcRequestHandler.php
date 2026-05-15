<?php

namespace Padosoft\AskMyDocsMcpPack\ServerSide;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerExposureContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

/**
 * v1.2.0 — Single JSON-RPC method dispatcher shared by every
 * server-side runner (stdio, HTTP, SSE).
 *
 * Each `handle()` call takes one inbound {@see JsonRpcMessage} (a
 * request OR a notification) and returns one outbound message (or
 * `null` for fire-and-forget notifications). The handler is
 * transport-agnostic: stdio runner reads from STDIN and writes to
 * STDOUT; HTTP controller wraps in a JSON response; SSE handler
 * frames the result into `data:` events.
 *
 * The handler enforces:
 *   - JSON-RPC 2.0 envelope validation
 *   - method allowlist (initialize / tools/list / tools/call /
 *     resources/list / resources/read / prompts/list / prompts/get)
 *   - per-tool authorization via {@see McpToolAuthorizerContract}
 *   - error mapping to JSON-RPC spec codes (-32700 parse, -32600
 *     invalid request, -32601 method not found, -32602 invalid
 *     params, -32603 internal, -32001..-32099 server-defined)
 */
final class JsonRpcRequestHandler
{
    public function __construct(
        private readonly McpServerExposureContract $exposure,
        private readonly McpToolAuthorizerContract $authorizer,
    ) {}

    /**
     * @param  array<string,mixed> $context  Required keys: `tenant_id`, `actor` (any host-defined identifier).
     */
    public function handle(JsonRpcMessage $message, array $context = []): ?JsonRpcMessage
    {
        if ($message->isNotification()) {
            // No response for notifications. The MCP spec allows
            // clients to send `notifications/initialized` and similar;
            // the handler accepts and discards.
            return null;
        }

        if (! $message->isRequest()) {
            return JsonRpcMessage::errorResponse(
                $message->id ?? 0,
                -32600,
                'Invalid request: missing method or id.',
            );
        }

        $tenantId = isset($context['tenant_id']) ? (string) $context['tenant_id'] : null;
        $actor = $context['actor'] ?? null;

        try {
            $result = match ($message->method) {
                'initialize' => $this->onInitialize($message->params ?? []),
                'tools/list' => $this->onToolsList($tenantId, $actor),
                'tools/call' => $this->onToolsCall($message->params ?? [], $tenantId, $actor),
                'resources/list' => $this->onResourcesList($tenantId),
                'resources/read' => $this->onResourcesRead($message->params ?? [], $tenantId),
                'prompts/list' => $this->onPromptsList($tenantId),
                'prompts/get' => $this->onPromptsGet($message->params ?? [], $tenantId),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            return JsonRpcMessage::errorResponse($message->id, -32602, $e->getMessage());
        } catch (\DomainException $e) {
            // Authorization denial / not-found at the contract level.
            return JsonRpcMessage::errorResponse($message->id, -32001, $e->getMessage());
        } catch (\Throwable $e) {
            return JsonRpcMessage::errorResponse($message->id, -32603, "Internal server error: {$e->getMessage()}");
        }

        if ($result === null) {
            return JsonRpcMessage::errorResponse($message->id, -32601, "Method not found: {$message->method}");
        }

        return JsonRpcMessage::response($message->id, $result);
    }

    /** @param array<string,mixed> $params  @return array<string,mixed> */
    private function onInitialize(array $params): array
    {
        return [
            'protocolVersion' => $params['protocolVersion'] ?? '2025-03-26',
            'capabilities' => $this->exposure->capabilities(),
            'serverInfo' => $this->exposure->serverInfo(),
        ];
    }

    /** @return array<string,mixed> */
    private function onToolsList(?string $tenantId, mixed $actor): array
    {
        $tools = $this->exposure->tools($tenantId);
        $visible = [];
        foreach ($tools as $tool) {
            if ($this->authorizer->authorize($actor, $tenantId, $tool)) {
                $visible[] = [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'inputSchema' => $tool->schema(),
                ];
            }
        }
        return ['tools' => $visible];
    }

    /** @param array<string,mixed> $params  @return array<string,mixed> */
    private function onToolsCall(array $params, ?string $tenantId, mixed $actor): array
    {
        $name = (string) ($params['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('tools/call requires `name`.');
        }
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $tools = $this->exposure->tools($tenantId);
        foreach ($tools as $tool) {
            if ($tool->name() !== $name) {
                continue;
            }
            if (! $this->authorizer->authorize($actor, $tenantId, $tool)) {
                throw new \DomainException("Tool [{$name}] is not authorized for the current actor.");
            }
            $result = $tool->invoke($args);
            return ['content' => $this->normaliseToolResult($result)];
        }

        throw new \DomainException("Tool [{$name}] is not exposed for the current tenant.");
    }

    /** @return array<string,mixed> */
    private function onResourcesList(?string $tenantId): array
    {
        $resources = $this->exposure->resources($tenantId);
        return [
            'resources' => array_map(
                static fn($resource): array => [
                    'uri' => $resource->uri(),
                    'name' => $resource->name(),
                    'description' => $resource->description(),
                    'mimeType' => $resource->mimeType(),
                ],
                $resources,
            ),
        ];
    }

    /** @param array<string,mixed> $params  @return array<string,mixed> */
    private function onResourcesRead(array $params, ?string $tenantId): array
    {
        $uri = (string) ($params['uri'] ?? '');
        if ($uri === '') {
            throw new \InvalidArgumentException('resources/read requires `uri`.');
        }
        foreach ($this->exposure->resources($tenantId) as $resource) {
            if ($resource->uri() === $uri) {
                $payload = $resource->read();
                return [
                    'contents' => $this->normaliseResourceRead($payload, $uri, $resource->mimeType()),
                ];
            }
        }
        throw new \DomainException("Resource [{$uri}] not found.");
    }

    /** @return array<string,mixed> */
    private function onPromptsList(?string $tenantId): array
    {
        $prompts = $this->exposure->prompts($tenantId);
        return [
            'prompts' => array_map(
                static fn($prompt): array => [
                    'name' => $prompt->name(),
                    'description' => $prompt->description(),
                    'arguments' => $prompt->arguments(),
                ],
                $prompts,
            ),
        ];
    }

    /** @param array<string,mixed> $params  @return array<string,mixed> */
    private function onPromptsGet(array $params, ?string $tenantId): array
    {
        $name = (string) ($params['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('prompts/get requires `name`.');
        }
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        foreach ($this->exposure->prompts($tenantId) as $prompt) {
            if ($prompt->name() === $name) {
                $rendered = $prompt->render($args);
                // Allow flat-list shape per the contract; wrap if needed.
                if (array_is_list($rendered)) {
                    return ['messages' => $rendered];
                }
                if (! isset($rendered['messages'])) {
                    $rendered = ['messages' => array_values($rendered)];
                }
                return $rendered;
            }
        }
        throw new \DomainException("Prompt [{$name}] not found.");
    }

    /** @return array<int,array<string,mixed>> */
    private function normaliseToolResult(mixed $result): array
    {
        if (is_string($result)) {
            return [['type' => 'text', 'text' => $result]];
        }
        if (is_array($result) && array_is_list($result)) {
            return $result;
        }
        return [['type' => 'text', 'text' => (string) json_encode($result, JSON_UNESCAPED_UNICODE)]];
    }

    /**
     * @param string|array<int,array<string,mixed>> $payload
     * @return array<int,array<string,mixed>>
     */
    private function normaliseResourceRead(string|array $payload, string $uri, string $mimeType): array
    {
        if (is_string($payload)) {
            return [['uri' => $uri, 'mimeType' => $mimeType, 'text' => $payload]];
        }
        // Caller returned its own block list — backfill `uri` on any
        // block missing it so the client can always correlate content
        // back to the requested resource (MCP spec requires `uri` per
        // block in `resources/read` responses).
        return array_map(
            static function (array $block) use ($uri): array {
                if (! isset($block['uri']) || $block['uri'] === '') {
                    $block['uri'] = $uri;
                }
                return $block;
            },
            $payload,
        );
    }
}
