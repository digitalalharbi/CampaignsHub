<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AGENTS-ENGINE-001 — conversations with agents, kept apart from human messaging.
 *
 * `content` holds the provider's own blocks exactly as returned, so a later turn can replay history
 * unchanged (models that reason between tool calls reject an edited history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->string('agent_key', 40);
            $table->string('title', 160)->nullable();
            $table->string('status', 20)->default('idle');   // idle | thinking | failed
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
        });

        Schema::create('agent_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('thread_id')->index();
            $table->string('role', 12);                        // user | assistant | tool
            $table->jsonb('content');                          // blocks
            $table->text('text')->nullable();                  // the readable part, for the screen
            $table->jsonb('tool_calls')->nullable();           // [{name, input, ok}] for activity
            $table->jsonb('usage')->nullable();
            $table->string('model', 80)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        // Replay order is insertion order; timestamps tie within a second, a sequence never does.
        DB::statement('ALTER TABLE agent_messages ADD COLUMN seq BIGSERIAL');
        DB::statement('CREATE INDEX agent_messages_thread_seq ON agent_messages (thread_id, seq)');
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_messages');
        Schema::dropIfExists('agent_threads');
    }
};
