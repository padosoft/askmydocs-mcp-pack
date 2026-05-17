<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.5.0 — OPTIONAL, publishable migration. Hosts that DO NOT already
 * have an API-key store can publish this via
 *
 *   php artisan vendor:publish --tag=mcp-pack-migrations
 *
 * and run `migrate`. Hosts with their own API-key infrastructure
 * (Sanctum tokens, Passport personal-access tokens, custom JWT)
 * ignore this migration and bind their own implementation of
 * `McpHostBridgeContract::{listApiKeys,createApiKey,revokeApiKey}()`.
 *
 * Schema:
 *   - `id` is a STRING primary key (`tok_*` format) — matches the
 *     wire contract on `HostApiKey::$id` and the URL regex in
 *     `AskMyDocsMcpPackServiceProvider::registerAdminRoutes()` which
 *     accepts `[A-Za-z0-9._\-]+`. Hosts mint these via
 *     `Str::random()` + an opaque prefix at creation time.
 *   - `hashed_token` is the SHA-256 (or argon2) digest of the
 *     plaintext token. The plaintext is surfaced exactly once at
 *     creation time and is never persisted.
 *   - `hashed_token` is UNIQUE — collision is treated as a
 *     programmer error (caller must regenerate).
 *   - `scopes` is JSON — list of permission strings the operator
 *     validated against the regex in `CreateApiKeyRequest`.
 *   - `last_used_at` is updated by the host's auth middleware on
 *     every successful token authentication.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_api_keys')) {
            return;
        }

        Schema::create('mcp_api_keys', function (Blueprint $table) {
            // String PK to match the wire contract (`tok_*` ids). Hosts
            // own the id format — the controller / FormRequest do not
            // assume integers anywhere.
            $table->string('id', 64)->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name', 150);
            $table->json('scopes');
            $table->string('hashed_token', 64);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->string('created_by', 255)->nullable();

            $table->unique('hashed_token', 'uq_mcp_api_keys_hashed_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_api_keys');
    }
};
