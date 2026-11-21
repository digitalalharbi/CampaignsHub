<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERF-SYNC-ISOLATION-001 — the live report reads `entity_daily_metrics` by account, project and day
 * range (64 s of one 66 s request was SQL under contention), and only `(project_id, entity_type,
 * metric_date)` served that. Additive: one index, no data read or changed.
 *
 * `creative_daily_metrics (creative_id, metric_date)` needs nothing: the table's UNIQUE constraint on
 * exactly those columns already is that index.
 *
 * Plain CREATE INDEX (not CONCURRENTLY) because a migration runs in a transaction; the table is
 * write-locked for the build, which is brief at this table's size.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS entity_daily_metrics_account_project_date ON entity_daily_metrics (external_account_id, project_id, metric_date)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS entity_daily_metrics_account_project_date');
    }
};
