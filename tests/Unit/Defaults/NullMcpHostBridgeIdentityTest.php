<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Defaults;

use PHPUnit\Framework\TestCase;
use Padosoft\AskMyDocsMcpPack\Defaults\NullMcpHostBridge;
use Padosoft\AskMyDocsMcpPack\Exceptions\HostFeatureNotImplementedException;

class NullMcpHostBridgeIdentityTest extends TestCase
{
    public function test_current_user_throws_host_feature_not_implemented(): void
    {
        $bridge = new NullMcpHostBridge();
        $this->expectException(HostFeatureNotImplementedException::class);
        $bridge->currentUser();
    }

    public function test_list_tenants_throws(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        (new NullMcpHostBridge())->listTenants();
    }

    public function test_list_api_keys_throws(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        (new NullMcpHostBridge())->listApiKeys(42);
    }

    public function test_create_api_key_throws(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        (new NullMcpHostBridge())->createApiKey([]);
    }

    public function test_revoke_api_key_throws(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        (new NullMcpHostBridge())->revokeApiKey('tok_01');
    }

    public function test_save_preferences_throws(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        (new NullMcpHostBridge())->savePreferences(42, []);
    }

    public function test_audit_for_throws(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        (new NullMcpHostBridge())->auditFor(1);
    }

    public function test_replay_audit_throws(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        (new NullMcpHostBridge())->replayAudit(1);
    }

    public function test_reset_breaker_throws(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        (new NullMcpHostBridge())->resetBreaker('srv-a', 'kb.search');
    }

    public function test_chat_still_throws_logic_exception_unchanged(): void
    {
        $bridge = new NullMcpHostBridge();
        $this->expectException(\LogicException::class);
        $bridge->chat(new \Padosoft\AskMyDocsMcpPack\Support\HostChatTurn(messages: [], tools: []));
    }

    // ----- v1.5.0 W1.D — resources / prompts / SSE defaults ----------

    public function test_list_resources_throws(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        (new NullMcpHostBridge())->listResources('srv_01');
    }

    public function test_resource_content_throws(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        (new NullMcpHostBridge())->resourceContent('srv_01', 'mcp://x');
    }

    public function test_list_prompts_throws(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        (new NullMcpHostBridge())->listPrompts('srv_01');
    }

    public function test_prompt_detail_throws(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        (new NullMcpHostBridge())->promptDetail('srv_01', 'research_brief');
    }

    public function test_recent_audit_throws(): void
    {
        $this->expectException(HostFeatureNotImplementedException::class);
        (new NullMcpHostBridge())->recentAudit();
    }
}
