<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Support\HostUser;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeIdentityBridge;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class MeControllerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mcp-pack.admin.enabled', true);
        $app['config']->set('mcp-pack.admin.middleware', ['api', InjectTenantMiddleware::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        InjectTenantMiddleware::$tenantId = null;
    }

    private function bindBridge(): FakeIdentityBridge
    {
        $bridge = new FakeIdentityBridge();
        $this->app->instance(McpHostBridgeContract::class, $bridge);
        return $bridge;
    }

    public function test_show_returns_current_user_payload(): void
    {
        $bridge = $this->bindBridge();
        $bridge->user = new HostUser(
            id: 42,
            email: 'lorenzo@padosoft.com',
            name: 'Lorenzo Padovani',
            initials: 'LP',
            tenantId: 'acme-corp',
            permissions: ['mcp.servers.view'],
        );
        InjectTenantMiddleware::$tenantId = 'acme-corp';

        $response = $this->getJson('/api/admin/mcp-pack/me');

        $response->assertOk();
        $this->assertSame(42, $response->json('data.id'));
        $this->assertSame('lorenzo@padosoft.com', $response->json('data.email'));
        $this->assertSame(['mcp.servers.view'], $response->json('data.permissions'));
        $this->assertSame('acme-corp', $response->json('meta.tenant_id'));
    }

    public function test_show_returns_401_when_no_actor_bound(): void
    {
        $this->bindBridge(); // user stays null
        $response = $this->getJson('/api/admin/mcp-pack/me');
        $response->assertStatus(401);
        $this->assertSame('unauthenticated', $response->json('error.code'));
    }

    public function test_show_returns_501_when_host_does_not_implement(): void
    {
        $bridge = $this->bindBridge();
        $bridge->forceNotImplemented = true;

        $response = $this->getJson('/api/admin/mcp-pack/me');
        $response->assertStatus(501);
        $this->assertSame('feature_not_implemented', $response->json('error.code'));
    }

    public function test_show_returns_403_when_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.me', false);
        $this->bindBridge();

        // The route is registered at boot (when features.me was
        // still default `true`); the controller's `featureGate()`
        // catches the runtime flip and returns 403 instead.
        $response = $this->getJson('/api/admin/mcp-pack/me');
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }

    public function test_update_preferences_persists_for_current_user_only(): void
    {
        $bridge = $this->bindBridge();
        $bridge->user = new HostUser(id: 42, email: 'a@b.c', name: 'A');

        $response = $this->postJson('/api/admin/mcp-pack/me/preferences', [
            'preferences' => ['theme' => 'dark', 'lang' => 'it'],
        ]);

        $response->assertOk();
        // R30: the persisted user_id is 42 (currentUser), NEVER a
        // value the client sent.
        $this->assertSame([42, ['theme' => 'dark', 'lang' => 'it']], $bridge->savedPreferences);
        $this->assertSame(42, $response->json('data.user_id'));
        $this->assertSame(['theme' => 'dark', 'lang' => 'it'], $response->json('data.values'));
    }

    public function test_update_preferences_ignores_client_supplied_user_id(): void
    {
        $bridge = $this->bindBridge();
        $bridge->user = new HostUser(id: 42, email: 'a@b.c', name: 'A');

        $this->postJson('/api/admin/mcp-pack/me/preferences', [
            'preferences' => ['theme' => 'dark'],
            // R30: even if a client tries to sneak in a different
            // user_id, the controller MUST persist for user 42.
            'user_id' => 99,
        ])->assertOk();

        $this->assertSame(42, $bridge->savedPreferences[0]);
    }

    public function test_update_preferences_422_on_missing_preferences_key(): void
    {
        $bridge = $this->bindBridge();
        $bridge->user = new HostUser(id: 42, email: 'a@b.c', name: 'A');

        $response = $this->postJson('/api/admin/mcp-pack/me/preferences', []);
        $response->assertStatus(422);
        $this->assertArrayHasKey('preferences', $response->json('errors'));
    }

    public function test_update_preferences_422_on_oversized_key(): void
    {
        $bridge = $this->bindBridge();
        $bridge->user = new HostUser(id: 42, email: 'a@b.c', name: 'A');

        $tooLongKey = str_repeat('k', 129);
        $response = $this->postJson('/api/admin/mcp-pack/me/preferences', [
            'preferences' => [$tooLongKey => 'value'],
        ]);
        $response->assertStatus(422);
    }

    public function test_update_preferences_401_when_no_actor(): void
    {
        $this->bindBridge();
        $response = $this->postJson('/api/admin/mcp-pack/me/preferences', [
            'preferences' => ['theme' => 'dark'],
        ]);
        $response->assertStatus(401);
        $this->assertSame('unauthenticated', $response->json('error.code'));
    }
}
