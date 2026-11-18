<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERF-BOOTSTRAP-001 — the spend ledger (`SpendLedger::accountDays()`, read by every budget, and by the funding refresher) selects
 * `daily_metrics` by project, `metric_key = 'spend'` and a date range. The table's indexes are `(tenant_id, project_id, metric_date)`
 * and single-column `project_id`, neither of which names the metric, so the planner read every metric of the project's days and
 * discarded the non-spend ones: a sequential scan on the measured 590 k-row seed. With this index the same statement is a bitmap
 * index scan on exactly the spend rows (28 ms -> 14 ms on that seed; the gain grows with the table).
 *
 * Additive: one index, no row read or changed. Plain CREATE INDEX IF NOT EXISTS, like `entity_daily_metrics_account_project_date`:
 * a migration runs in a transaction, so the build holds a write lock on `daily_metrics` for its duration (brief at this size; the
 * measured build was 1.4 s for 590 k rows). `down()` drops only the index named here.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction: syncs keep writing while the index builds. */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS daily_metrics_project_metric_date_idx ON daily_metrics (project_id, metric_key, metric_date)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS daily_metrics_project_metric_date_idx');
    }
};
