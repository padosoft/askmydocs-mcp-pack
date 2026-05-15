<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
        Schema::dropIfExists('mcp_tool_call_audit');
    }
};
