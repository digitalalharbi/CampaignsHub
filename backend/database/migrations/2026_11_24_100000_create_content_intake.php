<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CONTENT-INTAKE-001 — the quality gate for new content (additive).
 *
 *   - content_intake_items  : a piece of NEW content (file, link or copy) on its way to launch, project-scoped.
 *                             The status moves only through IntakeTransitions.
 *   - content_intake_events : the append-only decision trail — actor, time, decision and a NAME SNAPSHOT.
 *   - drive_links           : sync status columns, so the last sync and its error are visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_intake_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploader_name')->nullable();
            $table->string('name');
            $table->string('kind'); // image|video|copy|link
            $table->string('provider')->nullable(); // the platform token (meta|snapchat|tiktok|…)
            $table->string('objective')->nullable(); // an ObjectiveFamily value
            $table->string('placement')->nullable(); // feed|story|reel
            $table->string('status')->default('draft')->index();
            $table->string('source')->default('upload'); // upload|link|drive
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('content_hash', 64)->nullable()->index();
            $table->text('copy_text')->nullable();
            $table->string('headline')->nullable();
            $table->string('cta')->nullable();
            $table->text('destination_url')->nullable();
            $table->string('drive_file_id')->nullable();
            $table->uuid('drive_link_id')->nullable();
            $table->timestampTz('drive_modified_time')->nullable();
            $table->timestampTz('source_changed_at')->nullable();
            $table->jsonb('checks')->nullable();
            $table->string('check_result')->nullable(); // pass|warn|fail
            $table->unsignedSmallInteger('readiness_score')->nullable();
            $table->jsonb('analysis')->nullable();
            $table->jsonb('ai')->nullable();
            $table->timestampTz('analyzed_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decider_name')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestampTz('launched_at')->nullable();
            $table->uuid('linked_creative_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'drive_file_id']);
            $table->index(['tenant_id', 'project_id', 'status']);
        });

        Schema::create('content_intake_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('item_id')->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('item_name');
            $table->jsonb('meta')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('content_intake_items')->cascadeOnDelete();
        });

        // A monotonic order for the trail: timestamps inside one transaction are equal, a sequence is not.
        DB::statement('ALTER TABLE content_intake_events ADD COLUMN seq bigserial');
        DB::statement('CREATE UNIQUE INDEX content_intake_events_seq_unique ON content_intake_events (seq)');

        Schema::table('drive_links', function (Blueprint $table) {
            $table->string('sync_state')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('last_sync_summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('drive_links', function (Blueprint $table) {
            $table->dropColumn(['sync_state', 'last_synced_at', 'last_error', 'last_sync_summary']);
        });
        Schema::dropIfExists('content_intake_events');
        Schema::dropIfExists('content_intake_items');
    }
};
