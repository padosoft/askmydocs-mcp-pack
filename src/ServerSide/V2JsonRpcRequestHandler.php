<?php

namespace Padosoft\AskMyDocsMcpPack\ServerSide;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsMcpPack\Apps\AppRenderer;
use Padosoft\AskMyDocsMcpPack\Contracts\JsonRpcRequestHandlerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\ArtifactManagerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\CancellationRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\DefinitionContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\SubscriptionBrokerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\TaskManagerContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpProtocolException;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\AppDefinition;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\PromptDefinition;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ResourceDefinition;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ResourceTemplateDefinition;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ToolDefinition;
use Padosoft\AskMyDocsMcpPack\Fluent\McpManager;
use Padosoft\AskMyDocsMcpPack\Protocol\CursorCodec;
use Padosoft\AskMyDocsMcpPack\Protocol\ErrorCode;
use Padosoft\AskMyDocsMcpPack\Protocol\HandlerInvoker;
use Padosoft\AskMyDocsMcpPack\Protocol\McpRequest;
use Padosoft\AskMyDocsMcpPack\Protocol\McpResult;
use Padosoft\AskMyDocsMcpPack\Protocol\ProtocolVersion;
use Padosoft\AskMyDocsMcpPack\Protocol\RequestSignals;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Validation\JsonSchemaValidator;

final class V2JsonRpcRequestHandler implements JsonRpcRequestHandlerContract
{
    public function __construct(
        private readonly McpManager $manager,
        private readonly HandlerInvoker $invoker,
        private readonly JsonSchemaValidator $validator,
        private readonly CursorCodec $cursors,
        private readonly SubscriptionBrokerContract $subscriptions,
        private readonly AppRenderer $apps,
        private readonly CancellationRegistryContract $cancellations,
        private readonly ?TaskManagerContract $tasks = null,
        private readonly ?ArtifactManagerContract $artifacts = null,
    ) {}

    /** @param array<string,mixed> $context */
    public function handle(JsonRpcMessage $message, array $context = []): ?JsonRpcMessage
    {
        if ($message->isNotification()) {
            if ($message->method === 'notifications/cancelled') {
                $this->subscriptions->publish($context['tenant_id'] ?? null, 'notifications/cancelled', (array) ($message->params ?? []), $context['principal_id'] ?? null);
                $requestId = $message->params['requestId'] ?? $message->params['id'] ?? null;
                if (is_scalar($requestId)) {
                    $this->cancellations->cancel($context['tenant_id'] ?? null, (string) $requestId, $context['principal_id'] ?? null);
                }
            }

            return null;
        }
        if (! $message->isRequest()) {
            return JsonRpcMessage::errorResponse($message->id, -32600, 'Invalid request: missing method or id.');
        }

        $correlationId = (string) ($context['correlation_id'] ?? Str::uuid());
        try {
            $meta = $this->validateMeta($message->params ?? []);
            $serverId = (string) ($context['server_id'] ?? config('mcp-pack.v2.default_server', 'default'));
            $server = $this->manager->resolveLocal($serverId) ?? $this->manager->require($serverId);
            // The catalog itself keeps the principal in scope only for private-cache
            // servers (see ServerCatalog::forTenant()), so pass it through unchanged.
            $principal = $context['principal_id'] ?? null;
            $catalog = $server->forTenant($context['tenant_id'] ?? null, is_string($principal) ? $principal : null);
            $request = $this->makeRequest($message, $meta, $context, $correlationId);

            $result = match ($message->method) {
                'server/discover' => $this->discover($server, $catalog, $request),
                'tools/list' => $this->list($catalog->snapshot(), 'tools', $message->params ?? [], $server->server->serverInfo(), $server->server->ttlMs, $server->server->cacheScope->value, $request),
                'tools/call' => $this->callTool($catalog->find('tools', (string) ($request->arguments['name'] ?? '')), $request, $server->server->serverInfo()),
                'resources/list' => $this->listResources($catalog->snapshot(), $message->params ?? [], $server->server->serverInfo(), $server->server->ttlMs, $server->server->cacheScope->value, $request),
                'resources/read' => $this->readResource($catalog, $request, $server->server->serverInfo()),
                'resources/templates/list' => $this->list($catalog->snapshot(), 'resourceTemplates', $message->params ?? [], $server->server->serverInfo(), $server->server->ttlMs, $server->server->cacheScope->value),
                'prompts/list' => $this->list($catalog->snapshot(), 'prompts', $message->params ?? [], $server->server->serverInfo(), $server->server->ttlMs, $server->server->cacheScope->value),
                'prompts/get' => $this->getPrompt($catalog->find('prompts', (string) ($request->arguments['name'] ?? '')), $request, $server->server->serverInfo()),
                'completion/complete' => $this->complete($catalog->snapshot(), $request, $server->server->serverInfo()),
                'subscriptions/listen' => $this->listen($request, $server->server->serverInfo()),
                'tasks/get' => $this->getTask($request, $server->server->serverInfo()),
                'tasks/update' => $this->updateTask($request, $server->server->serverInfo()),
                'tasks/cancel' => $this->cancelTask($request, $server->server->serverInfo()),
                default => throw new McpProtocolException(-32601, "Method not found: {$message->method}"),
            };
            if (in_array($message->method, ['server/discover', 'resources/read'], true)) {
                $result['ttlMs'] ??= $server->server->ttlMs;
                $result['cacheScope'] ??= $server->server->cacheScope->value;
            }

            return JsonRpcMessage::response($message->id, $result);
        } catch (McpProtocolException $e) {
            return JsonRpcMessage::errorResponse($message->id, $e->rpcCode, $e->getMessage(), $e->data);
        } catch (\InvalidArgumentException $e) {
            return JsonRpcMessage::errorResponse($message->id, -32602, $e->getMessage());
        } catch (\Throwable $e) {
            report(new \RuntimeException("MCP v2 request failed [correlation_id={$correlationId}].", previous: $e));

            return JsonRpcMessage::errorResponse($message->id, -32603, 'Internal server error.', ['correlationId' => $correlationId]);
        }
    }

    /** @param array<string,mixed> $params @return array{protocolVersion:string,clientCapabilities:array<string,mixed>,clientInfo:array<string,mixed>,raw:array<string,mixed>} */
    private function validateMeta(array $params): array
    {
        $meta = $params['_meta'] ?? null;
        if (! is_array($meta)) {
            throw new McpProtocolException(ErrorCode::PROTOCOL_VERSION_UNSUPPORTED, 'Every MCP 2026-07-28 request requires params._meta.');
        }
        $version = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;
        if ($version !== ProtocolVersion::V2) {
            throw new McpProtocolException(ErrorCode::PROTOCOL_VERSION_UNSUPPORTED, 'Unsupported MCP protocol version.', ['supported' => [ProtocolVersion::V2], 'received' => $version]);
        }
        $capabilities = $meta['io.modelcontextprotocol/clientCapabilities'] ?? null;
        if (! is_array($capabilities)) {
            throw new McpProtocolException(ErrorCode::CAPABILITY_MISSING, 'Client capabilities are required on every request.');
        }
        $clientInfo = $params['clientInfo'] ?? $meta['io.modelcontextprotocol/clientInfo'] ?? $meta['clientInfo'] ?? [];

        return ['protocolVersion' => $version, 'clientCapabilities' => $capabilities, 'clientInfo' => is_array($clientInfo) ? $clientInfo : [], 'raw' => $meta];
    }

    /** @param array<string,mixed> $meta @param array<string,mixed> $context */
    private function makeRequest(JsonRpcMessage $message, array $meta, array $context, string $correlationId): McpRequest
    {
        $arguments = $message->params ?? [];
        unset($arguments['_meta'], $arguments['clientInfo'], $arguments['inputResponses'], $arguments['requestState']);

        return new McpRequest(
            method: (string) $message->method,
            arguments: $arguments,
            tenantId: isset($context['tenant_id']) ? (string) $context['tenant_id'] : null,
            actor: $context['actor'] ?? null,
            principalId: isset($context['principal_id']) ? (string) $context['principal_id'] : null,
            clientInfo: $meta['clientInfo'],
            clientCapabilities: $meta['clientCapabilities'],
            meta: $meta['raw'],
            inputResponses: is_array($message->params['inputResponses'] ?? null) ? $message->params['inputResponses'] : [],
            requestState: is_string($message->params['requestState'] ?? null) ? $message->params['requestState'] : null,
            correlationId: $correlationId,
            traceparent: is_string($context['traceparent'] ?? null) ? $context['traceparent'] : null,
            tracestate: is_string($context['tracestate'] ?? null) ? $context['tracestate'] : null,
            baggage: is_string($context['baggage'] ?? null) ? $context['baggage'] : null,
            signals: new RequestSignals(
                $this->subscriptions,
                $this->cancellations,
                isset($context['tenant_id']) ? (string) $context['tenant_id'] : null,
                isset($context['principal_id']) ? (string) $context['principal_id'] : null,
                (string) $message->id,
                ($context['stream_emitter'] ?? null) instanceof \Closure ? $context['stream_emitter'] : null,
            ),
        );
    }

    private function discover(object $server, object $catalog, McpRequest $request): array
    {
        $snapshot = $catalog->snapshot();
        $capabilities = [
            'tools' => ['listChanged' => true],
            'resources' => ['listChanged' => true, 'subscribe' => true],
            'prompts' => ['listChanged' => true],
            'completion' => new \stdClass,
            'subscriptions' => new \stdClass,
            'elicitation' => new \stdClass,
        ];
        if ((bool) config('mcp-pack.apps.enabled', true) && $snapshot['definitions']['apps'] !== []) {
            $capabilities['extensions']['io.modelcontextprotocol/ui'] = ['version' => '2026-01-26'];
        }
        $methods = ['server/discover', 'tools/list', 'tools/call', 'resources/list', 'resources/read', 'resources/templates/list', 'prompts/list', 'prompts/get', 'completion/complete', 'subscriptions/listen'];
        if ($this->tasksOperational()) {
            $capabilities['extensions']['io.modelcontextprotocol/tasks'] = ['methods' => ['tasks/get', 'tasks/update', 'tasks/cancel']];
            array_push($methods, 'tasks/get', 'tasks/update', 'tasks/cancel');
        }

        return [
            'resultType' => 'complete',
            'protocolVersion' => ProtocolVersion::V2,
            'serverInfo' => $server->server->serverInfo(),
            'capabilities' => $capabilities,
            'methods' => $methods,
            'catalogRevision' => $snapshot['revision'],
            'catalogDigest' => $snapshot['digest'],
        ];
    }

    /** @param array{revision:int,digest:string,definitions:array<string,list<array<string,mixed>>>} $snapshot @param array<string,mixed> $params @param array<string,mixed> $serverInfo */
    private function list(array $snapshot, string $kind, array $params, array $serverInfo, int $ttlMs, string $cacheScope, ?McpRequest $request = null): array
    {
        $items = $snapshot['definitions'][$kind] ?? [];
        if ($kind === 'tools' && $request !== null && (! (bool) config('mcp-pack.apps.enabled', true) || ! $request->supports('io.modelcontextprotocol/ui'))) {
            $items = array_map(static function (array $item): array {
                unset($item['_meta']['ui'], $item['_meta']['openai/outputTemplate']);
                if (($item['_meta'] ?? []) === []) {
                    unset($item['_meta']);
                }

                return $item;
            }, $items);
        }
        [$page, $next] = $this->page($items, $params, $snapshot['digest']);
        $key = match ($kind) {
            'resourceTemplates' => 'resourceTemplates', default => $kind
        };

        return array_filter([
            'resultType' => 'complete', 'serverInfo' => $serverInfo, $key => $page,
            'nextCursor' => $next, 'ttlMs' => $ttlMs, 'cacheScope' => $cacheScope,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array{revision:int,digest:string,definitions:array<string,list<array<string,mixed>>>} $snapshot @param array<string,mixed> $params @param array<string,mixed> $serverInfo */
    private function listResources(array $snapshot, array $params, array $serverInfo, int $ttlMs, string $cacheScope, McpRequest $request): array
    {
        $resources = $snapshot['definitions']['resources'];
        if ((bool) config('mcp-pack.apps.enabled', true) && $request->supports('io.modelcontextprotocol/ui')) {
            foreach ($snapshot['definitions']['apps'] as $app) {
                $resources[] = ['uri' => $app['resourceUri'], 'name' => $app['name'], 'mimeType' => 'text/html;profile=mcp-app', '_meta' => $app['_meta'] ?? []];
            }
        }
        usort($resources, static fn (array $a, array $b): int => strcmp((string) ($a['uri'] ?? ''), (string) ($b['uri'] ?? '')));
        [$page, $next] = $this->page($resources, $params, $snapshot['digest']);

        return array_filter(['resultType' => 'complete', 'serverInfo' => $serverInfo, 'resources' => $page, 'nextCursor' => $next, 'ttlMs' => $ttlMs, 'cacheScope' => $cacheScope], static fn (mixed $value): bool => $value !== null);
    }

    /** @param list<array<string,mixed>> $items @param array<string,mixed> $params @return array{0:list<array<string,mixed>>,1:?string} */
    private function page(array $items, array $params, string $digest): array
    {
        $limit = max(1, min((int) ($params['limit'] ?? config('mcp-pack.v2.pagination.default_limit', 100)), (int) config('mcp-pack.v2.pagination.max_limit', 500)));
        $offset = 0;
        if (is_string($params['cursor'] ?? null)) {
            $cursor = $this->cursors->decode($params['cursor']);
            if (! hash_equals($digest, $cursor['digest'])) {
                throw new \InvalidArgumentException('Cursor belongs to a stale catalog snapshot.');
            }
            $offset = $cursor['offset'];
        }
        $page = array_slice($items, $offset, $limit);
        $nextOffset = $offset + count($page);

        return [$page, $nextOffset < count($items) ? $this->cursors->encode($nextOffset, $digest) : null];
    }

    /** @param array<string,mixed> $serverInfo */
    private function callTool(?DefinitionContract $definition, McpRequest $request, array $serverInfo): array
    {
        if (! $definition instanceof ToolDefinition) {
            return McpResult::recoverableError('The requested tool is not available.')->toArray($serverInfo);
        }
        $arguments = is_array($request->arguments['arguments'] ?? null) ? $request->arguments['arguments'] : [];
        try {
            $this->validator->validate($definition->inputSchema, $arguments);
        } catch (\InvalidArgumentException $e) {
            return McpResult::recoverableError($e->getMessage())->toArray($serverInfo);
        }
        $toolRequest = new McpRequest($request->method, $arguments, $request->tenantId, $request->actor, $request->principalId, $request->clientInfo, $request->clientCapabilities, $request->meta, $request->inputResponses, $request->requestState, $request->correlationId, $request->traceparent, $request->tracestate, $request->baggage, $request->signals);
        if ($definition->asynchronous) {
            $this->requireTasks($request);
            if (! is_string($definition->handler)) {
                throw new McpProtocolException(-32603, 'Asynchronous handlers must be serializable class strings.');
            }

            return McpResult::make()->task($this->tasks->create($definition->handler, $toolRequest, outputSchema: $definition->outputSchema))->toArray($serverInfo);
        }
        try {
            $result = McpResult::normalise($this->invoker->invoke($definition->handler, $toolRequest), $serverInfo);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return McpResult::recoverableError($e->getMessage())->toArray($serverInfo);
        }
        if ($definition->outputSchema !== null && is_array($result['structuredContent'] ?? null)) {
            try {
                $this->validator->validate($definition->outputSchema, $result['structuredContent']);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException("Tool [{$definition->name}] returned structured content that violates its output schema.", previous: $e);
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $serverInfo */
    private function readResource(object $catalog, McpRequest $request, array $serverInfo): array
    {
        $uri = (string) ($request->arguments['uri'] ?? '');
        if ($uri === '') {
            throw new \InvalidArgumentException('resources/read requires uri.');
        }
        if (str_starts_with($uri, 'artifact://')) {
            return $this->readArtifact(substr($uri, 11), $request, $serverInfo);
        }
        $definition = $catalog->find('resources', $uri);
        if ($definition instanceof ResourceDefinition) {
            return $this->resourceResult($uri, $definition->mimeType, $this->invoker->invoke($definition->handler, $request), $serverInfo);
        }
        foreach ($catalog->all('apps') as $app) {
            if ($app instanceof AppDefinition && $app->resourceUri === $uri) {
                if (! (bool) config('mcp-pack.apps.enabled', true)) {
                    return McpResult::recoverableError('MCP Apps are disabled.')->toArray($serverInfo);
                }
                if (! $request->supports('io.modelcontextprotocol/ui')) {
                    throw new McpProtocolException(ErrorCode::CAPABILITY_MISSING, 'The client did not declare the MCP UI capability.');
                }

                return ['resultType' => 'complete', 'serverInfo' => $serverInfo, 'contents' => [[
                    'uri' => $uri, 'mimeType' => 'text/html;profile=mcp-app', 'text' => $this->apps->render($app),
                    '_meta' => $app->toArray()['_meta'],
                ]]];
            }
        }
        foreach ($catalog->all('resourceTemplates') as $template) {
            if ($template instanceof ResourceTemplateDefinition && $this->matchesTemplate($template->uriTemplate, $uri)) {
                return $this->resourceResult($uri, $template->mimeType, $this->invoker->invoke($template->handler, $request), $serverInfo);
            }
        }

        return McpResult::recoverableError('Resource not found.')->toArray($serverInfo);
    }

    /** @param array<string,mixed> $serverInfo */
    private function resourceResult(string $uri, string $mimeType, mixed $value, array $serverInfo): array
    {
        if ($value instanceof McpResult) {
            return $value->toArray($serverInfo);
        }
        if (is_array($value) && isset($value['contents'])) {
            return ['resultType' => 'complete', 'serverInfo' => $serverInfo] + $value;
        }
        $block = ['uri' => $uri, 'mimeType' => $mimeType];
        if (is_string($value)) {
            $block['text'] = $value;
        } else {
            $block['text'] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return ['resultType' => 'complete', 'serverInfo' => $serverInfo, 'contents' => [$block]];
    }

    /** @param array<string,mixed> $serverInfo */
    private function getPrompt(?DefinitionContract $definition, McpRequest $request, array $serverInfo): array
    {
        if (! $definition instanceof PromptDefinition) {
            return McpResult::recoverableError('Prompt not found.')->toArray($serverInfo);
        }
        $arguments = is_array($request->arguments['arguments'] ?? null) ? $request->arguments['arguments'] : [];
        $promptRequest = new McpRequest($request->method, $arguments, $request->tenantId, $request->actor, $request->principalId, $request->clientInfo, $request->clientCapabilities, $request->meta, $request->inputResponses, $request->requestState, $request->correlationId, $request->traceparent, $request->tracestate, $request->baggage, $request->signals);
        $value = $this->invoker->invoke($definition->handler, $promptRequest);
        if ($value instanceof McpResult) {
            return $value->toArray($serverInfo);
        }
        if (! is_array($value)) {
            throw new \DomainException('Prompt handlers must return a messages array or McpResult.');
        }
        if (array_is_list($value)) {
            $value = ['messages' => $value];
        }

        return ['resultType' => 'complete', 'serverInfo' => $serverInfo] + $value;
    }

    /** @param array{revision:int,digest:string,definitions:array<string,list<array<string,mixed>>>} $snapshot @param array<string,mixed> $serverInfo */
    private function complete(array $snapshot, McpRequest $request, array $serverInfo): array
    {
        $ref = $request->arguments['ref'] ?? [];
        $value = strtolower((string) ($request->arguments['argument']['value'] ?? ''));
        $kind = ($ref['type'] ?? null) === 'ref/prompt' ? 'prompts' : 'resourceTemplates';
        $key = $kind === 'prompts' ? 'name' : 'uriTemplate';
        $values = array_values(array_filter(array_map(static fn (array $item): string => (string) ($item[$key] ?? ''), $snapshot['definitions'][$kind]), static fn (string $item): bool => $value === '' || str_contains(strtolower($item), $value)));

        return ['resultType' => 'complete', 'serverInfo' => $serverInfo, 'completion' => ['values' => array_slice($values, 0, 100), 'total' => count($values), 'hasMore' => count($values) > 100]];
    }

    /** @param array<string,mixed> $serverInfo */
    private function listen(McpRequest $request, array $serverInfo): array
    {
        $after = is_string($request->arguments['after'] ?? null) ? $request->arguments['after'] : null;
        $events = $this->subscriptions->listen($request->tenantId, $after, (int) ($request->arguments['limit'] ?? 100), $request->principalId);

        return ['resultType' => 'complete', 'serverInfo' => $serverInfo, 'events' => $events, 'lastEventId' => $events === [] ? $after : $events[array_key_last($events)]['id']];
    }

    /** @param array<string,mixed> $serverInfo */
    private function getTask(McpRequest $request, array $serverInfo): array
    {
        $this->requireTasks($request);
        try {
            $task = $this->tasks->get((string) ($request->arguments['taskId'] ?? ''), $request->tenantId, $request->actorId());
        } catch (ModelNotFoundException) {
            return McpResult::recoverableError('Task not found or no longer available.')->toArray($serverInfo);
        }

        return ['resultType' => 'complete', 'serverInfo' => $serverInfo] + $task->toProtocolArray();
    }

    /** @param array<string,mixed> $serverInfo */
    private function updateTask(McpRequest $request, array $serverInfo): array
    {
        $this->requireTasks($request);
        try {
            $task = $this->tasks->update((string) ($request->arguments['taskId'] ?? ''), $request->inputResponses, $request->tenantId, $request->actorId(), $request->requestState);
        } catch (ModelNotFoundException) {
            return McpResult::recoverableError('Task not found or no longer available.')->toArray($serverInfo);
        } catch (\DomainException $e) {
            return McpResult::recoverableError($e->getMessage())->toArray($serverInfo);
        }

        return ['resultType' => 'complete', 'serverInfo' => $serverInfo];
    }

    /** @param array<string,mixed> $serverInfo */
    private function cancelTask(McpRequest $request, array $serverInfo): array
    {
        $this->requireTasks($request);
        try {
            $task = $this->tasks->cancel((string) ($request->arguments['taskId'] ?? ''), $request->tenantId, $request->actorId());
        } catch (ModelNotFoundException) {
            return McpResult::recoverableError('Task not found or no longer available.')->toArray($serverInfo);
        }

        return ['resultType' => 'complete', 'serverInfo' => $serverInfo];
    }

    private function requireTasks(McpRequest $request): void
    {
        if (! $this->tasksOperational()) {
            throw new McpProtocolException(ErrorCode::CAPABILITY_MISSING, 'Tasks are not operational on this server.');
        }
        if (! $request->supports('io.modelcontextprotocol/tasks')) {
            throw new McpProtocolException(ErrorCode::CAPABILITY_MISSING, 'The client did not declare the Tasks capability.');
        }
    }

    private function tasksOperational(): bool
    {
        return (bool) config('mcp-pack.tasks.enabled', false) && $this->tasks !== null && $this->tasks->operational();
    }

    /** @param array<string,mixed> $serverInfo */
    private function readArtifact(string $uuid, McpRequest $request, array $serverInfo): array
    {
        if (! (bool) config('mcp-pack.artifacts.enabled', true) || $this->artifacts === null) {
            return McpResult::recoverableError('Artifacts are disabled.')->toArray($serverInfo);
        }
        try {
            $artifact = $this->artifacts->read($uuid, $request->tenantId, $request->actorId());
        } catch (ModelNotFoundException) {
            return McpResult::recoverableError('Artifact not found or no longer available.')->toArray($serverInfo);
        }
        $contents = $this->artifacts->contents($artifact);
        $block = ['uri' => 'artifact://'.$uuid, 'name' => $artifact->name, 'mimeType' => $artifact->mime_type];
        if (str_starts_with((string) $artifact->mime_type, 'text/') || $artifact->mime_type === 'application/json') {
            $block['text'] = $contents;
        } else {
            $block['blob'] = base64_encode($contents);
        }

        return ['resultType' => 'complete', 'serverInfo' => $serverInfo, 'contents' => [$block]];
    }

    private function matchesTemplate(string $template, string $uri): bool
    {
        $pattern = preg_quote($template, '#');
        $pattern = preg_replace('/\\\{[^}]+\\\}/', '[^/]+', $pattern) ?? $pattern;

        return preg_match('#^'.$pattern.'$#', $uri) === 1;
    }
}
