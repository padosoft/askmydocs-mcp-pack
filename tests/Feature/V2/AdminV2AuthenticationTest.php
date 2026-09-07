<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\V2;

use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

final class AdminV2AuthenticationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mcp-pack.admin_v2.enabled', true);
        $app['config']->set('mcp-pack.admin_v2.middleware', ['api']);
    }

    public function test_default_api_middleware_never_exposes_admin_v2_anonymously(): void
    {
        $this->getJson('/api/admin/mcp-pack/v2/capabilities')
            ->assertForbidden()
            ->assertJsonPath('error', 'admin_authentication_required');
    }
}
