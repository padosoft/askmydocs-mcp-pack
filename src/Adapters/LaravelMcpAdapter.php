<?php

namespace Padosoft\AskMyDocsMcpPack\Adapters;

use Padosoft\AskMyDocsMcpPack\Contracts\V2\DefinitionContract;
use Padosoft\AskMyDocsMcpPack\Fluent\Definitions\ServerDefinition;
use Padosoft\AskMyDocsMcpPack\Protocol\McpResult;
use Padosoft\AskMyDocsMcpPack\Protocol\ProtocolVersion;

/**
 * Optional, dependency-free bridge to the first-party laravel/mcp package.
 * It deliberately resolves all Laravel MCP symbols at runtime so this package
 * never imposes laravel/mcp on autonomous-core users.
 */
final class LaravelMcpAdapter
{
    public function assertCompatible(): void
    {
        $enum = 'Laravel\\Mcp\\Enums\\ProtocolVersion';
        if (! enum_exists($enum) || ! defined($enum.'::LATEST')) {
            throw new \RuntimeException('The optional laravel/mcp adapter requires a release exposing MCP 2026-07-28 discovery. Install a compatible laravel/mcp release or disable mcp-pack.laravel_mcp.enabled.');
        }
        $latest = constant($enum.'::LATEST');
        $value = $latest instanceof \BackedEnum ? $latest->value : null;
        if ($value !== ProtocolVersion::V2) {
            throw new \RuntimeException('Installed laravel/mcp is incompatible: expected protocol '.ProtocolVersion::V2.', got '.($value ?? 'unknown').'.');
        }
    }

    /** Register an explicitly authored first-party Server subclass on its HTTP transport. @param class-string $serverClass */
    public function web(string $route, string $serverClass): mixed
    {
        $this->assertCompatible();
        $facade = 'Laravel\\Mcp\\Facades\\Mcp';

        return $facade::web($route, $serverClass);
    }

    /** Register an explicitly authored first-party Server subclass on its local transport. @param class-string $serverClass */
    public function local(string $name, string $serverClass): void
    {
        $this->assertCompatible();
        $facade = 'Laravel\\Mcp\\Facades\\Mcp';
        $facade::local($name, $serverClass);
    }

    public function client(string $name): mixed
    {
        $this->assertCompatible();
        $facade = 'Laravel\\Mcp\\Facades\\Mcp';

        return $facade::client($name);
    }

    /** @return array<string,mixed> Stable export used by first-party primitive adapters. */
    public function definition(DefinitionContract $definition): array
    {
        $this->assertCompatible();

        return $definition->toArray();
    }

    /** @return array<string,mixed> */
    public function server(ServerDefinition $server): array
    {
        $this->assertCompatible();

        return [
            'serverInfo' => $server->serverInfo(), 'protocolVersion' => ProtocolVersion::V2,
            'cache' => ['ttlMs' => $server->ttlMs, 'cacheScope' => $server->cacheScope->value],
            'tools' => array_map(static fn ($item): array => $item->toArray(), $server->tools),
            'resources' => array_map(static fn ($item): array => $item->toArray(), $server->resources),
            'resourceTemplates' => array_map(static fn ($item): array => $item->toArray(), $server->resourceTemplates),
            'prompts' => array_map(static fn ($item): array => $item->toArray(), $server->prompts),
            'apps' => array_map(static fn ($item): array => $item->toArray(), $server->apps),
        ];
    }

    /** @param array<string,mixed> $serverInfo @return array<string,mixed> */
    public function result(mixed $result, array $serverInfo = []): array
    {
        $this->assertCompatible();

        return McpResult::normalise($result, $serverInfo);
    }
}
