<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Metrics\Models\EntityDailyMetric;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ANALYTICS-DRILLDOWN-001 — totals for the ad-squad and ad rungs, on the same terms as every other.
 *
 * ## Why this is its own aggregator
 *
 * `MetricsAggregator` reads `daily_metrics`, whose rows are one metric each — a tall
 * `(metric_key, value)` shape it pivots. `entity_daily_metrics` is wide: one row per entity-day
 * with a column per measure. Forcing one class to do both would mean every query branching on
 * shape, and the pivot logic is the most delicate part of the existing aggregator.
 *
 * What IS shared is the contract: the same money-truth field names, the same demo-isolation policy,
 * the same refusal to coalesce a withheld figure to zero. A reader cannot tell which table answered.
 *
 * ## The money rules, restated because they are the ones that get lost
 *
 * Sums are NOT coalesced. `SUM()` over all-NULL is NULL and that is the honest answer — it means
 * «nothing was reported», where `COALESCE(SUM(x), 0)` invents a measured zero. The withheld
 * annotations carry the original amount and its currency so a card can print «412.50 USD ·
 * conversion unavailable» instead of «0 SAR».
 */
final class EntityMetricsAggregator
{
    /** The measures summed per entity. Deliberately un-coalesced — see the class docblock. */
    private const SUMS = [
        'impressions', 'reach', 'clicks', 'landing_page_views', 'engagements',
        'video_views', 'video_views_2s', 'video_views_5s', 'video_views_15s',
        'video_p25', 'video_p50', 'video_p75', 'video_p100', 'video_watch_seconds',
        'conversions', 'purchases', 'add_to_cart', 'checkout',
        'leads', 'sign_ups', 'installs', 'app_opens', 'page_views',
        'spend', 'revenue',
    ];

    /**
     * The withheld half of the money, in the canonical contract's own field names.
     *
     * The same keys `MetricsAggregator` and `CreativeMetrics` emit, so `lib/money/contract.ts`
     * renders an ad squad's spend through the identical reader it uses for a dashboard KPI.
     */
    private const MONEY_TRUTH = [
        'spend_withheld_rows' => 'COUNT(*) FILTER (WHERE spend IS NULL AND spend_original IS NOT NULL)',
        'spend_original' => 'SUM(spend_original) FILTER (WHERE spend IS NULL AND spend_original IS NOT NULL)',
        'revenue_withheld_rows' => 'COUNT(*) FILTER (WHERE revenue IS NULL AND revenue_original IS NOT NULL)',
        'revenue_original' => 'SUM(revenue_original) FILTER (WHERE revenue IS NULL AND revenue_original IS NOT NULL)',
        'money_original_currency' => 'MIN(original_currency) FILTER (WHERE (spend IS NULL AND spend_original IS NOT NULL) OR (revenue IS NULL AND revenue_original IS NOT NULL))',
        'money_original_currencies' => 'COUNT(DISTINCT original_currency) FILTER (WHERE (spend IS NULL AND spend_original IS NOT NULL) OR (revenue IS NULL AND revenue_original IS NOT NULL))',
    ];

    /**
     * The report scope, held the way `MetricsAggregator` holds it: null means «unbounded».
     *
     * REPORT-ADSET-001 — these exist because a report applies its scope ONCE, to one engine, and every
     * section reads that bounded engine. An ad-squad table that read this aggregator unbounded would
     * contradict the KPI cards directly above it on a report scoped to one platform, and the reader
     * would have no way to tell which of the two numbers was the real one. A scope honoured by some
     * sections and forgotten by the rest is worse than no scope at all.
     *
     * @var list<string>|null
     */
    private ?array $providers = null;

    /** @var list<string>|null */
    private ?array $accountIds = null;

    /** @var list<string>|null */
    private ?array $campaignIds = null;

    /** @var list<string>|null */
    private ?array $adSetIds = null;

    /** @var list<string>|null */
    private ?array $objectives = null;

    /**
     * Return a copy bounded to these providers. Empty → unbounded, matching `MetricsAggregator`.
     *
     * @param  list<string>  $providers
     */
    public function forProviders(array $providers): self
    {
        $clone = clone $this;
        $clone->providers = $providers === [] ? null : array_values($providers);

        return $clone;
    }

    /**
     * @param  list<string>  $accountIds
     */
    public function forAccounts(array $accountIds): self
    {
        $clone = clone $this;
        $clone->accountIds = $accountIds === [] ? null : array_values($accountIds);

        return $clone;
    }

    /**
     * @param  list<string>  $campaignIds
     */
    public function forCampaigns(array $campaignIds): self
    {
        $clone = clone $this;
        $clone->campaignIds = $campaignIds === [] ? null : array_values($campaignIds);

        return $clone;
    }

    /**
     * Bound to these ad squads.
     *
     * At the `ad` grain this narrows by PARENT, and at the `ad_set` grain by the squad's own id — the
     * same list means «these squads» in both cases, which is what a reader who picked them expects.
     *
     * @param  list<string>  $adSetIds
     */
    public function forAdSets(array $adSetIds): self
    {
        $clone = clone $this;
        $clone->adSetIds = $adSetIds === [] ? null : array_values($adSetIds);

        return $clone;
    }

    /**
     * Bound to campaigns carrying these objectives.
     *
     * Objective lives on the campaign, not on the metric row, so this resolves through
     * `external_campaign_id` exactly as `MetricsAggregator::forObjectives()` does.
     *
     * @param  list<string>  $objectives
     */
    public function forObjectives(array $objectives): self
    {
        $clone = clone $this;
        $clone->objectives = $objectives === [] ? null : array_values($objectives);

        return $clone;
    }

    /**
     * One row per entity of this grain, for a project and window.
     *
     * @param  list<string>|null  $parentIds  narrow to children of these parents, for drill-down
     * @return list<array<string,mixed>>
     */
    public function byEntity(
        string $projectId,
        string $entityType,
        Carbon $from,
        Carbon $to,
        ?array $parentIds = null,
        ?string $attributionWindow = null,
    ): array {
        $select = ['entity_id', 'external_entity_id', 'external_campaign_id', 'external_ad_set_id'];

        foreach (self::SUMS as $column) {
            $select[] = "SUM({$column}) AS {$column}";
        }

        // Frequency is an average of a ratio, never a sum: adding daily frequencies produces a
        // number that grows with the length of the window and means nothing.
        $select[] = 'AVG(frequency) AS frequency';
        $select[] = 'COUNT(DISTINCT metric_date) AS active_days';
        $select[] = 'MAX(metric_date) AS last_active_on';

        foreach (self::MONEY_TRUTH as $alias => $expression) {
            $select[] = "{$expression} AS {$alias}";
        }

        $query = EntityDailyMetric::query()
            ->where('project_id', $projectId)
            ->where('entity_type', $entityType)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()]);

        /*
         * DEMO-LIVE-AGGREGATION-ISOLATION-001, on the same rule as the campaign grain: a scope
         * holding any live row is operational and seeded rows are excluded from its totals. A demo
         * project keeps its own figures because they are all it has.
         */
        if ($this->hasLiveRows($projectId, $entityType)) {
            $query->where('is_demo', false);
        }

        $this->bound($query, $entityType);

        if ($parentIds !== null) {
            // Empty set → match nothing, never «all». A drill-down into a campaign with no ad squads
            // must show none, not every ad squad in the project.
            $column = $entityType === EntityDailyMetric::AD ? 'external_ad_set_id' : 'external_campaign_id';
            $query->whereIn($column, $parentIds ?: ['00000000-0000-0000-0000-000000000000']);
        }

        if ($attributionWindow !== null) {
            // Two windows are two measurements; mixing them in one total is a fabricated figure.
            $query->where('attribution_window', $attributionWindow);
        }

        $rows = $query
            ->groupBy('entity_id', 'external_entity_id', 'external_campaign_id', 'external_ad_set_id')
            ->selectRaw(implode(', ', $select))
            ->get();

        $names = $this->namesFor($entityType, $rows->pluck('entity_id')->all());

        return $rows
            ->map(fn ($row): array => $this->shape((array) $row->getAttributes(), $names))
            ->all();
    }

    /**
     * Narrow a query by the report scope. An empty set never means «all» — see below.
     *
     * @param  Builder<EntityDailyMetric>  $query
     */
    private function bound(Builder $query, string $entityType): void
    {
        /*
         * A bound that resolved to an empty list matches NOTHING, and `whereIn` with `[]` already does
         * exactly that. The dangerous direction is the other one: treating «the scope named these and
         * none of them exist» as «show everything», which is how a report scoped away from a platform
         * ends up printing it. `null` is the only thing that means unbounded here, and it is set only
         * by an explicitly empty argument to the setters above.
         */
        if ($this->providers !== null) {
            $query->whereIn('provider', $this->providers);
        }

        if ($this->accountIds !== null) {
            $query->whereIn('external_account_id', $this->accountIds);
        }

        if ($this->campaignIds !== null) {
            /*
             * The scope speaks UNIFIED campaign ids; this table stores EXTERNAL ones.
             *
             * `MetricsAggregator` filters `daily_metrics.unified_campaign_id`, and
             * `ReportScope::resolvedCampaignIds()` is built to match it — including
             * `campaignIdsBehindAdSetsAndAds()`, which plucks `unified_campaign_id` outright. But
             * `entity_daily_metrics.external_campaign_id` is `ExternalAdSet::external_campaign_id`,
             * which is an `external_campaigns` row id. Comparing the two id spaces directly matches
             * nothing, and «nothing» here does not read as a bug: the ad-squad table would render its
             * honest «the platform reported no ad squads» empty state on every scoped report, which is
             * a false statement about the platform rather than a visible error.
             */
            $query->whereIn(
                'external_campaign_id',
                DB::table('external_campaigns')
                    ->select('id')
                    ->whereIn('unified_campaign_id', $this->campaignIds),
            );
        }

        if ($this->adSetIds !== null) {
            // The squad's own id at the squad grain, its parent at the ad grain.
            $query->whereIn(
                $entityType === EntityDailyMetric::AD_SET ? 'entity_id' : 'external_ad_set_id',
                $this->adSetIds,
            );
        }

        if ($this->objectives !== null) {
            // Objective lives on the campaign. This subquery selects `external_campaigns.id`, which IS
            // the id space this table stores — unlike the campaign bound above, no translation needed.
            $query->whereIn(
                'external_campaign_id',
                DB::table('external_campaigns')
                    ->select('id')
                    ->whereIn('objective', $this->objectives),
            );
        }
    }

    /**
     * The entities' own names, so nothing downstream has to print an identifier.
     *
     * `entity_daily_metrics` stores `external_entity_id` — the provider's id, like `sq-8f21c0`. It is
     * the right thing to KEY on and the wrong thing to show: a client report captioned «sq-8f21c0» is
     * a raw key in visible UI, and the reader cannot tell which ad squad it means. Loaded in one
     * query per grain rather than joined, because the grouped aggregate above must not gain a row per
     * name collision.
     *
     * A missing name is left absent rather than filled with the id: the caller decides how to say
     * «this squad has no name on file», and substituting the key would hide that it happened.
     *
     * @param  list<string>  $entityIds
     * @return array<string, string>
     */
    private function namesFor(string $entityType, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        $table = $entityType === EntityDailyMetric::AD ? 'external_ads' : 'external_ad_sets';

        return DB::table($table)
            ->whereIn('id', $entityIds)
            ->pluck('name', 'id')
            ->map(static fn ($name): string => (string) $name)
            ->all();
    }

    /** Whether this scope holds any real row — see DEMO-LIVE-AGGREGATION-ISOLATION-001. */
    private function hasLiveRows(string $projectId, string $entityType): bool
    {
        return DB::table('entity_daily_metrics')
            ->where('project_id', $projectId)
            ->where('entity_type', $entityType)
            ->where('is_demo', false)
            ->exists();
    }

    /**
     * One entity's figures, with the ratios derived only where their inputs allow it.
     *
     * @param  array<string,mixed>  $row
     * @param  array<string,string>  $names
     * @return array<string,mixed>
     */
    private function shape(array $row, array $names = []): array
    {
        $num = static fn (string $key): ?float => is_numeric($row[$key] ?? null) ? (float) $row[$key] : null;

        $out = [
            'entity_id' => (string) ($row['entity_id'] ?? ''),
            'external_id' => (string) ($row['external_entity_id'] ?? ''),
            // Absent, not the id — see `namesFor()`. A caller must be able to tell the two apart.
            'name' => $names[(string) ($row['entity_id'] ?? '')] ?? null,
            'campaign_id' => $row['external_campaign_id'] ?? null,
            'ad_set_id' => $row['external_ad_set_id'] ?? null,
            'active_days' => (int) ($row['active_days'] ?? 0),
            'last_active_on' => $row['last_active_on'] ?? null,
        ];

        foreach (self::SUMS as $column) {
            $out[$column] = $num($column);
        }

        $out['frequency'] = $num('frequency');

        $spend = $num('spend');
        $impressions = $num('impressions');
        $clicks = $num('clicks');

        /*
         * Every ratio is null when its denominator is missing or zero. A ratio over nothing is «we
         * cannot say», and printing 0 for it is the same lie one level down from a withheld spend —
         * «CPA 0» over real money reads as an achievement.
         */
        $out['ctr'] = $this->ratio($clicks, $impressions);
        $out['cpc'] = $this->ratio($spend, $clicks);
        $out['cpm'] = $impressions ? $this->ratio($spend, $impressions / 1000) : null;
        $out['cpa'] = $this->ratio($spend, $num('conversions'));
        $out['cpl'] = $this->ratio($spend, $num('leads'));
        $out['cpi'] = $this->ratio($spend, $num('installs'));
        $out['cpe'] = $this->ratio($spend, $num('engagements'));
        $out['cost_per_view'] = $this->ratio($spend, $num('video_views'));
        $out['cost_per_lpv'] = $this->ratio($spend, $num('landing_page_views'));
        $out['roas'] = $this->ratio($num('revenue'), $spend);
        $out['aov'] = $this->ratio($num('revenue'), $num('purchases'));
        $out['conversion_rate'] = $this->ratio($num('conversions'), $clicks);
        $out['engagement_rate'] = $this->ratio($num('engagements'), $impressions);
        $out['completion_rate'] = $this->ratio($num('video_p100'), $num('video_views'));
        $out['view_rate'] = $this->ratio($num('video_views'), $impressions);

        foreach (array_keys(self::MONEY_TRUTH) as $key) {
            $out[$key] = match ($key) {
                'money_original_currency' => $row[$key] === null ? null : (string) $row[$key],
                'spend_withheld_rows', 'revenue_withheld_rows', 'money_original_currencies' => (int) ($row[$key] ?? 0),
                default => $num($key),
            };
        }

        return $out;
    }

    private function ratio(?float $numerator, ?float $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator == 0.0) {
            return null;
        }

        return $numerator / $denominator;
    }
}
