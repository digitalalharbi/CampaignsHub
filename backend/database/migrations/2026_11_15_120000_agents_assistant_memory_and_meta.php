<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AGENTS-ASSISTANT-001 — one assistant in front of the specialists, and a memory that is the person's own.
 *
 * ADDITIVE ONLY. Every existing conversation, message, routine, proposal and the workspace's retention setting keep working as
 * they are: the new columns are nullable or defaulted, nothing is renamed, nothing is rewritten.
 *
 * - `agent_threads.pinned` / `context`: the rail's pin and the context chips (period, campaign) the conversation was asked in.
 * - `agent_messages.specialist`: which specialist answered an assistant turn (the chip on the bubble).
 * - `agent_messages.meta`: what the CODE derived from the tool results of that turn — the data cards, the sources (tool + period)
 *   and the proposals it raised. Never the model's own words, so a card can never show a figure the tools did not return.
 * - `agent_memories`: a person's standing preferences, scoped to user + workspace, saved only when the person asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_threads', function (Blueprint $table): void {
            $table->boolean('pinned')->default(false);
            $table->jsonb('context')->nullable();
        });

        Schema::table('agent_messages', function (Blueprint $table): void {
            $table->string('specialist', 40)->nullable();
            $table->jsonb('meta')->nullable();
        });

        Schema::create('agent_memories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('text', 280);
            $table->string('source', 20)->default('user');   // user (asked «تذكّر») | suggested (accepted a chip)
            $table->timestampsTz();

            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_memories');
        Schema::table('agent_messages', function (Blueprint $table): void {
            $table->dropColumn(['specialist', 'meta']);
        });
        Schema::table('agent_threads', function (Blueprint $table): void {
            $table->dropColumn(['pinned', 'context']);
        });
    }
};
