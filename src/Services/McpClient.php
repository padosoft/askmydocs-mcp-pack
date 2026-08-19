<?php

namespace Padosoft\AskMyDocsMcpPack\Services;

use Padosoft\AskMyDocsMcpPack\Contracts\McpProtocolAwareTransportContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpProtocolNegotiationException;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpRemoteErrorException;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Padosoft\AskMyDocsMcpPack\Support\McpCatalogPage;
use Padosoft\AskMyDocsMcpPack\Support\McpNegotiationResult;
use Padosoft\AskMyDocsMcpPack\Support\McpProtocolEra;
use Padosoft\AskMyDocsMcpPack\Support\McpRemoteTask;
use Padosoft\AskMyDocsMcpPack\Support\McpToolResult;
use Padosoft\AskMyDocsMcpPack\Transports\HttpJsonRpcTransport;
use Padosoft\AskMyDocsMcpPack\Transports\LegacySseJsonRpcTransport;
use Padosoft\AskMyDocsMcpPack\Transports\SseJsonRpcTransport;
use Padosoft\AskMyDocsMcpPack\Transports\StdioJsonRpcTransport;

/**
 * Thin protocol-aware wrapper around a {@see McpTransportContract}.
 *
 * Uses discovery-first MCP 2026-07-28 and falls back across the supported
 * session-based legacy revisions only when the modern method is
 * explicitly rejected as unsupported.
 *
 * Higher-level orchestration (multi-turn loop, audit trail, retries)
 * lives in {@see McpToolCallingService}.
 */
class McpClient
{
    public const MODERN_PROTOCOL_VERSION = '2026-07-28';

    public const LATEST_LEGACY_PROTOCOL_VERSION = '2025-11-25';

    /** @var list<string> */
    public const SUPPORTED_LEGACY_PROTOCOL_VERSIONS = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
        '2024-10-07',
    ];

    private ?McpNegotiationResult $negotiation = null;

    private bool $sessionRecoveryAttempted = false;

    public function __construct(
        protected readonly McpServerContract $server,
        protected readonly McpTransportContract $transport,
    ) {}

    /**
     * Resolver hook — tests swap this to inject a stub transport.
     *
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
        if ($protocolVersion === null) {
            return $this->negotiate()->raw;
        }

        if ($protocolVersion === self::MODERN_PROTOCOL_VERSION) {
            return $this->negotiate('modern')->raw;
        }

        return $this->negotiateLegacy($protocolVersion)->raw;
    }

    public function negotiate(string $mode = 'auto'): McpNegotiationResult
    {
        if ($this->negotiation !== null) {
            return $this->negotiation;
        }
        if (! in_array($mode, ['auto', 'modern', 'legacy'], true)) {
            throw new \InvalidArgumentException("Unsupported MCP negotiation mode [{$mode}].");
        }
        if ($mode === 'legacy') {
            return $this->negotiateLegacy(self::LATEST_LEGACY_PROTOCOL_VERSION);
        }

        $this->configureTransport(McpProtocolEra::Modern, self::MODERN_PROTOCOL_VERSION);
        $request = JsonRpcMessage::request(
            self::newId(),
            'server/discover',
            ['_meta' => $this->requestMeta(), 'clientInfo' => $this->clientInfo()],
        );
        $response = $this->transport->request($request);

        if ($response->isError()) {
            if ($mode === 'auto' && $this->isUnsupportedModernResponse($response)) {
                return $this->negotiateLegacyWithFallback();
            }

            throw $this->exceptionFor($response, 'Modern MCP discovery failed');
        }

        if (! is_array($response->result)) {
            throw new McpProtocolNegotiationException('Modern MCP discovery returned a non-object result.');
        }

        $payload = $response->result;
        $version = is_string($payload['protocolVersion'] ?? null)
            ? $payload['protocolVersion']
            : self::MODERN_PROTOCOL_VERSION;
        if ($version !== self::MODERN_PROTOCOL_VERSION) {
            if ($mode === 'auto' && $this->looksLikeLegacyVersion($version)) {
                return $this->negotiateLegacyWithFallback();
            }
            throw new McpProtocolNegotiationException("Unsupported modern MCP protocol version [{$version}].");
        }

        $this->configureTransport(McpProtocolEra::Modern, $version);

        return $this->negotiation = new McpNegotiationResult(
            era: McpProtocolEra::Modern,
            protocolVersion: $version,
            capabilities: is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [],
            serverInfo: is_array($payload['serverInfo'] ?? null) ? $payload['serverInfo'] : [],
            raw: $payload,
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function listTools(): array
    {
        return $this->listToolsPage()->items;
    }

    public function listToolsPage(?string $cursor = null): McpCatalogPage
    {
        $params = $cursor !== null ? ['cursor' => $cursor] : [];
        $request = JsonRpcMessage::request(self::newId(), 'tools/list', $this->params($params));
        $payload = $this->call($request);

        $tools = $payload['tools'] ?? [];
        if (! is_array($tools)) {
            $tools = [];
        }

        $items = array_values(array_filter(
            $tools,
            static fn ($tool): bool => is_array($tool) && isset($tool['name']),
        ));

        $nextCursor = $payload['nextCursor'] ?? null;
        $meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
        $ttl = $meta['cacheTtlSeconds'] ?? $meta['cache_ttl_seconds'] ?? null;
        $ttlMs = $payload['ttlMs'] ?? null;
        $cacheScope = $payload['cacheScope'] ?? null;

        return new McpCatalogPage(
            items: $items,
            nextCursor: is_string($nextCursor) ? $nextCursor : null,
            cacheTtlSeconds: is_int($ttl) ? max(0, $ttl) : null,
            ttlMs: is_int($ttlMs) ? max(0, $ttlMs) : null,
            cacheScope: is_string($cacheScope) ? $cacheScope : null,
            meta: $meta,
        );
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function callTool(string $toolName, array $arguments): array
    {
        return $this->callToolResult($toolName, $arguments)->toArray();
    }

    /** @param array<string,mixed> $arguments */
    public function callToolResult(string $toolName, array $arguments, array $continuation = []): McpToolResult
    {
        $params = ['name' => $toolName, 'arguments' => $arguments];
        foreach (['requestState', 'inputResponses'] as $key) {
            if (array_key_exists($key, $continuation)) {
                $params[$key] = $continuation[$key];
            }
        }
        $request = JsonRpcMessage::request(
            id: self::newId(),
            method: 'tools/call',
            params: $this->params($params),
        );

        return McpToolResult::fromArray($this->call($request));
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
        $page = $this->listResourcesPage($cursor);

        return [
            'resources' => $page->items,
            'nextCursor' => $page->nextCursor,
        ];
    }

    /**
     * Protocol-era-neutral resource catalog page. Modern servers can attach
     * cache hints to the result while legacy callers keep using
     * {@see listResources()} and its original array shape.
     */
    public function listResourcesPage(?string $cursor = null): McpCatalogPage
    {
        $params = $this->params($cursor !== null ? ['cursor' => $cursor] : []);
        $request = JsonRpcMessage::request(self::newId(), 'resources/list', $params);
        $payload = $this->call($request);

        $resources = $payload['resources'] ?? [];
        if (! is_array($resources)) {
            $resources = [];
        }
        $resources = array_values(array_filter(
            $resources,
            static fn ($resource): bool => is_array($resource) && isset($resource['uri']),
        ));

        $nextCursor = $payload['nextCursor'] ?? null;
        $meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
        $ttl = $meta['cacheTtlSeconds'] ?? $meta['cache_ttl_seconds'] ?? null;
        $ttlMs = $payload['ttlMs'] ?? null;
        $cacheScope = $payload['cacheScope'] ?? null;

        return new McpCatalogPage(
            items: $resources,
            nextCursor: is_string($nextCursor) ? $nextCursor : null,
            cacheTtlSeconds: is_int($ttl) ? max(0, $ttl) : null,
            ttlMs: is_int($ttlMs) ? max(0, $ttlMs) : null,
            cacheScope: is_string($cacheScope) ? $cacheScope : null,
            meta: $meta,
        );
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
            params: $this->params(['uri' => $uri]),
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
        $params = $this->params($cursor !== null ? ['cursor' => $cursor] : []);
        $request = JsonRpcMessage::request(self::newId(), 'prompts/list', $params);
        $payload = $this->call($request);

        $prompts = $payload['prompts'] ?? [];
        if (! is_array($prompts)) {
            $prompts = [];
        }
        $prompts = array_values(array_filter(
            $prompts,
            static fn ($prompt): bool => is_array($prompt) && isset($prompt['name']),
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
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function getPrompt(string $name, array $arguments = []): array
    {
        $request = JsonRpcMessage::request(
            id: self::newId(),
            method: 'prompts/get',
            params: $this->params([
                'name' => $name,
                'arguments' => $arguments === [] ? new \stdClass : $arguments,
            ]),
        );

        return $this->call($request);
    }

    /** @param array<string,mixed> $arguments */
    public function task(string $toolName, array $arguments): McpToolResult
    {
        $result = $this->callToolResult($toolName, $arguments);
        if (! $result->isTask()) {
            throw new McpTransportException("Tool [{$toolName}] did not return a task result.");
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function getTask(string $taskId): array
    {
        return $this->call(JsonRpcMessage::request(self::newId(), 'tasks/get', $this->params(['taskId' => $taskId])));
    }

    public function getTaskStatus(string $taskId): McpRemoteTask
    {
        return McpRemoteTask::fromEnvelope($this->getTask($taskId));
    }

    /** @param array<string,mixed> $inputResponses @return array<string,mixed> */
    public function updateTask(string $taskId, array $inputResponses, ?string $requestState = null): array
    {
        $params = ['taskId' => $taskId, 'inputResponses' => $inputResponses];
        if ($requestState !== null) {
            $params['requestState'] = $requestState;
        }

        return $this->call(JsonRpcMessage::request(self::newId(), 'tasks/update', $this->params($params)));
    }

    /** @return array<string,mixed> */
    public function cancelTask(string $taskId): array
    {
        return $this->call(JsonRpcMessage::request(self::newId(), 'tasks/cancel', $this->params(['taskId' => $taskId])));
    }

    /** @return array<string,mixed> */
    public function waitForTask(string $taskId, int $timeoutMs = 60_000, int $maxPolls = 120): array
    {
        $started = hrtime(true);
        $polls = 0;
        do {
            $envelope = $this->getTask($taskId);
            $task = McpRemoteTask::fromEnvelope($envelope);
            if ($task->isTerminal()) {
                return $envelope;
            }
            $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);
            if (++$polls >= max(1, $maxPolls) || $elapsedMs >= max(1, $timeoutMs)) {
                throw new McpTransportException("Timed out waiting for task [{$taskId}].");
            }
            $sleepMs = max(50, min($task->pollIntervalMs ?? 1000, max(1, $timeoutMs - $elapsedMs)));
            usleep($sleepMs * 1000);
        } while (true);
    }

    public function waitForTaskStatus(string $taskId, int $timeoutMs = 60_000, int $maxPolls = 120): McpRemoteTask
    {
        return McpRemoteTask::fromEnvelope($this->waitForTask($taskId, $timeoutMs, $maxPolls));
    }

    public function transport(): McpTransportContract
    {
        return $this->transport;
    }

    public function negotiatedProtocol(): ?McpNegotiationResult
    {
        return $this->negotiation;
    }

    /** @return array<string,mixed> */
    protected function call(JsonRpcMessage $request): array
    {
        if ($this->negotiation === null) {
            $this->negotiate();
        }

        try {
            $response = $this->transport->request($request);
        } catch (McpTransportException $e) {
            if (! $this->canRecoverExpiredSession()) {
                throw $e;
            }

            $this->recoverLegacySession();
            $response = $this->transport->request($this->rebuildForCurrentProtocol($request));
        }

        if ($response->isError()) {
            throw $this->exceptionFor($response, 'MCP server error');
        }

        $result = $response->result;
        if (! is_array($result)) {
            throw new McpTransportException('MCP server returned non-object result.');
        }

        return $result;
    }

    private function negotiateLegacy(string $requestedVersion): McpNegotiationResult
    {
        if (! $this->looksLikeLegacyVersion($requestedVersion)) {
            throw new McpProtocolNegotiationException("Unsupported legacy MCP protocol version [{$requestedVersion}].");
        }

        $this->configureTransport(McpProtocolEra::Legacy, $requestedVersion);
        $request = JsonRpcMessage::request(self::newId(), 'initialize', [
            'protocolVersion' => $requestedVersion,
            'capabilities' => ['tools' => new \stdClass],
            'clientInfo' => $this->clientInfo(),
        ]);
        $response = $this->transport->request($request);
        if ($response->isError()) {
            throw $this->exceptionFor($response, 'Legacy MCP initialization failed');
        }
        if (! is_array($response->result)) {
            throw new McpProtocolNegotiationException('Legacy MCP initialization returned a non-object result.');
        }

        $payload = $response->result;
        $version = is_string($payload['protocolVersion'] ?? null)
            ? $payload['protocolVersion']
            : $requestedVersion;
        if (! $this->looksLikeLegacyVersion($version)) {
            throw new McpProtocolNegotiationException("Server selected unsupported legacy MCP protocol version [{$version}].");
        }

        $this->configureTransport(McpProtocolEra::Legacy, $version);
        $this->transport->notify(JsonRpcMessage::notification('notifications/initialized'));
        $this->sessionRecoveryAttempted = false;

        return $this->negotiation = new McpNegotiationResult(
            era: McpProtocolEra::Legacy,
            protocolVersion: $version,
            capabilities: is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : $payload,
            serverInfo: is_array($payload['serverInfo'] ?? null) ? $payload['serverInfo'] : [],
            raw: $payload,
        );
    }

    private function negotiateLegacyWithFallback(): McpNegotiationResult
    {
        $last = null;
        foreach (self::SUPPORTED_LEGACY_PROTOCOL_VERSIONS as $version) {
            try {
                return $this->negotiateLegacy($version);
            } catch (McpRemoteErrorException $e) {
                if (! $this->isUnsupportedVersionError($e)) {
                    throw $e;
                }
                $last = $e;
                $this->negotiation = null;
            }
        }
        throw new McpProtocolNegotiationException('MCP negotiation failed for every supported revision.', previous: $last);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function params(array $params): array
    {
        if ($this->negotiation === null) {
            $this->negotiate();
        }
        if ($this->negotiation?->era === McpProtocolEra::Modern) {
            $existing = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
            $params['_meta'] = array_replace($this->requestMeta(), $existing);
            $params['clientInfo'] ??= $this->clientInfo();
        }

        return $params;
    }

    /** @return array<string,mixed> */
    private function requestMeta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion' => self::MODERN_PROTOCOL_VERSION,
            'io.modelcontextprotocol/clientInfo' => $this->clientInfo(),
            'io.modelcontextprotocol/clientCapabilities' => [
                'tools' => new \stdClass,
                'extensions' => [
                    'io.modelcontextprotocol/tasks' => new \stdClass,
                    'io.modelcontextprotocol/ui' => ['version' => '2026-01-26'],
                ],
            ],
        ];
    }

    /** @return array{name:string,version:string} */
    private function clientInfo(): array
    {
        return ['name' => 'padosoft/askmydocs-mcp-pack', 'version' => '2.0.0'];
    }

    private function configureTransport(McpProtocolEra $era, string $version): void
    {
        if ($this->transport instanceof McpProtocolAwareTransportContract) {
            $this->transport->useProtocol($era, $version);
        }
    }

    private function isUnsupportedModernResponse(JsonRpcMessage $response): bool
    {
        $error = $response->error ?? [];
        $code = (int) ($error['code'] ?? 0);
        if ($code === -32601) {
            return true;
        }

        $message = strtolower((string) ($error['message'] ?? ''));

        return in_array($code, [-32602, -32000, -32001, -32002], true)
            && preg_match('/(unsupported|not supported|unknown).*(protocol|version|server\/discover)|server\/discover.*(unsupported|not supported|unknown)/', $message) === 1;
    }

    private function looksLikeLegacyVersion(string $version): bool
    {
        return in_array($version, self::SUPPORTED_LEGACY_PROTOCOL_VERSIONS, true);
    }

    private function isUnsupportedVersionError(McpRemoteErrorException $error): bool
    {
        return in_array($error->rpcCode, [-32602, -32000, -32001, -32002, -32022], true)
            && preg_match('/(unsupported|not supported|unknown|invalid).*(protocol|version)|(protocol|version).*(unsupported|not supported|unknown|invalid)/i', $error->getMessage()) === 1;
    }

    private function exceptionFor(JsonRpcMessage $response, string $prefix): McpRemoteErrorException
    {
        $error = $response->error ?? [];
        $message = (string) ($error['message'] ?? 'MCP server returned a JSON-RPC error.');
        $code = (int) ($error['code'] ?? 0);

        $data = is_array($error['data'] ?? null) ? $error['data'] : null;

        return new McpRemoteErrorException("{$prefix} [{$code}]: {$message}", $code, $data);
    }

    private function canRecoverExpiredSession(): bool
    {
        return ! $this->sessionRecoveryAttempted
            && $this->transport instanceof McpProtocolAwareTransportContract
            && $this->negotiation?->era === McpProtocolEra::Legacy
            && $this->transport->sessionId() !== null
            && in_array($this->transport->lastStatusCode(), [404, 410], true);
    }

    private function recoverLegacySession(): void
    {
        if (! $this->transport instanceof McpProtocolAwareTransportContract) {
            return;
        }
        $this->sessionRecoveryAttempted = true;
        $this->transport->clearSession();
        $this->negotiation = null;
        $this->negotiateLegacy(self::LATEST_LEGACY_PROTOCOL_VERSION);
        $this->sessionRecoveryAttempted = true;
    }

    private function rebuildForCurrentProtocol(JsonRpcMessage $request): JsonRpcMessage
    {
        $params = $request->params ?? [];
        if ($this->negotiation?->era === McpProtocolEra::Modern) {
            $params = $this->params($params);
        } else {
            unset($params['_meta']);
        }

        return JsonRpcMessage::request($request->id ?? self::newId(), (string) $request->method, $params === [] ? null : $params);
    }

    protected static function transportFor(McpServerContract $server): McpTransportContract
    {
        $config = $server->transportConfig();
        $transport = strtolower($server->transport());

        return match ($transport) {
            'http', 'https' => new HttpJsonRpcTransport($config),
            'sse' => new SseJsonRpcTransport($config),
            'legacy_sse' => new LegacySseJsonRpcTransport($config),
            'stdio' => new StdioJsonRpcTransport($config),
            default => throw new McpTransportException("Unknown MCP transport [{$transport}] for server [{$server->id()}]."),
        };
    }

    protected static function newId(): string
    {
        return 'rpc_'.bin2hex(random_bytes(8));
    }
}
