<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Support;

use Illuminate\Support\Facades\DB;

/**
 * ANALYTICS-PROVENANCE-001 on the CREATIVE grain — one rule, in one place.
 *
 * ## The defect
 *
 * `MetricsAggregator` has excluded seeded rows from operational scopes since that defect was found
 * on `daily_metrics`, in its own words: «a seeded row added to them is not a rounding error — it is
 * invented money inside a real total». `creative_daily_metrics` and `entity_daily_metrics` carry the
 * same `is_demo` column and NOTHING read either. Every figure the content library produced, every
 * creative sort order, and the trend sparkline on a client's own report summed real and seeded rows
 * together whenever a scope held both.
 *
 * ## Why this is a class and not a method on each reader
 *
 * Four call sites in three classes ask the same question, and the owner's instruction is explicit
 * about not manufacturing parallelism where one root cause should be fixed once centrally. A policy
 * copied four times is four places to forget it — which is how the creative tables came to be the
 * ones without it while the campaign table had it.
 *
 * ## The policy, derived rather than configured
 *
 * Demo-ness is a fact about ROWS, put there by the seeders — there is no flag on a project or a
 * tenant. So the scope's nature is read from the rows themselves:
 *
 *   - the scope holds any live row  → OPERATIONAL, and demo rows are excluded;
 *   - the scope holds only demo rows → a DEMO, and they are exactly what to show.
 *
 * A live project that somehow acquired demo rows reports its own real numbers, and the demo tenant
 * keeps working. Nothing has to be flagged by hand and no environment variable decides whose money
 * is real.
 *
 * Evaluated across the SCOPE and never the window, for the reason the aggregator gives: if the date
 * range decided, one KPI would mean two different things on two ranges with nothing on screen to
 * say so.
 */
final class CreativeDemoPolicy
{
    /** @var array<string, bool> one existence check per scope, not per query. */
    private static array $memo = [];

    /**
     * Narrow a query on `$table` to live rows, where the creatives in scope have any.
     *
     * @param  list<string>  $creativeIds
     */
    public static function applyToCreatives(mixed $query, string $table, array $creativeIds): void
    {
        if ($creativeIds === [] || ! self::creativesHaveLiveRows($creativeIds)) {
            return;
        }

        $query->where("{$table}.is_demo", false);
    }

    /**
     * The same, for a query whose scope is a PROJECT rather than a known list of creatives — the
     * sort subqueries, which rank every creative a project holds.
     */
    public static function applyToProject(mixed $query, string $table, ?string $projectId): void
    {
        if ($projectId === null || ! self::projectHasLiveRows($projectId)) {
            return;
        }

        $query->where("{$table}.is_demo", false);
    }

    /**
     * @param  list<string>  $creativeIds
     */
    public static function creativesHaveLiveRows(array $creativeIds): bool
    {
        sort($creativeIds);

        return self::$memo['c:'.implode(',', $creativeIds)] ??= DB::table('creative_daily_metrics')
            ->whereIn('creative_id', $creativeIds)
            ->where('is_demo', false)
            ->exists()
            /*
             * Both grains are asked. A creative whose own table is empty is exactly the case the ad
             * grain exists to answer, so deciding «this is a demo scope» from the empty table alone
             * would let seeded rows back into a real account through the other one.
             */
            || DB::table('entity_daily_metrics')
                ->join('external_ads', 'external_ads.id', '=', 'entity_daily_metrics.entity_id')
                ->where('entity_daily_metrics.entity_type', 'ad')
                ->whereIn('external_ads.creative_id', $creativeIds)
                ->where('entity_daily_metrics.is_demo', false)
                ->exists();
    }

    public static function projectHasLiveRows(string $projectId): bool
    {
        return self::$memo['p:'.$projectId] ??= DB::table('creative_daily_metrics')
            ->where('project_id', $projectId)
            ->where('is_demo', false)
            ->exists()
            || DB::table('entity_daily_metrics')
                ->where('project_id', $projectId)
                ->where('entity_type', 'ad')
                ->where('is_demo', false)
                ->exists();
    }

    /** Tests build a world per case; the memo must not carry one case's answer into the next. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
