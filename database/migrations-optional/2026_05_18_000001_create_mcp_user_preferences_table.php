<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.5.0 — OPTIONAL, publishable migration. Hosts that DO NOT already
 * have a user-preferences store can publish this via
 *
 *   php artisan vendor:publish --tag=mcp-pack-migrations
 *
 * and run `migrate`. Hosts with their own preferences table can
 * ignore this migration and bind their own implementation of
 * `McpHostBridgeContract::savePreferences()` on top of it.
 *
 * Schema:
 *   - `(user_id, key)` unique — every preference key appears at most
 *     once per user. The host overwrites by inserting with an upsert.
 *   - `value` is JSON. The wire payload is restricted to JSON-safe
 *     scalars by `UpdatePreferencesRequest::withValidator()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_user_preferences')) {
            return;
        }

        Schema::create('mcp_user_preferences', function (Blueprint $table) {
            $table->id();
            // `user_id` matches the host's `users.id` shape — we keep
            // it as `unsignedBigInteger` for the common case (auto-
            // increment Eloquent ids). Hosts using string ids (UUIDs,
            // Keycloak `sub`) subclass + override the migration.
            $table->unsignedBigInteger('user_id')->index();
            $table->string('key', 128);
            $table->json('value');
            $table->timestamps();

            $table->unique(['user_id', 'key'], 'uq_mcp_user_pref_user_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_user_preferences');
    }
};
