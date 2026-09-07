<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['mcp_tasks', 'mcp_artifacts', 'mcp_request_states'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("MCP Pack v2 cannot adopt pre-existing table [{$table}]. Rename it or reconcile the schema before installing the package.");
            }
        }
        $auditColumns = ['protocol_version', 'result_type', 'task_id', 'artifact_ids'];
        if (Schema::hasTable('mcp_tool_call_audit')) {
            foreach ($auditColumns as $column) {
                if (Schema::hasColumn('mcp_tool_call_audit', $column)) {
                    throw new RuntimeException("MCP Pack v2 cannot adopt pre-existing audit column [{$column}]. Reconcile the schema before installing the package.");
                }
            }
        }

        Schema::create('mcp_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 100)->nullable()->index();
            $table->string('actor_id', 191)->nullable()->index();
            $table->string('handler', 255);
            $table->string('state', 32)->index();
            $table->longText('request_payload');
            $table->longText('pending_inputs')->nullable();
            $table->longText('input_responses')->nullable();
            $table->longText('result')->nullable();
            $table->longText('error')->nullable();
            $table->longText('output_schema')->nullable();
            $table->text('request_state')->nullable();
            $table->timestamp('expires_at')->index();
            $table->unsignedInteger('poll_interval_ms')->default(1000);
            $table->boolean('cancel_requested')->default(false);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestamps();
            $table->uuid('lease_owner')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->index(['tenant_id', 'actor_id', 'state'], 'idx_mcp_tasks_scope_state');
        });
        Schema::create('mcp_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 100)->nullable()->index();
            $table->string('actor_id', 191)->nullable()->index();
            $table->string('name', 180);
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('disk', 100);
            $table->string('path', 500)->unique();
            $table->json('annotations')->nullable();
            $table->longText('metadata')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'actor_id', 'expires_at'], 'idx_mcp_artifacts_scope_expiry');
        });
        Schema::create('mcp_request_states', function (Blueprint $table): void {
            $table->uuid('nonce')->primary();
            $table->char('tenant_hash', 64);
            $table->char('actor_hash', 64);
            $table->char('payload_digest', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        if (Schema::hasTable('mcp_tool_call_audit')) {
            Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
                $table->string('protocol_version', 20)->nullable();
                $table->string('result_type', 32)->nullable();
                $table->uuid('task_id')->nullable();
                $table->json('artifact_ids')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_request_states');
        Schema::dropIfExists('mcp_artifacts');
        Schema::dropIfExists('mcp_tasks');
        if (Schema::hasTable('mcp_tool_call_audit')) {
            $columns = array_values(array_filter(['protocol_version', 'result_type', 'task_id', 'artifact_ids'], static fn (string $column): bool => Schema::hasColumn('mcp_tool_call_audit', $column)));
            if ($columns !== []) {
                Schema::table('mcp_tool_call_audit', static fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
    }
};
