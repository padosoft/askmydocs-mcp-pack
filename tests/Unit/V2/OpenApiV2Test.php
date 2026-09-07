<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

final class OpenApiV2Test extends TestCase
{
    public function test_openapi_31_covers_every_v2_admin_route(): void
    {
        $spec = json_decode(file_get_contents(dirname(__DIR__, 3).'/resources/openapi/v2.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('3.1.0', $spec['openapi']);
        $paths = array_keys($spec['paths']);
        sort($paths);
        $this->assertSame([
            '/apps',
            '/artifacts',
            '/artifacts/{artifact}',
            '/artifacts/{artifact}/download',
            '/capabilities',
            '/tasks',
            '/tasks/{task}',
            '/tasks/{task}/cancel',
            '/tasks/{task}/input',
        ], $paths);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
    }
}
