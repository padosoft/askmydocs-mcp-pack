<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Http\Admin\OpenApiController;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class OpenApiControllerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mcp-pack.admin.enabled', true);
        $app['config']->set('mcp-pack.admin.middleware', ['api']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiController::flushCache();
    }

    public function test_openapi_endpoint_returns_spec_with_correct_content_type(): void
    {
        $response = $this->get('/api/admin/mcp-pack/openapi.json');

        $response->assertOk();
        $this->assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertNotSame('', $body);

        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('3.1.0', $decoded['openapi']);
        $this->assertArrayHasKey('paths', $decoded);
        $this->assertArrayHasKey('components', $decoded);
    }

    public function test_openapi_endpoint_returns_403_when_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.openapi', false);

        $response = $this->get('/api/admin/mcp-pack/openapi.json');

        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    public function test_openapi_endpoint_memoises_the_spec_bytes(): void
    {
        // Call twice; the controller's static cache returns the same
        // body without re-reading the file. The functional assertion
        // is "second hit returns identical bytes" — exercising the
        // memoisation branch.
        $a = $this->get('/api/admin/mcp-pack/openapi.json')->getContent();
        $b = $this->get('/api/admin/mcp-pack/openapi.json')->getContent();

        $this->assertSame($a, $b);
        $this->assertNotEmpty($a);
    }
}
