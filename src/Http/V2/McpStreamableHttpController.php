<?php

namespace Padosoft\AskMyDocsMcpPack\Http\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\AuthenticationResolverContract;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\SubscriptionBrokerContract;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ToolDefinition;
use Padosoft\AskMyDocsMcpPack\Fluent\McpManager;
use Padosoft\AskMyDocsMcpPack\Protocol\ErrorCode;
use Padosoft\AskMyDocsMcpPack\Protocol\ProtocolVersion;
use Padosoft\AskMyDocsMcpPack\ServerSide\V2JsonRpcRequestHandler;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class McpStreamableHttpController
{
    public function __construct(
        private readonly V2JsonRpcRequestHandler $handler,
        private readonly AuthenticationResolverContract $auth,
        private readonly McpManager $manager,
        private readonly SubscriptionBrokerContract $subscriptions,
    ) {}

    public function __invoke(Request $request): JsonResponse|StreamedResponse
    {
        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            return $this->error(null, -32600, 'Invalid request: empty JSON body.', 400);
        }
        try {
            $message = JsonRpcMessage::fromArray($payload);
        } catch (\Throwable) {
            return $this->error($payload['id'] ?? null, -32600, 'Invalid JSON-RPC request.', 400);
        }

        $version = $request->header('MCP-Protocol-Version');
        if ($version !== ProtocolVersion::V2) {
            return $this->error($message->id, ErrorCode::PROTOCOL_VERSION_UNSUPPORTED, 'Unsupported or missing MCP-Protocol-Version header.', 400, ['supported' => [ProtocolVersion::V2], 'received' => $version]);
        }
        $method = $request->header('Mcp-Method');
        if (! is_string($method) || $method !== $message->method) {
            return $this->error($message->id, ErrorCode::HEADER_MISMATCH, 'Mcp-Method header does not match the JSON-RPC method.', 400);
        }
        $nameError = $this->validateNameHeader($request, $message);
        if ($nameError !== null) {
            return $nameError;
        }

        $serverId = (string) ($request->route('mcp_server') ?? config('mcp-pack.v2.default_server', 'default'));
        $identity = $this->auth->resolve($request);
        try {
            $message = $this->applyHeaderParameters($request, $message, $serverId, $identity);
        } catch (\Throwable $e) {
            return $this->error($message->id, ErrorCode::HEADER_MISMATCH, $e->getMessage(), 400);
        }
        $context = $identity + [
            'server_id' => $serverId,
            'correlation_id' => $request->header('X-Correlation-ID') ?: null,
            'traceparent' => $this->traceHeader($request->header('traceparent'), '/^[\da-f]{2}-[\da-f]{32}-[\da-f]{16}-[\da-f]{2}$/i', 55),
            'tracestate' => $this->traceHeader($request->header('tracestate'), '/^[\x20-\x7E]*$/', 512),
            'baggage' => $this->traceHeader($request->header('baggage'), '/^[\x20-\x7E]*$/', 8192),
        ];
        if ($request->accepts('text/event-stream')) {
            $isSubscription = $message->method === 'subscriptions/listen';

            return new StreamedResponse(function () use ($message, $context, $isSubscription, $identity): void {
                $context['stream_emitter'] = function (string $topic, array $payload): void {
                    $this->emitSse($topic, json_encode([
                        'jsonrpc' => '2.0', 'method' => $topic, 'params' => $payload,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    $this->flushStream();
                };
                $response = $this->handler->handle($message, $context);
                if ($response !== null) {
                    $this->emitSse('message', $response->toJson());
                    $this->flushStream();
                }
                if (! $isSubscription || $response === null) {
                    return;
                }
                $last = is_string($response->result['lastEventId'] ?? null) ? $response->result['lastEventId'] : null;
                $deadline = microtime(true) + max(1, (int) config('mcp-pack.subscriptions.stream_seconds', 30));
                $pollUs = max(50, (int) config('mcp-pack.subscriptions.poll_ms', 250)) * 1000;
                while (! connection_aborted() && microtime(true) < $deadline) {
                    usleep($pollUs);
                    $events = $this->subscriptions->listen($identity['tenant_id'] ?? null, $last, 100, $identity['principal_id'] ?? null);
                    foreach ($events as $event) {
                        $this->emitSse((string) $event['topic'], json_encode(['jsonrpc' => '2.0', 'method' => $event['topic'], 'params' => $event['payload']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string) $event['id']);
                        $last = (string) $event['id'];
                    }
                    if ($events === []) {
                        echo ": keepalive\n\n";
                    }
                    $this->flushStream();
                }
            }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-store', 'X-Accel-Buffering' => 'no']);
        }

        $response = $this->handler->handle($message, $context);
        if ($response === null) {
            return new JsonResponse(null, 202);
        }

        return new JsonResponse($response->toArray(), $this->statusFor($response), ['Cache-Control' => 'no-store']);
    }

    private function validateNameHeader(Request $request, JsonRpcMessage $message): ?JsonResponse
    {
        if (! in_array($message->method, ['tools/call', 'prompts/get'], true)) {
            return null;
        }
        $name = $message->params['name'] ?? null;
        $header = $request->header('Mcp-Name');
        if (! is_string($name) || $name === '' || ! is_string($header) || ! hash_equals($name, $header)) {
            return $this->error($message->id, ErrorCode::HEADER_MISMATCH, 'Mcp-Name header does not match params.name.', 400);
        }

        return null;
    }

    /** @param array<string,mixed> $identity */
    private function applyHeaderParameters(Request $request, JsonRpcMessage $message, string $serverId, array $identity): JsonRpcMessage
    {
        if ($message->method !== 'tools/call') {
            return $message;
        }
        $server = $this->manager->find($serverId);
        if ($server === null) {
            return $message;
        }
        $catalog = $server->forTenant($identity['tenant_id'] ?? null, $identity['principal_id'] ?? null);
        $tool = $catalog->find('tools', (string) ($message->params['name'] ?? ''));
        if (! $tool instanceof ToolDefinition) {
            return $message;
        }
        $params = $message->params ?? [];
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        foreach ((array) ($tool->inputSchema['properties'] ?? []) as $property => $schema) {
            if (! is_array($schema) || ! ($schema['x-mcp-header'] ?? false)) {
                continue;
            }
            $headerName = is_string($schema['x-mcp-header']) ? $schema['x-mcp-header'] : 'Mcp-Param-'.str_replace('_', '-', (string) $property);
            $value = $request->header($headerName);
            if (! is_string($value)) {
                continue;
            }
            $coerced = $this->coerce($value, (string) ($schema['type'] ?? 'string'));
            if (array_key_exists($property, $arguments) && $arguments[$property] !== $coerced) {
                throw new \InvalidArgumentException("Header [{$headerName}] does not match body argument [{$property}].");
            }
            $arguments[$property] = $coerced;
        }
        $params['arguments'] = $arguments;

        return JsonRpcMessage::request($message->id, (string) $message->method, $params);
    }

    private function coerce(string $value, string $type): mixed
    {
        return match ($type) {
            'integer' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException('Invalid integer MCP header parameter.'),
            'number' => is_numeric($value) ? (float) $value : throw new \InvalidArgumentException('Invalid numeric MCP header parameter.'),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? throw new \InvalidArgumentException('Invalid boolean MCP header parameter.'),
            'array', 'object' => json_decode($value, true, 32, JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    private function traceHeader(?string $value, string $pattern, int $maxLength): ?string
    {
        if ($value === null || strlen($value) > $maxLength || preg_match($pattern, $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function emitSse(string $event, string $data, ?string $id = null): void
    {
        if ($id !== null) {
            echo 'id: '.str_replace(["\r", "\n"], '', $id)."\n";
        }
        echo 'event: '.str_replace(["\r", "\n"], '', $event)."\n";
        foreach (preg_split('/\r?\n/', $data) ?: [] as $line) {
            echo 'data: '.$line."\n";
        }
        echo "\n";
    }

    private function flushStream(): void
    {
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    private function statusFor(JsonRpcMessage $response): int
    {
        if (! $response->isError()) {
            return 200;
        }

        return match ((int) ($response->error['code'] ?? 0)) {
            -32603 => 500,
            -32601 => 404,
            default => 400,
        };
    }

    /** @param array<string,mixed>|null $data */
    private function error(string|int|null $id, int $code, string $message, int $status, ?array $data = null): JsonResponse
    {
        return new JsonResponse(JsonRpcMessage::errorResponse($id, $code, $message, $data)->toArray(), $status, ['Cache-Control' => 'no-store']);
    }
}
