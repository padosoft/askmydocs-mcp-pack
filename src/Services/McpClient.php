<?php

namespace Padosoft\AskMyDocsMcpPack\Services;

use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Transports\HttpJsonRpcTransport;
use Padosoft\AskMyDocsMcpPack\Transports\StdioJsonRpcTransport;

/**
 * Thin protocol-aware wrapper around a {@see McpTransportContract}.
 *
 * Knows the 3 JSON-RPC methods this pack uses end-to-end:
 *   - `initialize`            — handshake at session start
 *   - `tools/list`            — enumerate the server's tool catalog
 *   - `tools/call`            — invoke a tool by name with arguments
 *
 * Higher-level orchestration (multi-turn loop, audit trail, retries)
 * lives in {@see McpToolCallingService}.
 */
class McpClient
{
    public function __construct(
        protected readonly McpServerContract $server,
        protected readonly McpTransportContract $transport,
    ) {}

    /**
     * Resolver hook — tests swap this to inject a stub transport.
     * @var (\Closure(McpServerContract):McpTransportContract)|null
     */
    private static ?\Closure $transportResolver = null;

    public static function forServer(McpServerContract $server): self
    {
        $transport = self::$transportResolver !== null
            ? (self::$transportResolver)($server)
            : self::transportFor($server);

        return new self($server, $transport);
    }

    public static function useTransportResolver(?\Closure $resolver): void
    {
        self::$transportResolver = $resolver;
    }

    /** @return array<string,mixed> */
    public function initialize(?string $protocolVersion = null): array
    {
        $request = JsonRpcMessage::request(
            id: self::newId(),
            method: 'initialize',
            params: [
                'protocolVersion' => $protocolVersion ?? '2025-03-26',
                'capabilities' => ['tools' => new \stdClass()],
                'clientInfo' => [
                    'name' => 'padosoft/askmydocs-mcp-pack',
                    'version' => '1.0.0',
                ],
            ],
        );

        return $this->call($request);
    }

    /** @return array<int,array<string,mixed>> */
    public function listTools(): array
    {
        $request = JsonRpcMessage::request(self::newId(), 'tools/list');
        $payload = $this->call($request);

        $tools = $payload['tools'] ?? [];
        if (! is_array($tools)) {
            return [];
        }

        return array_values(array_filter(
            $tools,
            static fn($tool): bool => is_array($tool) && isset($tool['name']),
        ));
    }

    /**
     * @param  array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function callTool(string $toolName, array $arguments): array
    {
        $request = JsonRpcMessage::request(
            id: self::newId(),
            method: 'tools/call',
            params: ['name' => $toolName, 'arguments' => $arguments],
        );

        return $this->call($request);
    }

    /**
     * v1.1.0 — MCP `resources/list`. Returns one page of the upstream
     * server's catalog of readable resources, plus the `nextCursor`
     * the spec mandates for paging.
     *
     * @return array{resources:array<int,array<string,mixed>>,nextCursor:?string}
     */
    public function listResources(?string $cursor = null): array
    {
        $params = $cursor !== null ? ['cursor' => $cursor] : null;
        $request = JsonRpcMessage::request(self::newId(), 'resources/list', $params);
        $payload = $this->call($request);

        $resources = $payload['resources'] ?? [];
        if (! is_array($resources)) {
            $resources = [];
        }
        $resources = array_values(array_filter(
            $resources,
            static fn($resource): bool => is_array($resource) && isset($resource['uri']),
        ));

        $nextCursor = $payload['nextCursor'] ?? null;
        return [
            'resources' => $resources,
            'nextCursor' => is_string($nextCursor) ? $nextCursor : null,
        ];
    }

    /**
     * Eager helper that drains every page of `resources/list` into a
     * flat list. Useful for hosts that always want the full catalog
     * (admin SPA, regression tests). Production hosts should prefer
     * {@see listResources()} with explicit cursor handling so they
     * can stream large catalogs without buffering.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listAllResources(): array
    {
        $all = [];
        $cursor = null;
        do {
            $page = $this->listResources($cursor);
            foreach ($page['resources'] as $resource) {
                $all[] = $resource;
            }
            $cursor = $page['nextCursor'];
        } while ($cursor !== null);

        return $all;
    }

    /**
     * v1.1.0 — MCP `resources/read`. Returns the FULL `resources/read`
     * result envelope verbatim — typically `{contents: [{type, text|blob, mimeType?}, …]}`.
     * Callers that only want the first text block can read
     * `$envelope['contents'][0]['text']`.
     *
     * @return array<string,mixed>
     */
    public function readResource(string $uri): array
    {
        $request = JsonRpcMessage::request(
            id: self::newId(),
            method: 'resources/read',
            params: ['uri' => $uri],
        );

        return $this->call($request);
    }

    /**
     * v1.1.0 — MCP `prompts/list`. Returns one page of the upstream
     * server's catalog of named prompt templates plus `nextCursor`.
     *
     * @return array{prompts:array<int,array<string,mixed>>,nextCursor:?string}
     */
    public function listPrompts(?string $cursor = null): array
    {
        $params = $cursor !== null ? ['cursor' => $cursor] : null;
        $request = JsonRpcMessage::request(self::newId(), 'prompts/list', $params);
        $payload = $this->call($request);

        $prompts = $payload['prompts'] ?? [];
        if (! is_array($prompts)) {
            $prompts = [];
        }
        $prompts = array_values(array_filter(
            $prompts,
            static fn($prompt): bool => is_array($prompt) && isset($prompt['name']),
        ));

        $nextCursor = $payload['nextCursor'] ?? null;
        return [
            'prompts' => $prompts,
            'nextCursor' => is_string($nextCursor) ? $nextCursor : null,
        ];
    }

    /**
     * Eager helper draining every page of `prompts/list`. See
     * {@see listAllResources()} for the equivalent rationale.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listAllPrompts(): array
    {
        $all = [];
        $cursor = null;
        do {
            $page = $this->listPrompts($cursor);
            foreach ($page['prompts'] as $prompt) {
                $all[] = $prompt;
            }
            $cursor = $page['nextCursor'];
        } while ($cursor !== null);

        return $all;
    }

    /**
     * v1.1.0 — MCP `prompts/get`. Renders a named prompt with the
     * supplied arguments. Result shape: `{description?, messages:[…]}`.
     *
     * Empty `$arguments` is encoded as a JSON object (`{}`), NOT a
     * JSON array (`[]`), because the MCP spec requires `arguments` to
     * be a map. Strict server implementations reject `arguments: []`.
     *
     * @param  array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function getPrompt(string $name, array $arguments = []): array
    {
        $request = JsonRpcMessage::request(
            id: self::newId(),
            method: 'prompts/get',
            params: [
                'name' => $name,
                'arguments' => $arguments === [] ? new \stdClass() : $arguments,
            ],
        );

        return $this->call($request);
    }

    public function transport(): McpTransportContract
    {
        return $this->transport;
    }

    /** @return array<string,mixed> */
    protected function call(JsonRpcMessage $request): array
    {
        $response = $this->transport->request($request);

        if ($response->isError()) {
            $err = $response->error ?? [];
            $message = (string) ($err['message'] ?? 'MCP server returned a JSON-RPC error.');
            $code = (int) ($err['code'] ?? 0);
            throw new McpTransportException("MCP server error [{$code}]: {$message}");
        }

        $result = $response->result;
        if (! is_array($result)) {
            throw new McpTransportException('MCP server returned non-object result.');
        }

        return $result;
    }

    protected static function transportFor(McpServerContract $server): McpTransportContract
    {
        $config = $server->transportConfig();
        $transport = strtolower($server->transport());

        return match ($transport) {
            'http', 'https' => new HttpJsonRpcTransport($config),
            'sse' => new \Padosoft\AskMyDocsMcpPack\Transports\SseJsonRpcTransport($config),
            'stdio' => new StdioJsonRpcTransport($config),
            default => throw new McpTransportException("Unknown MCP transport [{$transport}] for server [{$server->id()}]."),
        };
    }

    protected static function newId(): string
    {
        return 'rpc_' . bin2hex(random_bytes(8));
    }
}
