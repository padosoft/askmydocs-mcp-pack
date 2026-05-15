<?php

namespace Padosoft\AskMyDocsMcpPack\Console;

use Illuminate\Console\Command;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

class McpPingCommand extends Command
{
    protected $signature = 'mcp-pack:ping
                            {server-id? : Specific server id to ping (omit for all enabled servers in tenant)}
                            {--tenant= : Tenant id (defaults to platform-global)}';

    protected $description = 'Initialize + list-tools against MCP servers and print a per-server status report.';

    public function handle(McpServerRegistryContract $registry): int
    {
        $tenantId = $this->option('tenant');
        $serverId = $this->argument('server-id');

        $servers = $serverId
            ? array_filter([$registry->find((string) $serverId)])
            : $registry->forTenant($tenantId);

        if ($servers === []) {
            $this->warn('No MCP servers found for tenant ' . ($tenantId ?? '<global>') . '.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($servers as $server) {
            $client = McpClient::forServer($server);
            $status = 'ok';
            $toolsCount = 0;
            $error = '';

            try {
                $client->initialize();
                $toolsCount = count($client->listTools());
            } catch (\Throwable $e) {
                $status = 'error';
                $error = $e->getMessage();
            }

            $rows[] = [
                $server->id(),
                $server->name(),
                $server->transport(),
                $server->tenantId() ?? '<global>',
                $status,
                $toolsCount,
                $error !== '' ? mb_substr($error, 0, 80) : '',
            ];
        }

        $this->table(
            ['id', 'name', 'transport', 'tenant', 'status', '#tools', 'error'],
            $rows,
        );

        $hasError = false;
        foreach ($rows as $row) {
            if ($row[4] === 'error') {
                $hasError = true;
                break;
            }
        }

        return $hasError ? self::FAILURE : self::SUCCESS;
    }
}
