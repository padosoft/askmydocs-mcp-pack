<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Defensive guard: when the package is installed on top of a
        // host that already manages an `mcp_tool_call_audit` table
        // (e.g. a Laravel app whose v5.0 schema predates this pack),
        // do not try to recreate it. The host is expected to ALTER
        // its existing table to add the package's columns
        // (`input_hash`, `actor`) and to point `mcp-pack.audit_model`
        // at a subclass that satisfies both schemas — see Recipe 5
        // ("Coexist with a host-owned audit table") in the package
        // README.
        if (Schema::hasTable('mcp_tool_call_audit')) {
            return;
        }

        Schema::create('mcp_tool_call_audit', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('actor', 100)->nullable();
            $table->string('mcp_server_id', 64)->index();
            $table->string('mcp_server_name', 150)->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->unsignedBigInteger('message_id')->nullable()->index();
            $table->string('tool_name', 150);
            $table->char('input_hash', 64);
            $table->char('result_hash', 64)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('status', 32)->default('ok');
            $table->string('error_excerpt', 500)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['tenant_id', 'mcp_server_id', 'tool_name'], 'idx_mcp_audit_tenant_server_tool');
            $table->index(['tenant_id', 'created_at'], 'idx_mcp_audit_tenant_created');
        });
    }

    public function down(): void
    {
        // Symmetric guard to up(): only drop the table when its shape
        // matches the one THIS migration created. A host-owned schema
        // will have at least one column the package never declares
        // (e.g. `input_json_redacted` in AskMyDocs's coexistence
        // model); when any of those host markers are present, do
        // nothing — a rollback that erases host data is far worse
        // than a no-op rollback the operator can clean up manually.
        if (! Schema::hasTable('mcp_tool_call_audit')) {
            return;
        }
        $hostMarkers = ['input_json_redacted', 'user_id', 'error_json'];
        foreach ($hostMarkers as $col) {
            if (Schema::hasColumn('mcp_tool_call_audit', $col)) {
                return;
            }
        }

        Schema::dropIfExists('mcp_tool_call_audit');
    }
};
