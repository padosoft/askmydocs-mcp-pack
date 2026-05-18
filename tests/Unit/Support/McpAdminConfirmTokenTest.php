<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Unit\Support;

use Padosoft\AskMyDocsMcpPack\Support\McpAdminConfirmToken;
use PHPUnit\Framework\TestCase;

class McpAdminConfirmTokenTest extends TestCase
{
    public function test_mint_produces_tok_prefixed_32_hex_token(): void
    {
        $token = McpAdminConfirmToken::mint(
            scope: McpAdminConfirmToken::SCOPE_AUDIT_REPLAY,
            targetId: 'aud_001',
            tenantId: 'acme',
        );
        $this->assertMatchesRegularExpression('/^tok_[a-f0-9]{32}$/', $token->token);
        $this->assertSame('audit_replay', $token->scope);
        $this->assertSame('aud_001', $token->targetId);
        $this->assertSame('acme', $token->tenantId);
        $this->assertNull($token->usedAt);
    }

    public function test_mint_two_tokens_have_different_plaintext(): void
    {
        // 128 bits of entropy → collision is astronomically unlikely.
        $a = McpAdminConfirmToken::mint('audit_replay', 'aud_001', null);
        $b = McpAdminConfirmToken::mint('audit_replay', 'aud_001', null);
        $this->assertNotSame($a->token, $b->token);
    }

    public function test_mint_default_ttl_is_120_seconds(): void
    {
        $before = time();
        $token = McpAdminConfirmToken::mint('audit_replay', 'aud_001', null);
        $after = time();
        // Allow 1s slack on either side for slow CI.
        $this->assertGreaterThanOrEqual($before + 120, $token->expiresAt);
        $this->assertLessThanOrEqual($after + 121, $token->expiresAt);
    }

    public function test_mint_ttl_floor_is_one_second(): void
    {
        // Negative / zero TTL must clamp to 1s so a sane minimum
        // window always exists.
        $token = McpAdminConfirmToken::mint('audit_replay', 'aud_001', null, ttlSeconds: -5);
        $this->assertGreaterThan(time(), $token->expiresAt);
    }

    public function test_is_expired_returns_true_after_expiry(): void
    {
        $past = time() - 60;
        $token = new McpAdminConfirmToken(
            token: 'tok_' . str_repeat('a', 32),
            scope: 'audit_replay',
            targetId: 'aud_001',
            tenantId: 'acme',
            expiresAt: $past,
        );
        $this->assertTrue($token->isExpired());
    }

    public function test_is_used_reflects_used_at(): void
    {
        $fresh = new McpAdminConfirmToken(
            token: 'tok_' . str_repeat('a', 32),
            scope: 'audit_replay',
            targetId: 'aud_001',
            tenantId: null,
            expiresAt: time() + 120,
        );
        $this->assertFalse($fresh->isUsed());

        $consumed = new McpAdminConfirmToken(
            token: 'tok_' . str_repeat('a', 32),
            scope: 'audit_replay',
            targetId: 'aud_001',
            tenantId: null,
            expiresAt: time() + 120,
            usedAt: time(),
        );
        $this->assertTrue($consumed->isUsed());
    }

    public function test_to_mint_response_carries_replays_in_seconds(): void
    {
        $token = McpAdminConfirmToken::mint('audit_replay', 'aud_001', 'acme', ttlSeconds: 90);
        $response = $token->toMintResponse();
        $this->assertSame($token->token, $response['confirm_token']);
        $this->assertSame('audit_replay', $response['scope']);
        $this->assertSame('aud_001', $response['target_id']);
        $this->assertGreaterThan(0, $response['replays_in_seconds']);
        $this->assertLessThanOrEqual(90, $response['replays_in_seconds']);
    }

    public function test_to_mint_response_clamps_to_zero_when_already_expired(): void
    {
        $past = time() - 60;
        $token = new McpAdminConfirmToken(
            token: 'tok_' . str_repeat('a', 32),
            scope: 'breaker_reset',
            targetId: 'srv-a:kb.search',
            tenantId: null,
            expiresAt: $past,
        );
        $response = $token->toMintResponse();
        $this->assertSame(0, $response['replays_in_seconds']);
    }
}
