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
            'stdio' => new StdioJsonRpcTransport($config),
            default => throw new McpTransportException("Unknown MCP transport [{$transport}] for server [{$server->id()}]."),
        };
    }

    protected static function newId(): string
    {
        return 'rpc_' . bin2hex(random_bytes(8));
    }
}
