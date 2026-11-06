<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INTEG-SYNC-ENGINE-001 — additive only: one new table, four nullable columns, one partial index. No existing row is touched.
 *
 * `metric_sync_checkpoints` starts EMPTY on purpose. A row exists only because a window landed after this ran; an account with no row
 * behaves exactly as before (the sweep's window, unwidened), so deploying this changes nothing until something has been proved.
 * Backfilling from past successes is Stage F's (gap map batch 9), behind its own dry-run.
 *
 * No cursor column: see `SyncCheckpoints`. The nullable columns on `metric_sync_runs` cost no rewrite on PostgreSQL, and the partial
 * index covers only dead letters, so it is empty at creation (the 25 k-row table is scanned once, in the migration's transaction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_sync_checkpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->uuid('external_account_id');
            $table->string('provider');
            $table->string('grain', 32);
            $table->date('window_from');
            $table->date('window_to');
            $table->timestampTz('completed_at');
            $table->uuid('metric_sync_run_id')->nullable();
            // Days a capped resume moved past without reading them, so a long outage says what it cost.
            $table->date('unread_from')->nullable();
            $table->date('unread_to')->nullable();
            // When a sweep last widened to reach this checkpoint; a stuck grain is not re-read every half hour.
            $table->timestampTz('resume_attempted_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('external_account_id')->references('id')->on('external_accounts')->cascadeOnDelete();
            $table->foreign('metric_sync_run_id')->references('id')->on('metric_sync_runs')->nullOnDelete();
            $table->unique(['external_account_id', 'project_id', 'grain'], 'metric_sync_checkpoints_binding_grain_unique');
        });

        Schema::table('metric_sync_runs', function (Blueprint $table) {
            $table->timestampTz('dead_lettered_at')->nullable();
            $table->string('dead_letter_category', 64)->nullable();
            $table->timestampTz('redriven_at')->nullable();
            $table->unsignedBigInteger('redriven_by')->nullable();
        });

        DB::statement('CREATE INDEX IF NOT EXISTS metric_sync_runs_dead_letters_idx ON metric_sync_runs (dead_lettered_at DESC) WHERE dead_lettered_at IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS metric_sync_runs_dead_letters_idx');

        Schema::table('metric_sync_runs', function (Blueprint $table) {
            $table->dropColumn(['dead_lettered_at', 'dead_letter_category', 'redriven_at', 'redriven_by']);
        });

        Schema::dropIfExists('metric_sync_checkpoints');
    }
};
