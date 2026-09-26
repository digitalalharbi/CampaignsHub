<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Metrics\Support\EntityScope;
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
    /**
     * REACH-DEDUP-001 — reach and frequency are the provider's figures for ONE row, or nothing.
     *
     * A row is one entity on one day (per attribution window), and the provider deduplicated its reach
     * for that day. `SUM(reach)` over a window counts a person reached on two days twice, and
     * `AVG(frequency)` weights a day of a hundred impressions equally with a day of a hundred thousand
     * — neither is a measurement of the window. Where the group is exactly one row the provider's own
     * figure stands; otherwise both are null, which every reader already renders as «—».
     */
    private const SINGLE_ROW_REACH = 'CASE WHEN COUNT(*) = 1 THEN MAX(reach) END AS reach';

    private const SINGLE_ROW_FREQUENCY = 'CASE WHEN COUNT(*) = 1 THEN MAX(frequency) END AS frequency';

    private const MONEY_TRUTH = [
        'spend_withheld_rows' => 'COUNT(*) FILTER (WHERE spend IS NULL AND spend_original IS NOT NULL)',
        'spend_original' => 'SUM(spend_original) FILTER (WHERE spend IS NULL AND spend_original IS NOT NULL)',
        'revenue_withheld_rows' => 'COUNT(*) FILTER (WHERE revenue IS NULL AND revenue_original IS NOT NULL)',
        'revenue_original' => 'SUM(revenue_original) FILTER (WHERE revenue IS NULL AND revenue_original IS NOT NULL)',
        'money_original_currency' => 'MIN(original_currency) FILTER (WHERE (spend IS NULL AND spend_original IS NOT NULL) OR (revenue IS NULL AND revenue_original IS NOT NULL))',
        'money_original_currencies' => 'COUNT(DISTINCT original_currency) FILTER (WHERE (spend IS NULL AND spend_original IS NOT NULL) OR (revenue IS NULL AND revenue_original IS NOT NULL))',
    ];

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
        ?EntityScope $scope = null,
    ): array {
        $select = ['entity_id', 'external_entity_id', 'external_campaign_id', 'external_ad_set_id'];

        foreach (self::SUMS as $column) {
            $select[] = $column === 'reach' ? self::SINGLE_ROW_REACH : "SUM({$column}) AS {$column}";
        }

        $select[] = self::SINGLE_ROW_FREQUENCY;
        $select[] = 'COUNT(DISTINCT metric_date) AS active_days';
        $select[] = 'MAX(metric_date) AS last_active_on';

        foreach (self::MONEY_TRUTH as $alias => $expression) {
            $select[] = "{$expression} AS {$alias}";
        }

        $query = EntityDailyMetric::query()
            ->where('project_id', $projectId)
            ->where('entity_type', $entityType)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()]);

        // ACCOUNT-SCOPE-ISOLATION-001 — only rows of accounts selected for this project.
        BoundAccountVisibility::applyToEntityMetrics($query, 'entity_daily_metrics');

        /*
         * DEMO-LIVE-AGGREGATION-ISOLATION-001, on the same rule as the campaign grain: a scope
         * holding any live row is operational and seeded rows are excluded from its totals. A demo
         * project keeps its own figures because they are all it has.
         */
        if ($this->hasLiveRows($projectId, $entityType)) {
            $query->where('is_demo', false);
        }

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

        $this->applyScope($query, $scope);

        $rows = $query
            ->groupBy('entity_id', 'external_entity_id', 'external_campaign_id', 'external_ad_set_id')
            ->selectRaw(implode(', ', $select))
            ->get()
            ->map(fn ($row): array => $this->shape((array) $row->getAttributes()))
            ->all();

        return $this->named($entityType, $rows);
    }

    /**
     * The PINNED parents, named — HIERARCHY-ENTITY-ANALYTICS-DRILLDOWN, «breadcrumb preserved».
     *
     * The breadcrumb used to be named from the row the reader clicked, held in memory. That is fine
     * for as long as the tab stays open and wrong the moment it is not: reloading a drilled-in
     * address, or opening a bookmarked one, left the crumb with nothing to name the parent from and
     * it fell back to the raw uuid — «مجموعة: 70589c4f-e362-596f-8b12-2ea611ebc7b2» on a page whose
     * whole job is to tell an operator which ad set they are looking at. A three-browser reload test
     * found it; every unit guard passed, because a fixture hands the name over directly and nothing
     * in a fixture is ever lost to a refresh.
     *
     * Named HERE rather than by the page, for the reason `named()` exists one method down: the server
     * already knows which parent it narrowed to, so a second answer assembled from whatever rows the
     * client happens to be holding could only drift from it.
     *
     * **Scoped to the project, unlike `named()`.** `named()` looks up ids that came out of this
     * project's own metric rows, so they are already the reader's. These ids come from the URL, and a
     * reader can type them: an unscoped lookup would answer a pasted foreign id with another
     * project's ad-set name — a name disclosed beside a table that correctly showed no rows. Unknown
     * ids are simply absent from the map and the page keeps its id fallback, which is the honest
     * thing to show for a parent that cannot be named.
     *
     * @param  list<string>  $parentIds
     * @return array<string, string> parent id => name, absent when unknown or unnamed
     */
    public function nameParents(string $projectId, string $level, array $parentIds): array
    {
        $ids = array_values(array_unique(array_filter($parentIds, static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            return [];
        }

        $query = $level === EntityDailyMetric::AD
            ? ExternalAdSet::withoutGlobalScopes()->whereIn('id', $ids)
            : ExternalCampaign::withoutGlobalScopes()->whereIn('id', $ids);

        return $query->where('project_id', $projectId)
            ->pluck('name', 'id')
            ->filter(static fn ($name): bool => is_string($name) && trim($name) !== '')
            ->map(static fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * The row's own name and status — REPORT-DETAIL-PARITY-001.
     *
     * This belonged to the operator's controller, and the client's shared report called the
     * aggregator directly. So the agency's drill-down listed «Riyadh 25-45» and the client's copy of
     * the same table listed twenty-two rows of «—»: the metrics were right, and nobody could tell
     * which ad set any of them was. The comment in `LiveReportService` promised the two surfaces
     * could not disagree about one ad set, and they disagreed about the only part a reader uses to
     * find it.
     *
     * It lives here now because a name is a property of the row, not of one caller's response shape.
     *
     * Null rather than a placeholder: an entity the structure sweep has since removed is a real
     * state, and «Unknown ad set» would hide it behind something that looks like data.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function named(string $entityType, array $rows): array
    {
        $ids = array_values(array_filter(array_column($rows, 'entity_id')));

        if ($ids === []) {
            return $rows;
        }

        $named = $entityType === EntityDailyMetric::AD
            ? ExternalAd::withoutGlobalScopes()->whereIn('id', $ids)
                ->get(['id', 'name', 'status', 'external_id', 'external_ad_set_id', 'external_campaign_id', 'unified_campaign_id'])
            : ExternalAdSet::withoutGlobalScopes()->whereIn('id', $ids)
                ->get(['id', 'name', 'status', 'external_id', 'external_campaign_id', 'unified_campaign_id']);

        $byId = $named->keyBy(static fn ($m): string => (string) $m->getKey());

        /*
         * OBJECTIVE-ANALYTICS-DEPTH-001 — the row says what it was bought FOR.
         *
         * Without this the ad-set and ad tables render one fixed column set for every row whatever its
         * campaign was for: a sales ad set shown frequency and CPM and denied ROAS, an awareness ad set
         * shown a cost per order it was never bought to produce. `ObjectiveFamily::headlineMetrics()`
         * exists to prevent exactly that, and it could not reach this grain because the rows carried no
         * objective to select on.
         *
         * From `unified_campaigns`, through the entity's own `unified_campaign_id` — NOT
         * `external_campaigns.objective`. Both tables carry the column; the external one is what the
         * provider said, and the two diverge the moment an operator corrects a misclassification, which
         * is the correction the whole objective family is built to honour.
         *
         * One query for the whole page, keyed in memory: a lookup per row would make an ad table's cost
         * grow with its own length, and an ad table is the longest one in the product.
         */
        $objectives = UnifiedCampaign::withoutGlobalScopes()
            ->whereIn('id', $named->pluck('unified_campaign_id')->filter()->unique()->values()->all())
            ->pluck('objective', 'id');

        return array_map(static function (array $row) use ($byId, $objectives): array {
            $entity = $byId->get((string) $row['entity_id']);
            $campaignId = $entity?->unified_campaign_id;

            return [
                ...$row,
                'name' => $entity?->name,
                'status' => $entity?->status,
                /*
                 * Null where the entity is not linked to a unified campaign. Unlinked is «no answer»,
                 * not «unknown objective»: a caller deciding a column set has to be able to tell the
                 * two apart, because `unknown` IS a family with its own metrics and this is the absence
                 * of a question rather than an answer to it.
                 */
                'objective' => $campaignId === null ? null : ($objectives[$campaignId] ?? null),
            ];
        }, $rows);
    }

    /**
     * ANALYTICS-FILTER-TRUTH-001 at the entity grain.
     *
     * ## The objective comes from `unified_campaigns`, not from `external_campaigns`
     *
     * Both tables carry an `objective` column, and reading the wrong one is the failure this method
     * is most likely to be rewritten into. `external_campaigns.objective` is what the provider said;
     * `unified_campaigns.objective` is the campaign's objective in this product, and it is what the
     * campaign grain filters on. Reading the provider's copy here would make the ad table disagree
     * with the campaign table directly above it whenever an operator has corrected an objective —
     * two rows on one screen, narrowed by one chip, answering different questions.
     *
     * ## An unmatched campaign filter empties the table
     *
     * The subqueries return nothing when the chosen campaigns have no external campaign, and the
     * `whereIn` then matches no row. That is the answer: «this campaign has no ads» is a fact, and
     * the alternative — an unmatched filter widening back to every ad in the project — is the shape
     * of leak this requirement exists to prevent.
     */
    private function applyScope(mixed $query, ?EntityScope $scope): void
    {
        if ($scope === null || $scope->isEmpty()) {
            return;
        }

        if ($scope->providers !== []) {
            // A column on this table: no join needed, and the same canonical keys the strip uses.
            $query->whereIn('provider', $scope->providers);
        }

        if ($scope->campaigns !== []) {
            $query->whereIn(
                'external_campaign_id',
                ExternalCampaign::query()
                    ->select('id')
                    ->whereIn('unified_campaign_id', $scope->campaigns),
            );
        }

        if ($scope->objectives !== []) {
            $query->whereIn(
                'external_campaign_id',
                ExternalCampaign::query()
                    ->select('id')
                    ->whereIn(
                        'unified_campaign_id',
                        UnifiedCampaign::query()->select('id')->whereIn('objective', $scope->objectives),
                    ),
            );
        }
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
     * @return array<string,mixed>
     */
    private function shape(array $row): array
    {
        $num = static fn (string $key): ?float => is_numeric($row[$key] ?? null) ? (float) $row[$key] : null;

        $out = [
            'entity_id' => (string) ($row['entity_id'] ?? ''),
            'external_id' => (string) ($row['external_entity_id'] ?? ''),
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
