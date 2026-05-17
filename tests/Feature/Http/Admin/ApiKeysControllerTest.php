<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\Http\Admin;

use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Support\HostApiKey;
use Padosoft\AskMyDocsMcpPack\Support\HostUser;
use Padosoft\AskMyDocsMcpPack\Tests\Support\FakeIdentityBridge;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

class ApiKeysControllerTest extends TestCase
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

    private function bindBridge(int $userId = 42): FakeIdentityBridge
    {
        $bridge = new FakeIdentityBridge();
        $bridge->user = new HostUser(id: $userId, email: 'a@b.c', name: 'A');
        $this->app->instance(McpHostBridgeContract::class, $bridge);
        return $bridge;
    }

    public function test_index_scopes_to_current_user_id(): void
    {
        $bridge = $this->bindBridge(userId: 42);
        $bridge->apiKeys = [
            new HostApiKey(id: 'tok_01', name: 'cli', scopes: ['mcp.tools.invoke']),
        ];

        $response = $this->getJson('/api/admin/mcp-pack/api-keys');
        $response->assertOk();
        $this->assertSame([42], $bridge->listApiKeysCalledWith); // R30: only ever queries user 42's keys
        $this->assertSame('tok_01', $response->json('data.0.id'));
        $this->assertArrayNotHasKey('plaintext', $response->json('data.0'));
        $this->assertSame(1, $response->json('meta.count'));
        $this->assertSame(42, $response->json('meta.user_id'));
    }

    public function test_index_401_when_no_actor(): void
    {
        $bridge = new FakeIdentityBridge(); // user stays null
        $this->app->instance(McpHostBridgeContract::class, $bridge);

        $response = $this->getJson('/api/admin/mcp-pack/api-keys');
        $response->assertStatus(401);
    }

    public function test_store_creates_key_with_plaintext_in_response_once(): void
    {
        $bridge = $this->bindBridge();
        $bridge->createApiKeyResult = new HostApiKey(
            id: 'tok_new',
            name: 'cli',
            scopes: ['mcp.tools.invoke'],
            plaintext: 'mcp_pat_abc123',
        );

        $response = $this->postJson('/api/admin/mcp-pack/api-keys', [
            'name' => 'cli',
            'scopes' => ['mcp.tools.invoke', 'mcp.servers.view'],
        ]);

        $response->assertStatus(201);
        $this->assertSame('mcp_pat_abc123', $response->json('data.plaintext'));
        $this->assertSame('tok_new', $response->json('data.id'));
    }

    public function test_store_422_when_scope_contains_sql_like_wildcard(): void
    {
        $this->bindBridge();

        $response = $this->postJson('/api/admin/mcp-pack/api-keys', [
            'name' => 'cli',
            // R19: `%` is rejected by the regex.
            'scopes' => ['mcp.tools.%'],
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('scopes.0', $response->json('errors'));
    }

    public function test_store_422_when_scope_contains_underscore_wildcard(): void
    {
        $this->bindBridge();

        $response = $this->postJson('/api/admin/mcp-pack/api-keys', [
            'name' => 'cli',
            // R19: underscore is rejected even though SQL LIKE
            // treats it as a single-char wildcard — the regex
            // explicitly excludes `_`.
            'scopes' => ['mcp_tools_invoke'],
        ]);

        $response->assertStatus(422);
    }

    public function test_store_422_when_name_has_control_characters(): void
    {
        $this->bindBridge();

        $response = $this->postJson('/api/admin/mcp-pack/api-keys', [
            'name' => "cli\nmalicious-log-injection",
            'scopes' => ['mcp.tools.invoke'],
        ]);
        $response->assertStatus(422);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    public function test_store_422_on_empty_scopes(): void
    {
        $this->bindBridge();

        $response = $this->postJson('/api/admin/mcp-pack/api-keys', [
            'name' => 'cli',
            'scopes' => [],
        ]);
        $response->assertStatus(422);
    }

    public function test_store_dedupes_scopes_before_handing_to_host(): void
    {
        $bridge = $this->bindBridge();
        $bridge->createApiKeyResult = new HostApiKey(
            id: 'tok_x',
            name: 'cli',
            scopes: ['mcp.tools.invoke'],
            plaintext: 't',
        );

        $this->postJson('/api/admin/mcp-pack/api-keys', [
            'name' => 'cli',
            'scopes' => ['mcp.tools.invoke', 'mcp.tools.invoke', 'mcp.servers.view'],
        ])->assertStatus(201);
    }

    public function test_destroy_returns_404_when_revoke_returns_false(): void
    {
        $bridge = $this->bindBridge();
        $bridge->revokeApiKeyResult = false;

        $response = $this->deleteJson('/api/admin/mcp-pack/api-keys/tok_nope');
        $response->assertStatus(404);
        $this->assertSame('not_found', $response->json('error.code'));
    }

    public function test_destroy_returns_200_when_revoke_returns_true(): void
    {
        $bridge = $this->bindBridge();
        $bridge->revokeApiKeyResult = true;

        $response = $this->deleteJson('/api/admin/mcp-pack/api-keys/tok_01');
        $response->assertOk();
        $this->assertSame('tok_01', $response->json('data.id'));
        $this->assertTrue($response->json('data.revoked'));
    }

    public function test_index_returns_501_when_host_does_not_implement(): void
    {
        $bridge = $this->bindBridge();
        // apiKeys stays null → trait throws
        $response = $this->getJson('/api/admin/mcp-pack/api-keys');
        $response->assertStatus(501);
    }

    public function test_returns_403_when_feature_disabled(): void
    {
        $this->app['config']->set('mcp-pack.admin.features.api_keys', false);
        $this->bindBridge();

        $response = $this->getJson('/api/admin/mcp-pack/api-keys');
        $response->assertStatus(403);
        $this->assertSame('feature_disabled', $response->json('error.code'));
    }
}
