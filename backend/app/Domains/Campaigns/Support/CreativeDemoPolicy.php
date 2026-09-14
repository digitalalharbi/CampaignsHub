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

    /*
     * There was an `applyToCreatives()` here, keyed on the id list, and the project form replaced it.
     *
     * Asking per LIST made the policy's cost depend on how many distinct id lists a request happened
     * to build — the library asks for a page, then asks again for something else — so filtering 230
     * creatives ran one more query than filtering 30 and `CreativeHealthFilterScopeTest` failed on
     * exactly that difference. The guard was right: a policy every creative read passes through must
     * have a predictable price.
     *
     * The scope is the project in any case. That is the model `MetricsAggregator` uses for the same
     * question, and it makes the answer one query per request rather than one per list.
     */

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
     * Kept for callers that genuinely hold ids and no project — none today.
     *
     * @param  list<string>  $creativeIds
     */
    public static function creativesHaveLiveRows(array $creativeIds): bool
    {
        sort($creativeIds);

        return self::$memo['c:'.implode(',', $creativeIds)] ??= self::eitherGrainHasLiveRow(
            DB::table('creative_daily_metrics')
                ->whereIn('creative_id', $creativeIds)
                ->where('is_demo', false),
            /*
             * Both grains are asked. A creative whose own table is empty is exactly the case the ad
             * grain exists to answer, so deciding «this is a demo scope» from the empty table alone
             * would let seeded rows back into a real account through the other one.
             */
            DB::table('entity_daily_metrics')
                ->join('external_ads', 'external_ads.id', '=', 'entity_daily_metrics.entity_id')
                ->where('entity_daily_metrics.entity_type', 'ad')
                ->whereIn('external_ads.creative_id', $creativeIds)
                ->where('entity_daily_metrics.is_demo', false),
        );
    }

    public static function projectHasLiveRows(string $projectId): bool
    {
        return self::$memo['p:'.$projectId] ??= self::eitherGrainHasLiveRow(
            DB::table('creative_daily_metrics')
                ->where('project_id', $projectId)
                ->where('is_demo', false),
            DB::table('entity_daily_metrics')
                ->where('project_id', $projectId)
                ->where('entity_type', 'ad')
                ->where('is_demo', false),
        );
    }

    /**
     * Both grains, in ONE round trip.
     *
     * Written as `->exists() || ->exists()` first, which is correct and costs one query or two
     * depending on which way the first answers. `CreativeHealthFilterScopeTest` asserts the library's
     * query count is the SAME over 230 creatives as over 30 — a guard against per-row work — and a
     * check whose cost depends on the data breaks it for a reason that has nothing to do with rows.
     * It also made the policy's price unpredictable, which is the wrong property for something every
     * creative read now goes through.
     *
     * So both questions go in one statement and the database answers once.
     */
    private static function eitherGrainHasLiveRow(mixed $creativeGrain, mixed $adGrain): bool
    {
        /*
         * The two subqueries are inlined as SQL with their bindings, because a query builder cannot
         * be passed as a binding — it has no string form, and Laravel throws rather than guessing.
         */
        $creativeSql = $creativeGrain->select(DB::raw('1'))->limit(1)->toSql();
        $adSql = $adGrain->select(DB::raw('1'))->limit(1)->toSql();

        $row = DB::selectOne(
            "SELECT (EXISTS ({$creativeSql}) OR EXISTS ({$adSql})) AS live",
            [...$creativeGrain->getBindings(), ...$adGrain->getBindings()],
        );

        return (bool) ($row->live ?? false);
    }

    /** Tests build a world per case; the memo must not carry one case's answer into the next. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
