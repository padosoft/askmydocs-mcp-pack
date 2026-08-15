<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Feature\V2;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsMcpPack\Artifacts\Artifact;
use Padosoft\AskMyDocsMcpPack\Contracts\V2\ArtifactManagerContract;
use Padosoft\AskMyDocsMcpPack\Models\McpArtifact;
use Padosoft\AskMyDocsMcpPack\Protocol\McpResult;
use Padosoft\AskMyDocsMcpPack\Tests\TestCase;

final class ArtifactManagerTest extends TestCase
{
    public function test_private_immutable_artifact_is_hashed_scoped_and_embedded_when_small(): void
    {
        Storage::fake('mcp-artifacts');
        config()->set('mcp-pack.artifacts.disk', 'mcp-artifacts');
        $manager = $this->app->make(ArtifactManagerContract::class);
        $artifact = $manager->create(Artifact::make('../report.txt')->mimeType('text/plain')->contents('hello'), 'acme', 'alice');

        $this->assertSame('report.txt', $artifact->name);
        $this->assertSame(hash('sha256', 'hello'), $artifact->sha256);
        $this->assertSame('hello', $manager->contents($manager->read($artifact->getKey(), 'acme', 'alice')));
        $result = McpResult::make()->artifact($artifact, 'hello')->toArray();
        $this->assertSame('resource', $result['content'][0]['type']);
        $this->assertSame('artifact://'.$artifact->getKey(), $result['content'][0]['resource']['uri']);
        $linked = McpResult::make()->artifact($artifact, 'hello', 1)->toArray();
        $this->assertSame('resource_link', $linked['content'][0]['type']);
        $this->assertSame(5, $linked['content'][0]['size']);

        $this->expectException(ModelNotFoundException::class);
        $manager->read($artifact->getKey(), 'other', 'alice');
    }

    public function test_executable_mime_is_rejected(): void
    {
        Storage::fake('mcp-artifacts');
        config()->set('mcp-pack.artifacts.disk', 'mcp-artifacts');
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(ArtifactManagerContract::class)->create(Artifact::make('run.sh')->mimeType('application/x-sh')->contents('#!/bin/sh'), 'acme', 'alice');
    }

    public function test_size_limit_delete_and_prune_are_safe_and_idempotent(): void
    {
        Storage::fake('mcp-artifacts');
        config()->set('mcp-pack.artifacts.disk', 'mcp-artifacts');
        config()->set('mcp-pack.artifacts.max_bytes', 4);
        $manager = $this->app->make(ArtifactManagerContract::class);

        try {
            $manager->create(Artifact::make('too-big.txt')->mimeType('text/plain')->contents('12345'), 'acme', 'alice');
            $this->fail('Oversized artifact should be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('4-byte limit', $e->getMessage());
        }

        config()->set('mcp-pack.artifacts.max_bytes', 100);
        $artifact = $manager->create(Artifact::make('../../private/report.txt')->mimeType('text/plain')->contents('ok'), 'acme', 'alice');
        $path = $artifact->path;
        $this->assertSame('report.txt', $artifact->name);
        $this->assertStringNotContainsString('report.txt', $path);
        $this->assertTrue($manager->delete($artifact->getKey(), 'acme', 'alice'));
        $this->assertSame(1, $manager->prune());
        $this->assertSame(0, $manager->prune());
        Storage::disk('mcp-artifacts')->assertMissing($path);
    }

    public function test_expiry_and_actor_scope_are_rechecked_on_every_read(): void
    {
        Storage::fake('mcp-artifacts');
        config()->set('mcp-pack.artifacts.disk', 'mcp-artifacts');
        $manager = $this->app->make(ArtifactManagerContract::class);
        $artifact = $manager->create(Artifact::make('short.txt')->mimeType('text/plain')->contents('short')->ttl(1), 'acme', 'alice');

        try {
            $manager->read($artifact->getKey(), 'acme', 'mallory');
            $this->fail('Cross-actor artifact lookup should not resolve.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->travel(2)->seconds();
        $this->expectException(ModelNotFoundException::class);
        $manager->read($artifact->getKey(), 'acme', 'alice');
    }

    public function test_signed_download_is_short_lived_attachment_and_nosniff(): void
    {
        // A persistent local fake deliberately has no temporary-URL callback,
        // exercising the package's signed-route fallback.
        Storage::persistentFake('mcp-artifacts');
        config()->set('mcp-pack.artifacts.disk', 'mcp-artifacts');
        $manager = $this->app->make(ArtifactManagerContract::class);
        $artifact = $manager->create(Artifact::make('report.txt')->mimeType('text/plain')->contents('private report'), 'acme', 'alice');

        $url = $manager->temporaryUrl($artifact->getKey(), 'acme', 'alice', 60);
        $response = $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="report.txt"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('private report', $response->streamedContent());
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->travel(61)->seconds();
        $this->get($url)->assertForbidden();
    }

    public function test_signed_download_reapplies_tenant_and_actor_scope(): void
    {
        Storage::persistentFake('mcp-artifacts');
        config()->set('mcp-pack.artifacts.disk', 'mcp-artifacts');
        $manager = $this->app->make(ArtifactManagerContract::class);
        $artifact = $manager->create(Artifact::make('report.txt')->mimeType('text/plain')->contents('private report'), 'acme', 'alice');
        $url = $manager->temporaryUrl($artifact->getKey(), 'acme', 'alice', 60);

        // The signed capability carries the scope it was minted for: the URL never
        // resolves the row by UUID alone, so a row that no longer matches that
        // tenant/actor pair is not served even though the signature is still valid.
        McpArtifact::query()->whereKey($artifact->getKey())->update(['tenant_id' => 'globex']);
        $this->get($url)->assertNotFound();

        // A syntactically valid signature with a forged/missing scope is rejected too.
        $forged = preg_replace('/([?&])scope=[^&]*/', '$1scope=forged', $url);
        $this->assertIsString($forged);
        $this->assertNotSame($url, $forged);
        $this->get($forged)->assertForbidden();
    }
}
