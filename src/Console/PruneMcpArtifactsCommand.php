<?php

namespace Padosoft\AskMyDocsMcpPack\Console;

use Illuminate\Console\Command;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\ArtifactManagerContract;

final class PruneMcpArtifactsCommand extends Command
{
    protected $signature = 'mcp-pack:artifacts:prune';

    protected $description = 'Idempotently delete expired or soft-deleted MCP v2 artifacts.';

    public function handle(ArtifactManagerContract $artifacts): int
    {
        $this->components->info('Pruned '.$artifacts->prune().' MCP artifact(s).');

        return self::SUCCESS;
    }
}
