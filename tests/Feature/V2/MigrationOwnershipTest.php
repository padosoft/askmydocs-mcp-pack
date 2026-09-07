<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\V2;

use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

final class MigrationOwnershipTest extends TestCase
{
    public function test_v2_migration_refuses_to_adopt_pre_existing_objects(): void
    {
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_08_13_000001_create_mcp_v2_tables.php';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot adopt pre-existing table [mcp_tasks]');

        $migration->up();
    }
}
