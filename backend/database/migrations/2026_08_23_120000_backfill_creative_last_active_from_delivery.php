<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SNAP-CREATIVE-METRICS-LIVE-001 — give the already-stored rows their last active day.
 *
 * `UpsertCreativeDailyMetrics` now records delivery, but only for the creatives a sync touches.
 * Production already holds 814 creative metric rows written before that existed, so without this the
 * Creative Library would keep opening on creatives with no numbers until the next sweep happened to
 * cover each one.
 *
 * The same rule as the action, deliberately: a day is delivery when `impressions > 0 OR spend > 0`.
 * A stats row of zeroes records only that we asked about that day, and a creative with no delivering
 * day keeps its NULL rather than being given a date it never earned.
 *
 * Idempotent and additive: it derives every value from rows that already exist, writes no metric,
 * and touches no creative whose figure is already correct. Running it twice changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update(
            'UPDATE external_creatives AS c
                SET last_active_at = d.last_delivery
               FROM (
                    SELECT creative_id, MAX(metric_date)::timestamp AS last_delivery
                      FROM creative_daily_metrics
                     WHERE impressions > 0 OR spend > 0
                  GROUP BY creative_id
               ) AS d
              WHERE c.id = d.creative_id
                AND c.last_active_at IS DISTINCT FROM d.last_delivery'
        );
    }

    /**
     * Deliberately empty.
     *
     * The column held nothing but nulls for live creatives before this ran, so there is no prior
     * state to restore — and blanking it on rollback would destroy the demo seeder's values too.
     */
    public function down(): void {}
};
