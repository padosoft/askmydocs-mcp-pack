<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeIdentityContract;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeIdentityBridge;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventsSseControllerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mcp-pack.admin.enabled', true);
        $app['config']->set('mcp-pack.admin.middleware', ['api', InjectTenantMiddleware::class]);
        // Cap each connection at 1 second so the test does not block
        // on the polling loop. Combined with `poll_ms=1000` this
        // means the StreamedResponse callback runs ONE poll cycle
        // (the initial fetch) and then exits.
        $app['config']->set('mcp-pack.admin.sse.poll_ms', 1000);
        $app['config']->set('mcp-pack.admin.sse.max_seconds', 1);
    }

    protected function setUp(): void
    {
        parent::setUp();
        InjectTenantMiddleware::$tenantId = null;
    }

    private function bindBridge(): FakeIdentityBridge
    {
        $bridge = new FakeIdentityBridge();
        $this->app->instance(McpHostBridgeIdentityContract::class, $bridge);
        return $bridge;
    }

    public function test_stream_emits_initial_frame_for_recent_audit_row(): void
    {
        $bridge = $this->bindBridge();
        $bridge->recentAuditRows = [
            [
                'id' => 42,
                'ts' => 1_700_000_000,
                'server_id' => 'srv_01',
                'tool' => 'search',
                'status' => 200,
                'dur' => 142,
                'actor' => 'lorenzo@padosoft.com',
            ],
        ];
        InjectTenantMiddleware::$tenantId = 'acme';

        $response = $this->get('/api/admin/mcp-pack/events');

        $response->assertOk();
        // Symfony appends `; charset=utf-8` to text/* responses, so
        // assert the prefix rather than the exact value.
        $contentType = (string) $response->headers->get('Content-Type');
        $this->assertStringStartsWith('text/event-stream', $contentType);
        $baseResponse = $response->baseResponse;
        $this->assertInstanceOf(StreamedResponse::class, $baseResponse);

        // Capture the streamed body. The controller's `emit()`
        // calls `@ob_flush()` so we need TWO output-buffer layers:
        // the inner layer absorbs each emit, the outer layer is
        // where the inner one flushes to. We read the outer one
        // after the callback returns.
        ob_start();
        ob_start();
        $baseResponse->sendContent();
        ob_end_flush();
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('event: invocation', $body);
        $this->assertStringContainsString('"id":42', $body);
        $this->assertStringContainsString('"server_id":"srv_01"', $body);
        // R30: the bridge was called with the trusted tenant id from
        // the request attribute (`acme`), not from any client header.
        $this->assertNotEmpty($bridge->recentAuditCalls);
        $this->assertSame('acme', $bridge->recentAuditCalls[0][1]);
    }

    public function test_stream_returns_501_when_host_does_not_implement(): void
    {
        $bridge = $this->bindBridge();
        $bridge->forceNotImplemented = true;

        $response = $this->get('/api/admin/mcp-pack/events');

        $response->assertStatus(501);
        $this->assertSame('feature_not_implemented', $response->json('error.code'));
    }

    public function test_stream_returns_403_when_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.events_sse', false);
        $this->bindBridge();

        $response = $this->get('/api/admin/mcp-pack/events');

        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    public function test_stream_emits_no_data_frames_when_bridge_returns_empty(): void
    {
        $bridge = $this->bindBridge();
        $bridge->recentAuditRows = []; // empty initial set

        $response = $this->get('/api/admin/mcp-pack/events');
        $response->assertOk();

        ob_start();
        ob_start();
        $response->baseResponse->sendContent();
        ob_end_flush();
        $body = (string) ob_get_clean();

        // No `event:` frames written; just the silent wait loop.
        $this->assertStringNotContainsString('event: invocation', $body);
    }

    public function test_stream_sets_no_cache_header(): void
    {
        $bridge = $this->bindBridge();
        $bridge->recentAuditRows = [];

        $response = $this->get('/api/admin/mcp-pack/events');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl);
    }
}
