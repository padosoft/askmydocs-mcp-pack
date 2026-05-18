<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * Schema-file integrity test for `resources/openapi/v1.5.json`.
 *
 * - Parses as JSON.
 * - Declares OpenAPI 3.1.
 * - Contains all 22 endpoint paths v1.5 ships across W1.A → W1.D.
 * - Has a non-empty `components.schemas` block.
 *
 * If a future PR adds a path, the count assertion and the explicit
 * list below need to grow in lock-step — failing this test reminds
 * the author to keep the SPA's OpenAPI viewer in sync with the
 * actual REST surface.
 */
class OpenApiSpecFileTest extends TestCase
{
    private const SPEC_PATH = __DIR__ . '/../../../resources/openapi/v1.5.json';

    private const EXPECTED_PATHS = [
        '/me',
        '/me/preferences',
        '/tenants',
        '/api-keys',
        '/api-keys/{id}',
        '/servers',
        '/servers/{id}',
        '/servers/{id}/handshake',
        '/servers/{id}/tools',
        '/servers/{id}/tools/{name}/invoke',
        '/tools',
        '/servers/{id}/resources',
        '/servers/{id}/resources/{uri}',
        '/servers/{id}/prompts',
        '/servers/{id}/prompts/{name}',
        '/audit',
        '/audit/{id}',
        '/audit/{id}/replay',
        '/circuit-breaker',
        '/circuit-breaker/{key}/reset',
        '/events',
        '/openapi.json',
    ];

    public function test_spec_file_exists_and_is_readable(): void
    {
        $this->assertFileExists(self::SPEC_PATH);
        $this->assertIsReadable(self::SPEC_PATH);
    }

    public function test_spec_parses_as_openapi_3_1_json(): void
    {
        $contents = file_get_contents(self::SPEC_PATH);
        $this->assertIsString($contents);
        $decoded = json_decode($contents, true);
        $this->assertIsArray($decoded);
        $this->assertSame('3.1.0', $decoded['openapi']);
        $this->assertArrayHasKey('info', $decoded);
        $this->assertSame('1.5.0', $decoded['info']['version']);
    }

    public function test_spec_contains_all_22_v15_endpoint_paths(): void
    {
        $contents = file_get_contents(self::SPEC_PATH);
        $decoded = json_decode($contents, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('paths', $decoded);

        $paths = array_keys($decoded['paths']);
        sort($paths);
        $expected = self::EXPECTED_PATHS;
        sort($expected);

        $this->assertSame($expected, $paths);
        $this->assertCount(22, $paths);
    }

    public function test_spec_has_components_schemas_block(): void
    {
        $contents = file_get_contents(self::SPEC_PATH);
        $decoded = json_decode($contents, true);
        $this->assertArrayHasKey('components', $decoded);
        $this->assertArrayHasKey('schemas', $decoded['components']);
        $this->assertNotEmpty($decoded['components']['schemas']);
        // Spot-check the schemas required by `data.js` consumers.
        $schemas = $decoded['components']['schemas'];
        foreach (['HostUser', 'HostTenant', 'HostApiKey', 'McpServer', 'McpServerPage', 'Tool', 'AuditRow', 'AuditDetail', 'BreakerState', 'Resource', 'Prompt', 'Event', 'Error'] as $required) {
            $this->assertArrayHasKey($required, $schemas, "Missing required schema: {$required}");
        }
    }
}
