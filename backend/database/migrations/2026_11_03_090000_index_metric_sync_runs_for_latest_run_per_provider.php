<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERF — the newest sync run per provider is read on every report build (coverage) and by the freshness stamp.
 *
 * Both filter `tenant_id`, `project_id`, `provider` and order by `window_end DESC, created_at DESC`. The only index that
 * led with those columns was (tenant_id, project_id, status), so Postgres bitmap-scanned every run of the project and
 * sorted them (1.35 M sequential scans on 25 k rows on Production). This index serves the equality prefix and the order.
 *
 * `external_account_id` is deliberately NOT a column: the visibility rule reaches it only through an EXISTS against
 * `project_integration_bindings`, never as a filter on this table (the freshness stamp's optional account ceiling is a
 * short IN list applied after the index narrowed to one project and provider).
 *
 * Additive and idempotent. Not CONCURRENTLY: the table is small (25 k rows) and migrations run in a transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS metric_sync_runs_latest_per_provider_idx ON metric_sync_runs (tenant_id, project_id, provider, window_end DESC, created_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS metric_sync_runs_latest_per_provider_idx');
    }
};
