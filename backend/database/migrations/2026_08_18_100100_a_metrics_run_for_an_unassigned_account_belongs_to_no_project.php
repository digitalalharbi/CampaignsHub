<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * METRICS-RUN-PROJECT-001 — `metric_sync_runs.project_id` becomes nullable, and that is the point.
 *
 * ## What NOT NULL was actually forcing
 *
 * The column's own comment said it plainly: «an account that feeds nothing yet still needs a project
 * to file the run under (the column is NOT NULL and a run with no home would be unreadable)». So the
 * constraint was the REASON the syncer fell back to the tenant's oldest project — a schema rule
 * forced a claim the data could not support, and the claim leaked one agency client's sync history
 * into another's, because `MetricSyncRun` is project-scoped and read back that way.
 *
 * A run for an account nobody has assigned belongs to no project. Saying so is the honest answer,
 * and `integration_sync_runs.project_id` has been nullable for exactly this reason since the
 * structure syncer was corrected — this is the metrics side catching up.
 *
 * Additive and non-destructive: relaxing NOT NULL invalidates no existing row, and nothing is
 * rewritten. Rows already filed under a wrongly-chosen project are LEFT ALONE — they are a record of
 * what the product did, and silently re-attributing history would replace one unfounded claim with
 * another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_sync_runs', function (Blueprint $table): void {
            $table->uuid('project_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Deliberately not reinstated.
         *
         * Reversing this would require inventing a project for every run that honestly has none,
         * which is the defect this migration exists to remove. A down() that recreates a bug is worse
         * than one that declines to.
         */
    }
};
