<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messaging storage: threaded client ⇄ internal-team conversations, optionally tied to a request/project.
 *
 *   - message_threads : one conversation (subject + open/closed lifecycle). last_message_at drives inbox
 *                       ordering. Tenant-scoped; may reference a client workspace / request / project.
 *   - messages        : one posted message. author_type records which side wrote it (client|team|system);
 *                       read_by_client_at / read_by_team_at stamp when each side has seen it (null = unread
 *                       for that side). attachments is an optional metadata array (no file bytes here).
 *
 * Unread is derived, not stored on the thread: a side's unread count is the messages in the thread whose
 * read_by_<side>_at is still null. Posting a message leaves the OTHER side's stamp null (a new unread) and
 * marks the author's own side read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->nullable()->index();
            $table->uuid('request_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->string('subject');
            $table->string('status')->default('open'); // open|closed
            $table->timestampTz('last_message_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('thread_id');
            $table->string('author_type'); // client|team|system
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->jsonb('attachments')->nullable();
            $table->timestampTz('read_by_client_at')->nullable();
            $table->timestampTz('read_by_team_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on('message_threads')->cascadeOnDelete();
            $table->index(['tenant_id', 'thread_id']);
            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
    }
};
