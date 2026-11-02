<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-CLUSTER-2 — the natural key of the two metric tables gains `project_id`.
 *
 * Both keys named the account and the campaign / entity but not the project. When an ad account was
 * detached from project A and bound to project B, the next sync of a date A already held hit the
 * old key: the row stayed filed under A (project_id was not in the update list) while its figure,
 * run id and unified campaign were overwritten with B-era values — A's history rewritten after A had
 * lost the account, and B left with no row for that date.
 *
 * With the project in the key, B gets its own row for the same date and A's row is never touched by
 * a sync made for B. History of the old project stays with the old project.
 *
 * Non-destructive: the new indexes are strictly looser than the old constraints (a superset of
 * columns), so every existing row satisfies them, and no row is read, moved, deleted or relabelled.
 * The new index is created BEFORE the old constraint is dropped so uniqueness is never absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS daily_metrics_natural_key_project ON daily_metrics (external_account_id, external_campaign_id, metric_key, metric_date, attribution_window, project_id)');
        DB::statement('ALTER TABLE daily_metrics DROP CONSTRAINT IF EXISTS daily_metrics_natural_key');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS entity_daily_metrics_natural_key_project ON entity_daily_metrics (entity_type, entity_id, metric_date, attribution_window, project_id)');
        DB::statement('ALTER TABLE entity_daily_metrics DROP CONSTRAINT IF EXISTS entity_daily_metrics_natural_key');
    }

    public function down(): void
    {
        // Restoring the narrower keys can fail if a project move has since produced two rows for one
        // account/campaign/day; that is the situation this migration exists to allow.
        DB::statement('ALTER TABLE daily_metrics ADD CONSTRAINT daily_metrics_natural_key UNIQUE (external_account_id, external_campaign_id, metric_key, metric_date, attribution_window)');
        DB::statement('DROP INDEX IF EXISTS daily_metrics_natural_key_project');

        DB::statement('ALTER TABLE entity_daily_metrics ADD CONSTRAINT entity_daily_metrics_natural_key UNIQUE (entity_type, entity_id, metric_date, attribution_window)');
        DB::statement('DROP INDEX IF EXISTS entity_daily_metrics_natural_key_project');
    }
};
