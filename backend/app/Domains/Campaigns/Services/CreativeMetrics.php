<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Support\CreativeDemoPolicy;
use App\Domains\Projects\Context\ProjectContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a creative's numbers mean, and which of them the platform actually sent (§15.4, §15.5, §15.15).
 *
 * ## The two rules this class exists to keep
 *
 * **1. A metric nobody reported is not zero.** `creative_daily_metrics` stores video columns as NULL
 * when the provider does not report them, and this class carries that distinction all the way to the
 * response as `null` plus a `reported` map. A completion rate of 0% beside 40,000 impressions reads
 * as a catastrophic video; «Not provided» reads as what actually happened. SQL makes this easy to get
 * wrong — `SUM()` over all-NULL is NULL, but `COALESCE(SUM(x), 0)` silently invents the zero — so the
 * sums here are deliberately un-coalesced and the null is preserved.
 *
 * **2. A creative is judged by its campaign's objective.** An awareness video has no CPA, and
 * printing one for it is not a harmless extra column: it is a terrible number attached to content
 * that was never asked to sell, and it is what makes somebody switch off the top of their funnel.
 * `headline()` returns the metrics that mean something for the creative's marketing path, and
 * `comparable()` refuses to rank two creatives on one axis when they are doing different jobs.
 */
final class CreativeMetrics
{
    /**
     * Every column summed per creative. Keys are the response's own names.
     *
     * NOT wrapped in COALESCE: a null sum is the answer when the provider reported nothing, and this
     * is the one place in the aggregation where losing that distinction costs the reader the truth.
     */
    /**
     * Every ratio {@see self::derive()} computes — the other half of what this service can supply.
     *
     * Named rather than inferred because `supportable()` has to know the difference between «this
     * table has no such column» and «this is computed from columns it does have».
     *
     * @var list<string>
     */
    private const DERIVED = [
        'ctr', 'cpc', 'cpm', 'cpa', 'roas', 'conversion_rate', 'aov', 'cost_per_view',
        'view_rate', 'completion_rate', 'hook_rate', 'cost_per_lpv', 'engagement_rate',
        'cpe', 'orders',
    ];

    /**
     * Derived figures whose NUMERATOR only the ad grain has — CONTENT-KPI-COVERAGE-002.
     *
     * `cpl` is spend over leads and `cpi` is spend over installs, and `creative_daily_metrics` has
     * neither column. Putting them in `DERIVED` would make them producible for every creative, which
     * is a promise of an empty cell on any creative whose figures come from the creative table — the
     * exact thing `ObjectiveAwareKpiTest` guards, and it caught this. They are producible only when
     * the row came from the grain that can answer them.
     */
    private const AD_GRAIN_DERIVED = ['cpl', 'cpi'];

    /**
     * Each ratio's inputs, in the terms `derive()` divides them — for pooling a set without crossing grains.
     *
     * `derive()` stays the only place a ratio's arithmetic is written for a row. This map exists so an
     * aggregate over creatives whose figures were partly filled from their ads (see `fillFromAds()`)
     * can pool each ratio's numerator and denominator from the SAME grain per creative, instead of
     * dividing one grain's spend by another grain's clicks. Numerator keys are tried in order, exactly
     * as `derive()` falls back from `video_p100` to `video_completions`.
     *
     * @var array<string, array{0: list<string>, 1: string, 2: float}>
     */
    private const RATIO_INPUTS = [
        'ctr' => [['clicks'], 'impressions', 1.0],
        'cpc' => [['spend'], 'clicks', 1.0],
        'cpm' => [['spend'], 'impressions', 1000.0],
        'cpa' => [['spend'], 'conversions', 1.0],
        'cpl' => [['spend'], 'leads', 1.0],
        'cpi' => [['spend'], 'installs', 1.0],
        'roas' => [['revenue'], 'spend', 1.0],
        'conversion_rate' => [['conversions'], 'clicks', 1.0],
        'aov' => [['revenue'], 'conversions', 1.0],
        'cost_per_view' => [['spend'], 'video_views', 1.0],
        'view_rate' => [['video_views'], 'impressions', 1.0],
        'completion_rate' => [['video_p100', 'video_completions'], 'video_views', 1.0],
        'hook_rate' => [['video_views_3s'], 'impressions', 1.0],
        'cost_per_lpv' => [['spend'], 'landing_page_views', 1.0],
        'engagement_rate' => [['engagements'], 'impressions', 1.0],
        'cpe' => [['spend'], 'engagements', 1.0],
    ];

    /**
     * Averaged COLUMNS, which are not derived — carried over from CONTENT-KPI-COLLAPSE-001.
     *
     * `frequency` and `video_avg_watch_seconds` sat in {@see self::DERIVED}, and nothing in
     * {@see self::derive()} has ever computed either. They are columns, read as an AVG rather than a
     * SUM because a frequency added across days grows with the length of the window and means nothing.
     *
     * The misfiling still matters even now that selection is availability-aware. `DERIVED` is the
     * PIPELINE filter's answer to «can this service produce the metric at all», and for these two the
     * honest answer is «only if a provider sends the column». Snapchat's creative-grain stats call
     * does not ask for frequency, so the awareness family — most of this account — was promising a
     * cell that could never be filled. Availability now catches that per row; the classification is
     * corrected so the SHAPE is right when no row is in hand.
     *
     * Deriving frequency instead was considered and refused: it is impressions ÷ reach, and `reach`
     * is summed across days, so daily uniques added together over-count the people actually reached
     * and the quotient would be a lower bound presented as a measurement.
     *
     * Both stay in the payload — `shape()` still reads them — so a provider that DOES report them at
     * creative grain is not thrown away. What stops is the headline PROMISING them.
     *
     * @var list<string>
     */
    private const AVERAGED = ['frequency', 'video_avg_watch_seconds'];

    /**
     * How many headline metrics a card is entitled to before it reads as broken.
     *
     * The grid renders `headline_metrics.slice(0, 4)`, so four is not a preference — it is the number
     * the surface asks for, and a family that can answer fewer leaves visible gaps.
     */
    private const HEADLINE_MINIMUM = 4;

    /**
     * The keys whose absence may still be answerable, because FX-001 preserves an original beside a
     * figure it refused to convert.
     *
     * @var list<string>
     */
    private const MONEY = ['spend', 'revenue'];

    private const SUMS = [
        'spend' => 'spend',
        'impressions' => 'impressions',
        'clicks' => 'clicks',
        'conversions' => 'conversions',
        'revenue' => 'revenue',
        'add_to_cart' => 'add_to_cart',
        'checkout' => 'checkout',
        'purchases' => 'purchases',
        'landing_page_views' => 'landing_page_views',
        'engagements' => 'engagements',
        'reach' => 'reach',
        'video_views' => 'video_views',
        'video_views_2s' => 'video_views_2s',
        'video_views_3s' => 'video_views_3s',
        'video_views_6s' => 'video_views_6s',
        'video_p25' => 'video_p25',
        'video_p50' => 'video_p50',
        'video_p75' => 'video_p75',
        'video_p100' => 'video_p100',
        'video_completions' => 'video_completions',
    ];

    /**
     * CONTENT-KPI-COVERAGE-002 — the results a creative table cannot hold, and an ad table can.
     *
     * `SUMS` above is the column list of `creative_daily_metrics`, and every query in this service
     * was written against it. `entity_daily_metrics` — the ad grain the fallback reads when a
     * provider breaks its figures down per ad and not per creative — carries five more: the columns
     * that hold what a campaign was actually bought for.
     *
     * Leaving them out was the owner's reopened defect stated exactly: a Meta lead-gen creative
     * showed spend, clicks and a conversion rate, and no Results and no Cost per result — the two
     * cells that decide whether the creative worked — while the leads sat in the database one join
     * away. They are kept separate from `SUMS` rather than merged into it because the creative-grain
     * queries name their columns unguarded, and `SUM(leads)` against a table without the column is
     * not a missing figure, it is a broken page.
     */
    private const AD_GRAIN_SUMS = [
        'leads' => 'leads',
        'sign_ups' => 'sign_ups',
        'installs' => 'installs',
        'app_opens' => 'app_opens',
        'page_views' => 'page_views',
    ];

    /**
     * CREATIVE-MONEY-TRUTH-001 — the withheld half of the money, in the contract's own field names.
     *
     * These are deliberately the SAME keys `MetricsAggregator::MONEY_TRUTH` emits, because the
     * frontend has one money reader (`lib/money/contract.ts`) and it keys off these names. Matching
     * them is what lets a creative card and a dashboard KPI render an unconvertible figure the same
     * way without a second implementation — the thing that «one contract» was supposed to mean.
     *
     * A row is withheld when its converted value is null AND an original survives. A row the platform
     * never reported has neither, and must not be counted here: «not reported» and «reported but not
     * convertible» are different sentences and the card says different things for them.
     */
    private const MONEY_TRUTH = [
        'spend_withheld_rows' => 'COUNT(*) FILTER (WHERE spend IS NULL AND spend_original IS NOT NULL)',
        'spend_original' => 'SUM(spend_original) FILTER (WHERE spend IS NULL AND spend_original IS NOT NULL)',
        'revenue_withheld_rows' => 'COUNT(*) FILTER (WHERE revenue IS NULL AND revenue_original IS NOT NULL)',
        'revenue_original' => 'SUM(revenue_original) FILTER (WHERE revenue IS NULL AND revenue_original IS NOT NULL)',
        'money_original_currency' => 'MIN(original_currency) FILTER (WHERE (spend IS NULL AND spend_original IS NOT NULL) OR (revenue IS NULL AND revenue_original IS NOT NULL))',
        // The reader refuses to name a currency when this is not exactly 1: several unconvertible
        // currencies cannot be added, and printing one of their names would be a wrong label.
        'money_original_currencies' => 'COUNT(DISTINCT original_currency) FILTER (WHERE (spend IS NULL AND spend_original IS NOT NULL) OR (revenue IS NULL AND revenue_original IS NOT NULL))',
    ];

    /**
     * Totals per creative for a window, with derived KPIs and a map of what was actually reported.
     *
     * @param  list<string>  $creativeIds
     * @return array<string, array<string, mixed>> creative id => figures
     */
    public function forCreatives(array $creativeIds, Carbon $from, Carbon $to): array
    {
        if ($creativeIds === []) {
            return [];
        }

        $out = $this->fromCreativeGrain($creativeIds, $from, $to);

        /*
         * CONTENT-SPEND-ALWAYS-001 — the figures exist one rung up, and nothing was reading them.
         *
         * ## What the owner sees
         *
         * «Spend missing / unavailable on promoted creatives» on a real account. The first rung to
         * check is not the React cell: it is whether the number was ever ingested.
         *
         * ## It was not, for every provider but one
         *
         * `AccountMetricsSyncer` fetches creative-level insights behind
         * `if ($connector instanceof ReportsCreativeInsights)`, and Snapchat is the only implementor.
         * Meta, Google, TikTok, LinkedIn and X never write a `creative_daily_metrics` row, so this
         * table — the only thing the content library read — is empty for them and every card on it
         * shows nothing.
         *
         * This codebase has already met that exact shape one rung above and named it: «one
         * `instanceof` decided that of eight platforms exactly one would ever fill the table … and
         * the product printed our silence as the platform's». That was fixed for the ad and ad-set
         * grains by asking a capability instead of a class, and **Meta implements it** — so a Meta
         * account's ad-grain rows carry spend, impressions, clicks and the rest in
         * `entity_daily_metrics` right now, keyed by the ad that ran the creative, while the card
         * above them says «—».
         *
         * ## So the creative's figures come from its ADS when it has none of its own
         *
         * Not a second opinion and never a blend: a creative with native rows keeps them, because a
         * provider that reports the creative grain is more precise than a sum over the ads that used
         * it. The derivation is for the creatives that have nothing, which is all of them on every
         * provider except Snapchat.
         *
         * Several ads may share one creative — summing them is not double counting, it is what that
         * creative cost. An ad with no `creative_id` contributes nothing, because there is nothing to
         * attribute it to, and a creative whose ads reported nothing still reaches the reader as an
         * absence rather than as a zero.
         */
        /*
         * Content Production Recovery — a creative with BOTH grains keeps its own rows and gains, metric
         * by metric, only what its own rows do not report.
         *
         * ## What Production showed
         *
         * `content:census` (run 35117219746) on the live Snapchat account: 199 creatives with figures,
         * 160 at creative grain, 129 at ad grain, and 90 at BOTH. Letting the creative's own rows «win
         * outright» made the ads invisible for those 90, so 22 cards showed indicators and no Spend
         * (the ads carried it) and 79 lost the metric their objective is judged by — revenue, CPA and
         * AOV on sales, landing-page views on traffic — all of which the same creative's ads reported.
         *
         * ## The three rules, because each is how this could make a number wrong in a new way
         *
         *   1. NEVER SUM THE GRAINS. Both can describe the same delivery; adding them double-counts.
         *      A metric the creative grain reports is taken from the creative grain, full stop — the
         *      existing «not blended» guarantee is unchanged. Only a metric it does NOT report is taken
         *      from the ads.
         *   2. A RATIO COMES WHOLLY FROM ONE GRAIN. CPA over the ads' spend and the creative's orders
         *      would divide two different measurements. Each derived figure is the creative grain's own
         *      when it could compute it, else the ad grain's own, else absent — never recomputed across.
         *   3. ONLY FROM A GRAIN THAT COVERS THE SAME DAYS. An ad grain active on fewer days than the
         *      creative grain would state a partial figure as the creative's whole one, so it fills
         *      nothing.
         *
         * Every filled key is named in `from_ads`, so a surface can say which figures were summed
         * from the ads rather than reported for the creative.
         */
        $ad = $this->fromAdGrain($creativeIds, $from, $to);

        foreach ($ad as $creativeId => $figures) {
            $out[$creativeId] = isset($out[$creativeId])
                ? $this->fillFromAds($out[$creativeId], $figures)
                : $figures;
        }

        return $out;
    }

    /**
     * One creative's own figures, with the metrics they do not report taken from its ads — see the
     * three rules in {@see self::forCreatives()}.
     *
     * @param  array<string, mixed>  $native  shaped creative-grain figures
     * @param  array<string, mixed>  $ads  shaped ad-grain figures for the same creative and window
     * @return array<string, mixed>
     */
    private function fillFromAds(array $native, array $ads): array
    {
        $native['from_ads'] = [];

        if ((int) ($ads['active_days'] ?? 0) < (int) ($native['active_days'] ?? 0)) {
            return $native;
        }

        $merged = $native;
        $filled = [];

        // Raw columns and averaged columns: taken only where the creative grain reported nothing.
        foreach ([...array_keys(self::SUMS), ...array_keys(self::AD_GRAIN_SUMS), ...self::AVERAGED] as $key) {
            if (in_array($key, self::MONEY, true)) {
                continue;
            }

            if (($native[$key] ?? null) === null && ($ads[$key] ?? null) !== null) {
                $merged[$key] = $ads[$key];
                $merged['reported'][$key] = true;
                $filled[] = $key;
            }
        }

        // Money: taken with its whole provenance, and only where the creative grain cannot state it.
        $moneyFrom = [];
        foreach (self::MONEY as $key) {
            $moneyFrom[$key] = 'native';

            if (! $this->answerable($native, $key) && $this->answerable($ads, $key)) {
                $merged[$key] = $ads[$key] ?? null;
                $merged[$key.'_withheld_rows'] = (int) ($ads[$key.'_withheld_rows'] ?? 0);
                $merged[$key.'_original'] = $ads[$key.'_original'] ?? null;
                $merged['reported'][$key] = ($ads[$key] ?? null) !== null;
                $moneyFrom[$key] = 'ads';
                $filled[] = $key;
            }
        }

        // The original currency now describes whichever grain each withheld money figure came from.
        $currencies = [];
        $several = false;
        foreach (self::MONEY as $key) {
            $source = $moneyFrom[$key] === 'ads' ? $ads : $native;

            if ((int) ($source[$key.'_withheld_rows'] ?? 0) === 0) {
                continue;
            }

            $several = $several || (int) ($source['money_original_currencies'] ?? 0) > 1;

            if (is_string($source['money_original_currency'] ?? null)) {
                $currencies[$source['money_original_currency']] = true;
            }
        }
        $merged['money_original_currency'] = count($currencies) === 1 && ! $several ? (string) array_key_first($currencies) : null;
        $merged['money_original_currencies'] = $several ? max(2, count($currencies)) : count($currencies);

        // Ratios: the creative grain's own, else the ad grain's own — never recomputed across grains.
        // The inputs travel with the choice, so an aggregate over this creative pools the same grain.
        $merged['ratio_inputs'] = [];
        foreach ([...self::DERIVED, ...self::AD_GRAIN_DERIVED] as $key) {
            $source = null;

            if (($native[$key] ?? null) !== null) {
                $source = $native;
            } elseif (($ads[$key] ?? null) !== null) {
                $merged[$key] = $ads[$key];
                $filled[] = $key;
                $source = $ads;
            } else {
                $merged[$key] = null;
            }

            if (isset(self::RATIO_INPUTS[$key])) {
                $merged['ratio_inputs'][$key] = $source === null ? [null, null] : $this->ratioInputs($source, $key);
            }
        }

        $merged['reported']['orders'] = ($merged['conversions'] ?? null) !== null;
        $merged['active_days'] = max((int) ($native['active_days'] ?? 0), (int) ($ads['active_days'] ?? 0));
        $merged['from_ads'] = array_values(array_unique($filled));

        return $merged;
    }

    /**
     * One row's numerator and denominator for a ratio, or nulls when either is missing.
     *
     * @param  array<string, mixed>  $figures
     * @return array{0: float|null, 1: float|null}
     */
    private function ratioInputs(array $figures, string $key): array
    {
        [$numerators, $denominator, $divisor] = self::RATIO_INPUTS[$key];

        $numerator = null;
        foreach ($numerators as $candidate) {
            if (is_numeric($figures[$candidate] ?? null)) {
                $numerator = (float) $figures[$candidate];
                break;
            }
        }

        $below = is_numeric($figures[$denominator] ?? null) ? (float) $figures[$denominator] / $divisor : null;

        return $numerator === null || $below === null ? [null, null] : [$numerator, $below];
    }

    /**
     * A creative's figures as the platform reported them FOR THE CREATIVE — `creative_daily_metrics`.
     *
     * Extracted from `forCreatives()` unchanged, so the content census can ask each grain on its own
     * through the very query the product runs rather than through a copy of it.
     *
     * @param  list<string>  $creativeIds
     * @return array<string, array<string, mixed>>
     */
    private function fromCreativeGrain(array $creativeIds, Carbon $from, Carbon $to): array
    {
        if ($creativeIds === []) {
            return [];
        }

        $select = ['creative_id'];
        foreach (self::SUMS as $alias => $column) {
            $select[] = "SUM({$column}) AS {$alias}";
        }
        // Frequency is an average of a ratio, not a sum: adding daily frequencies would produce a
        // number that grows with the length of the window and means nothing.
        $select[] = 'AVG(frequency) AS frequency';
        $select[] = 'AVG(video_avg_watch_seconds) AS video_avg_watch_seconds';
        $select[] = 'COUNT(DISTINCT metric_date) AS active_days';

        foreach (self::MONEY_TRUTH as $alias => $expression) {
            $select[] = "{$expression} AS {$alias}";
        }

        $rows = DB::table('creative_daily_metrics')
            ->whereIn('creative_id', $creativeIds)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => app(CreativeDemoPolicy::class)->applyToProject($q, 'creative_daily_metrics', app(ProjectContext::class)->projectId()))
            ->groupBy('creative_id')
            ->selectRaw(implode(', ', $select))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $figures = $this->shape((array) $row);
            /*
             * Where the number came from, carried with it.
             *
             * `creative` is the platform reporting this creative directly. `ad` is a sum over the ads
             * that ran it — the same money, attributed rather than reported, and a surface is
             * entitled to say which it is holding. The alternative is a figure whose provenance only
             * the query knows, which is how «trustworthy» becomes unanswerable.
             */
            $figures['grain'] = 'creative';
            $out[(string) $row->creative_id] = $figures;
        }

        return $out;
    }

    /**
     * Both grains for the same creatives, kept APART — the question `forCreatives()` never asks.
     *
     * `forCreatives()` answers from a creative's own rows when it has any and from its ads only when
     * it has none, and that is the right rule for a card. It is also the rule that makes one class of
     * defect invisible from the card: a creative whose platform reports spend at creative grain while
     * the RESULTS it was bought for sit on its ads. The content census asks both grains separately,
     * through the same two queries, so it can say which rung a missing figure fell off.
     *
     * @param  list<string>  $creativeIds
     * @return array{creative: array<string, array<string, mixed>>, ad: array<string, array<string, mixed>>}
     */
    public function byGrain(array $creativeIds, Carbon $from, Carbon $to): array
    {
        return [
            'creative' => $this->fromCreativeGrain($creativeIds, $from, $to),
            'ad' => $this->fromAdGrain($creativeIds, $from, $to),
        ];
    }

    /**
     * Whether a ratio is absent here because its denominator was REPORTED as zero — cost per nothing.
     *
     * A CPA over zero orders is not a missing figure the other grain «answers»; it is a figure that
     * does not exist for this row, and «—» is its truthful reading. The content census asks this so it
     * does not report an arithmetic absence as a lost metric.
     *
     * @param  array<string, mixed>  $figures
     */
    public function undefinedOverAReportedZero(array $figures, string $key): bool
    {
        if (! isset(self::RATIO_INPUTS[$key])) {
            return false;
        }

        $denominator = self::RATIO_INPUTS[$key][1];

        return is_numeric($figures[$denominator] ?? null) && (float) $figures[$denominator] === 0.0;
    }

    /**
     * Whether a surface can STATE this figure for this row — the card's own test, made askable.
     *
     * Delegates to the private rule `headline()` filters by, including the money contract's withheld
     * case, so a diagnostic asking «does this card show spend» gets the product's answer rather than
     * a second opinion about what withheld money means.
     *
     * @param  array<string, mixed>  $figures
     */
    public function statable(array $figures, string $key): bool
    {
        return $this->answerable($figures, $key);
    }

    /*
     * The demo policy lives in `CreativeDemoPolicy`, not here.
     *
     * It was a private pair of methods on this class first, and that was already the second copy of
     * a rule `MetricsAggregator` states for `daily_metrics`. Four call sites across three classes ask
     * it; a policy copied four times is four places to forget it, which is how the creative tables
     * came to be the ones without it.
     */

    /**
     * A creative's totals summed from the ad grain, for creatives with no rows of their own.
     *
     * The column list is deliberately the INTERSECTION of the two tables rather than everything
     * either holds: `video_completions` exists on the creative table and not on the entity one, and
     * inventing a 0 for it here would be the fabricated zero this product refuses everywhere else.
     * A metric the ad grain does not carry stays absent, and the reader renders «—».
     *
     * @param  list<string>  $creativeIds
     * @return array<string, array<string, mixed>>
     */
    private function fromAdGrain(array $creativeIds, Carbon $from, Carbon $to): array
    {
        if ($creativeIds === []) {
            return [];
        }

        $entityColumns = Schema::getColumnListing('entity_daily_metrics');

        $select = ['external_ads.creative_id as creative_id'];

        foreach ([...self::SUMS, ...self::AD_GRAIN_SUMS] as $alias => $column) {
            if (in_array($column, $entityColumns, true)) {
                $select[] = "SUM(entity_daily_metrics.{$column}) AS {$alias}";
            }
        }

        if (in_array('frequency', $entityColumns, true)) {
            $select[] = 'AVG(entity_daily_metrics.frequency) AS frequency';
        }

        $select[] = 'COUNT(DISTINCT entity_daily_metrics.metric_date) AS active_days';

        foreach (self::MONEY_TRUTH as $alias => $expression) {
            $select[] = str_replace(
                ['spend', 'revenue', 'original_currency'],
                ['entity_daily_metrics.spend', 'entity_daily_metrics.revenue', 'entity_daily_metrics.original_currency'],
                $expression,
            )." AS {$alias}";
        }

        $rows = DB::table('entity_daily_metrics')
            ->join('external_ads', 'external_ads.id', '=', 'entity_daily_metrics.entity_id')
            ->where('entity_daily_metrics.entity_type', 'ad')
            ->whereIn('external_ads.creative_id', $creativeIds)
            ->where(fn ($q) => app(CreativeDemoPolicy::class)->applyToProject($q, 'entity_daily_metrics', app(ProjectContext::class)->projectId()))
            ->whereBetween('entity_daily_metrics.metric_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('external_ads.creative_id')
            ->selectRaw(implode(', ', $select))
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $figures = $this->shape((array) $row);
            $figures['grain'] = 'ad';
            $out[(string) $row->creative_id] = $figures;
        }

        return $out;
    }

    /**
     * CONTENT-KPI-TOTALS-001 — the figures for a whole filtered scope, not one creative.
     *
     * «The Content area currently does not visibly expose the required KPI figures consistently …
     * never turn unavailable data into zero.» The library could show a card per creative and had no
     * row of totals at all, so «what did this filter cost» was a question the page could not answer.
     *
     * Read over the SAME ids the list was narrowed to, in ONE query, and shaped by the same `shape()`
     * every card uses — so the strip and the cards beneath it cannot derive a rate differently. The
     * obvious alternative, calling the project SUMMARY endpoint, would have answered a different
     * question: that scope knows nothing about creative kinds, ad ids or fatigue, so the headline
     * would have described a wider set than the cards under it.
     *
     * Derived ratios are recomputed from the pooled sums inside `shape()`, never averaged across
     * creatives — the rule CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 holds everywhere else in this
     * product, and a CTR that is the mean of per-creative CTRs is a different number from the
     * account's CTR.
     *
     * @param  list<string>  $creativeIds
     * @return array<string, mixed>|null null when the scope holds no reported day at all
     */
    public function totalsFor(array $creativeIds, Carbon $from, Carbon $to): ?array
    {
        if ($creativeIds === []) {
            return null;
        }

        /*
         * Owner defect 95 — the strip reads exactly what the cards read, because it IS what they read.
         *
         * ## What this used to be, and why it was the owner's sentence
         *
         * A second SQL projection over `creative_daily_metrics`. Two consequences, and both are
         * «sometimes Spend appears and the other KPIs disappear» said mechanically:
         *
         *   · NO AD-GRAIN FALLBACK. `forCreatives()` reads a creative's own rows and falls back to
         *     the ads that ran it, because `AccountMetricsSyncer` asks for creative-level insights
         *     behind `instanceof ReportsCreativeInsights` and Snapchat is the only implementor. So on
         *     a Meta, Google, TikTok, LinkedIn or X account the cards carried spend and the strip
         *     directly above them returned null — the figures appearing and vanishing in one
         *     viewport, which is what the owner was looking at.
         *   · NO DEMO POLICY. `forCreatives()` applies `CreativeDemoPolicy` and this did not, so on a
         *     project holding seeded rows beside real ones the strip and the cards summed different
         *     sets. `ANALYTICS-PROVENANCE-001` calls that «invented money inside a real total», and
         *     the strip was the element carrying it.
         *
         * ## Why delegation rather than a wider query
         *
         * Adding a UNION and a policy clause here would have fixed today's two divergences and left
         * the mechanism that produced them: two readers of one subject, free to drift again the next
         * time either learns something. The Unified Data Pipeline requirement is explicit that one
         * number may not be derived in more than one place, so the strip now pools the very rows the
         * cards render — and `aggregate()` recomputes every ratio from the pooled sums, which is the
         * rule that keeps a scope's CTR from being the mean of per-creative CTRs.
         *
         * The cost is two grouped queries over the scope instead of one, and N shaped rows summed in
         * PHP rather than by the database. That is the price `idsWithFatigueStatus()` already pays
         * over the same candidate set for the health filter, and it buys the one property this
         * endpoint exists to have: the strip cannot say something the cards under it contradict.
         */
        $figures = $this->forCreatives($creativeIds, $from, $to);

        if ($figures === []) {
            return null;
        }

        $totals = $this->aggregate(array_values($figures));

        /*
         * A scope where the provider reported NOTHING is «no figures», not a row of zeros.
         *
         * `aggregate()` keeps a null null, so a set of silent creatives comes back with every figure
         * absent — honest, and still an answer a strip would draw. The caller gets null and says so.
         */
        return $totals === null || ! in_array(true, (array) ($totals['reported'] ?? []), true)
            ? null
            : $totals;
    }

    /**
     * One row's figures: the raw sums, the derived KPIs, and what the provider actually reported.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        /*
         * A key that is not in the row is NOT REPORTED, which is null — never 0.
         *
         * This read `$row[$key]` directly, which is safe for a creative-grain row because that query
         * selects every metric in `SUMS`. The ad grain does not carry all of them — `video_completions`
         * exists on one table and not the other — and the choice at that moment is the whole rule this
         * product runs on: a missing key becomes `null` and the reader prints «—», or it becomes 0 and
         * the reader is told the platform measured nothing. The second is the fabricated zero refused
         * everywhere else, so it is the first.
         */
        $num = static fn (string $key): ?float => ($row[$key] ?? null) === null ? null : (float) $row[$key];

        $figures = [];
        /*
         * The ad-grain results are read the same way, and their absence means the same thing.
         *
         * `shape()` runs for both grains, and the creative-grain row has no `leads` key at all — so
         * `$num` returns null and the metric is «not reported», which is exactly right for a table
         * that cannot hold it. Listing them here rather than only in the ad-grain branch keeps one
         * shaping path for both, which is the property that stopped the two grains disagreeing in
         * the first place.
         */
        foreach ([...array_keys(self::SUMS), ...array_keys(self::AD_GRAIN_SUMS)] as $key) {
            $figures[$key] = $num($key);
        }

        foreach (self::AVERAGED as $key) {
            $figures[$key] = $num($key);
        }
        $figures['active_days'] = (int) ($row['active_days'] ?? 0);

        $figures = $this->derive($figures);

        /*
         * What the platform actually sent.
         *
         * The frontend needs this to tell «0» from «not reported», and it cannot infer it from the
         * value alone: a genuine zero and a missing metric both arrive as falsy in JavaScript.
         */
        $figures['reported'] = [];
        // `orders` shares `conversions`' answer, because it shares its column. Without this it is
        // absent from the map and renders as «no data» rather than «not provided» on an awareness
        // creative, which is the weaker of the two true statements.
        $figures['reported']['orders'] = $row['conversions'] !== null;
        foreach ([...array_keys(self::SUMS), ...array_keys(self::AD_GRAIN_SUMS)] as $key) {
            /*
             * Absent and null are the same answer here: «the platform did not report this».
             *
             * A creative-grain row selects every metric, so the key is always present and this read
             * it directly. The ad grain carries a narrower set — see `fromAdGrain()` — and a metric
             * that table has no column for must arrive as «not reported» rather than as a key error
             * or, worse, as a reported zero.
             */
            $figures['reported'][$key] = ($row[$key] ?? null) !== null;
        }
        foreach (self::AVERAGED as $key) {
            /* Same rule as the sums above: an absent column is «not reported». */
            $figures['reported'][$key] = ($row[$key] ?? null) !== null;
        }

        /*
         * Carried AFTER `reported` is built, and never inside it.
         *
         * These describe the money's provenance, not a metric the platform sends. Adding them to the
         * reported map would put «Spend withheld rows» on a card as though it were a figure somebody
         * could act on.
         */
        $figures['spend_withheld_rows'] = (int) ($row['spend_withheld_rows'] ?? 0);
        $figures['revenue_withheld_rows'] = (int) ($row['revenue_withheld_rows'] ?? 0);
        $figures['spend_original'] = $num('spend_original');
        $figures['revenue_original'] = $num('revenue_original');
        $figures['money_original_currency'] = $row['money_original_currency'] === null ? null : (string) $row['money_original_currency'];
        $figures['money_original_currencies'] = (int) ($row['money_original_currencies'] ?? 0);

        return $figures;
    }

    /**
     * The derived KPIs, from raw figures — the ONLY place a ratio in this system is written down.
     *
     * Called for one creative's sums and for a set of creatives summed together. Two copies of this
     * arithmetic is exactly how a dashboard's «image vs video ROAS» ends up disagreeing with the ROAS
     * on the cards it was computed from, so the aggregate does not get its own version: it sums the
     * raw figures and comes through here.
     *
     * @param  array<string, mixed>  $figures  raw sums, nulls intact
     * @return array<string, mixed>
     */
    private function derive(array $figures): array
    {
        $num = static fn (string $key): ?float => is_numeric($figures[$key] ?? null) ? (float) $figures[$key] : null;

        $spend = $num('spend');
        $impressions = $num('impressions');
        $clicks = $num('clicks');
        $conversions = $num('conversions');
        $revenue = $num('revenue');
        $videoViews = $num('video_views');

        // Every one is null when its denominator is missing or zero — a ratio over nothing is «there
        // is nothing to divide», and 0 reads as «it costs nothing».
        $figures['ctr'] = $this->ratio($clicks, $impressions);
        $figures['cpc'] = $this->ratio($spend, $clicks);
        $figures['cpm'] = $impressions ? $this->ratio($spend, $impressions / 1000) : null;
        $figures['cpa'] = $this->ratio($spend, $conversions);
        /*
         * The cost of the result the campaign was bought for — CONTENT-KPI-COVERAGE-002.
         *
         * `ObjectiveFamily::Leads` leads with `cpl` and `App` with `cpi`, and neither was ever
         * computed: the family named the verdict and the service produced no figure for it, so the
         * filter below dropped the cell and the card led with whatever came next. `ratio()` returns
         * null when the denominator is missing or zero, so a creative whose platform reported no
         * leads gets «not reported» rather than a cost per nothing.
         */
        $figures['cpl'] = $this->ratio($spend, $num('leads'));
        $figures['cpi'] = $this->ratio($spend, $num('installs'));
        $figures['roas'] = $this->ratio($revenue, $spend);
        $figures['conversion_rate'] = $this->ratio($conversions, $clicks);
        $figures['aov'] = $this->ratio($revenue, $conversions);
        $figures['cost_per_view'] = $this->ratio($spend, $videoViews);
        $figures['view_rate'] = $this->ratio($videoViews, $impressions);
        $figures['completion_rate'] = $this->ratio($num('video_p100') ?? $num('video_completions'), $videoViews);
        $figures['hook_rate'] = $this->ratio($num('video_views_3s'), $impressions);

        /*
         * The two headline metrics the sales path names but this service never produced.
         *
         * `MarketingPath::headlineMetrics()` asks the conversion path for `orders` and the traffic
         * path for `cost_per_lpv`. Neither key existed here, and an absent key reads as «no data» —
         * so a sales creative with 850 orders showed «Orders: No data» on the row that is supposed to
         * carry its most important figure, and two of the seven sales headlines were dead.
         *
         * `orders` is `conversions` under the name the marketing paths use, kept as an alias rather
         * than renamed: `conversions` is the column, the canonical metric and what every other
         * surface reads, and one concept with two names is better than a rename that leaves half the
         * system pointing at the old one.
         */
        $figures['orders'] = $conversions;
        $figures['cost_per_lpv'] = $this->ratio($spend, $num('landing_page_views'));

        /*
         * Owner defect 95 — the two figures the Engagement family is JUDGED by, and never computed.
         *
         * The third instance of one shape in this file, and the other two are recorded a few lines
         * above: `cpl` and `cpi` were «named as the verdict and never computed», so `supportable()`
         * struck them and the card «led with whatever came next»; and `ObjectiveFamily::App` named
         * `registrations` and `in_app_events`, «two figures that could never arrive».
         *
         * `engagement_rate` and `cpe` were listed in `DERIVED` — this service's own claim that it can
         * produce them — and nothing here produced either. So an engagement creative lost BOTH of its
         * verdict metrics silently and led with spend, engagements and impressions: figures true of any
         * campaign whatever it was bought for, on the card that is supposed to say whether this one
         * worked.
         *
         * `engagements` has been a summed column all along, so both are arithmetic the service already
         * had the inputs for. An engagement is deliberately NOT a click — the family's own comment says
         * so — which is why the rate is engagements over impressions rather than over clicks.
         *
         * `ratio()` returns null when the denominator is missing or zero, so a creative whose platform
         * reported no engagement gets «nothing to divide» rather than a rate of nothing.
         */
        $engagements = $num('engagements');

        $figures['engagement_rate'] = $this->ratio($engagements, $impressions);
        $figures['cpe'] = $this->ratio($spend, $engagements);

        return $figures;
    }

    /**
     * Several creatives' figures summed into one — for «images vs videos», a group, or a path total.
     *
     * ## Null survives addition
     *
     * A key nobody reported stays null rather than becoming 0, exactly as it does for one creative.
     * A key SOME reported sums what was actually sent and says so: an aggregate that quietly treated
     * the silent half as zero would report a completion rate over a denominator missing most of its
     * views, which is a worse lie than «not provided» because it looks like an answer.
     *
     * `active_days` is the maximum rather than the sum — a set of creatives that each ran seven days
     * over the same week was delivering for seven days, not for seventy.
     *
     * @param  list<array<string, mixed>>  $sets  rows from `forCreatives()`
     * @return array<string, mixed>|null null when there is nothing at all to add up
     */
    public function aggregate(array $sets): ?array
    {
        $sets = array_values(array_filter($sets, static fn ($s): bool => is_array($s)));

        if ($sets === []) {
            return null;
        }

        $figures = [];
        $reported = [];

        /*
         * Owner defect 95 — the AD grain's results are summed too, or an aggregate loses them.
         *
         * This iterated `SUMS` alone, which is the CREATIVE table's column list. `leads`, `sign_ups`,
         * `installs`, `app_opens` and `page_views` live only on `entity_daily_metrics`, and since the
         * ad-grain fallback a creative's figures routinely come from there — so a card showed twenty
         * leads and the group containing that one creative showed none, as did the format comparison
         * and Content Analytics above it. Exactly the loss `shape()` already avoids by reading both
         * key lists; this is the same correction one surface further on.
         */
        foreach ([...array_keys(self::SUMS), ...array_keys(self::AD_GRAIN_SUMS)] as $key) {
            $total = null;
            foreach ($sets as $set) {
                if (is_numeric($set[$key] ?? null)) {
                    $total = ($total ?? 0.0) + (float) $set[$key];
                }
            }
            $figures[$key] = $total;
            $reported[$key] = $total !== null;
        }

        /*
         * Frequency is impression-weighted, because it is an average and averages do not add.
         *
         * A creative shown twice to a hundred thousand people and one shown eight times to two
         * hundred do not average to five: the plain mean lets a rounding error of a creative dominate
         * the figure that is supposed to describe the audience's exposure.
         */
        $figures['frequency'] = $this->weightedMean($sets, 'frequency', 'impressions');
        $figures['video_avg_watch_seconds'] = $this->weightedMean($sets, 'video_avg_watch_seconds', 'video_views');
        $reported['frequency'] = $figures['frequency'] !== null;
        $reported['video_avg_watch_seconds'] = $figures['video_avg_watch_seconds'] !== null;

        $figures['active_days'] = (int) max(array_map(
            static fn (array $s): int => (int) ($s['active_days'] ?? 0),
            $sets,
        ));

        /*
         * The money's PROVENANCE, pooled — so a withheld total is withheld rather than absent.
         *
         * FX-001 leaves `spend` null and preserves the original beside it, which is the state of every
         * Snapchat row on the owner's account: a USD account with no USD→SAR rate. An aggregate that
         * summed only the converted column would report «nothing was spent» over money the platform
         * did report — the same defect `creativeMoney` exists to prevent one element away, and the
         * reason the reader keys off exactly these field names.
         *
         * The currency is named only when every contributing row agrees on one. Two unconvertible
         * currencies cannot be added, and a label over their sum would be a wrong label: that is the
         * rule `money_original_currencies` already enforces for one creative, held here for a set.
         */
        foreach (['spend', 'revenue'] as $key) {
            $figures[$key.'_withheld_rows'] = (int) array_sum(array_map(
                static fn (array $s): int => (int) ($s[$key.'_withheld_rows'] ?? 0),
                $sets,
            ));

            $original = null;
            foreach ($sets as $set) {
                if (is_numeric($set[$key.'_original'] ?? null)) {
                    $original = ($original ?? 0.0) + (float) $set[$key.'_original'];
                }
            }
            $figures[$key.'_original'] = $original;
        }

        $currencies = array_values(array_unique(array_filter(array_map(
            static fn (array $s): ?string => is_string($s['money_original_currency'] ?? null)
                && trim((string) $s['money_original_currency']) !== ''
                    ? (string) $s['money_original_currency']
                    : null,
            $sets,
        ))));

        $figures['money_original_currency'] = count($currencies) === 1 ? $currencies[0] : null;
        $figures['money_original_currencies'] = count($currencies);

        /*
         * The grain, only where every contributing row agrees on it.
         *
         * A mixed scope has no single provenance, and claiming one would put «summed from this
         * creative's ads» over a set where half the figures are the platform's own creative-level
         * report. Null is «no claim», which is how a payload predating the field already reads.
         */
        $grains = array_values(array_unique(array_filter(array_map(
            static fn (array $s): ?string => is_string($s['grain'] ?? null) ? (string) $s['grain'] : null,
            $sets,
        ))));
        if (count($grains) === 1) {
            $figures['grain'] = $grains[0];
        }

        $figures = $this->derive($figures);

        /*
         * Content Production Recovery — a ratio pooled over a set never divides across grains.
         *
         * A creative filled from its ads can carry the ads' spend beside its own clicks. Pooling the
         * columns and dividing, as above, would state spend(ads) / clicks(creative) — a CPC nobody
         * measured, and one the card for that same creative does not show (it shows the ads' own).
         * Where any contributing row carries its ratio inputs, every ratio is pooled pair by pair
         * instead: each row contributes a numerator and a denominator from ONE grain, or nothing.
         * Rows that were never filled contribute their own columns, exactly as before.
         */
        if (array_filter($sets, static fn (array $s): bool => is_array($s['ratio_inputs'] ?? null)) !== []) {
            foreach (array_keys(self::RATIO_INPUTS) as $key) {
                $numerator = null;
                $denominator = null;

                foreach ($sets as $set) {
                    [$n, $d] = is_array($set['ratio_inputs'][$key] ?? null)
                        ? $set['ratio_inputs'][$key]
                        : $this->ratioInputs($set, $key);

                    if ($n === null || $d === null) {
                        continue;
                    }

                    $numerator = ($numerator ?? 0.0) + (float) $n;
                    $denominator = ($denominator ?? 0.0) + (float) $d;
                }

                $figures[$key] = $this->ratio($numerator, $denominator);
            }
        }

        $reported['orders'] = $reported['conversions'];
        $figures['reported'] = $reported;
        $figures['creatives'] = count($sets);

        return $figures;
    }

    /**
     * A mean weighted by the figure that gives each row its size, or null when nothing reported it.
     *
     * Falls back to the plain mean when no weight is available — a weighted mean with no weights is
     * not more accurate than an unweighted one, it is undefined, and returning null there would
     * throw away a figure every row actually reported.
     *
     * @param  list<array<string, mixed>>  $sets
     */
    private function weightedMean(array $sets, string $key, string $weightKey): ?float
    {
        $sum = 0.0;
        $weight = 0.0;
        $plain = [];

        foreach ($sets as $set) {
            if (! is_numeric($set[$key] ?? null)) {
                continue;
            }

            $value = (float) $set[$key];
            $plain[] = $value;

            if (is_numeric($set[$weightKey] ?? null) && (float) $set[$weightKey] > 0) {
                $sum += $value * (float) $set[$weightKey];
                $weight += (float) $set[$weightKey];
            }
        }

        if ($plain === []) {
            return null;
        }

        return $weight > 0
            ? round($sum / $weight, 4)
            : round(array_sum($plain) / count($plain), 4);
    }

    /** A ratio, or null when there is nothing to divide by. Never 0 — see the class note. */
    private function ratio(?float $numerator, ?float $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator == 0.0) {
            return null;
        }

        return round($numerator / $denominator, 4);
    }

    /**
     * The metrics that mean something for this creative, given the job its campaign was doing.
     *
     * Delegates to `MarketingPath::headlineMetrics()` rather than keeping a second list: the report
     * layouts read the same source, and two lists that disagree would mean a creative judged one way
     * on the dashboard and another in the client's report.
     *
     * @return list<string>
     */
    /**
     * @param  array<string, mixed>|null  $figures  this creative's own figures for the window, when
     *                                              known; null asks only what the FAMILY wants
     */
    public function headline(?string $objective, ?array $figures = null): array
    {
        /*
         * OBJECTIVE-AWARE-KPI-001 — chosen by the objective's FAMILY, not by its marketing path.
         *
         * The path has three cases and answers a money question. Using it here meant `Leads` and
         * `AppInstalls` — both on the conversion path — were headlined with `revenue`, `roas` and
         * `aov`: figures a lead-generation or app-install campaign was never bought to produce and
         * the platform will never report for it. The requirement is explicit that a campaign must
         * not be judged by another objective's verdict, and this was the shipping version of that.
         *
         * `pathFor()` is untouched and still governs whose CPA the money lands in.
         */
        $family = $this->familyFor($objective);

        $metrics = $family->headlineMetrics();

        /*
         * Video figures ride along on an awareness buy, because a video's hook and completion matter
         * whether it was bought for reach or for sales — but NOT on the video family, which already
         * leads with them, and not on the others, where they would push the actual verdict down.
         */
        $metrics = $family === ObjectiveFamily::Awareness
            ? array_values(array_unique([...$metrics, 'video_views', 'view_rate', 'completion_rate', 'cost_per_view']))
            : $metrics;

        return $this->supportable($metrics, $figures);
    }

    /**
     * Whether THIS row can answer a given metric — the question `supportable()` cannot ask alone.
     *
     * Three states count as answerable, and the third is the one that has been getting this wrong:
     *
     *   REPORTED  a value came back, including a measured zero. `0 orders` is a fact about a sales
     *             creative and belongs on its card.
     *   DERIVED   a ratio this service computed from figures that were reported. Null when its
     *             denominator was missing, which is «there was nothing to divide», not «zero».
     *   WITHHELD  money with no conversion rate. `value` is null by FX-001's design and the ORIGINAL
     *             amount is preserved beside it, so the cell renders «79.61 USD» and is answerable.
     *             Treating this as unanswerable would drop spend off the card of every creative on an
     *             account with no rate — which is every creative on the account this was found on.
     *
     * @param  array<string, mixed>  $figures
     */
    private function answerable(array $figures, string $key): bool
    {
        if (in_array($key, self::MONEY, true)) {
            return $this->moneyAnswerable($figures, $key);
        }

        return ($figures[$key] ?? null) !== null;
    }

    /**
     * CONTENT-KPI-MONEY-PROVENANCE-001 — money is answerable on PROVENANCE, not on non-nullness.
     *
     * The first version of this asked «is the value or its original non-null», and that is not the
     * money contract. It let `revenue_original = 0` put «0.00 USD» on a sales card as though the
     * platform had reported a monetary zero, when a summed original of zero is not evidence of
     * anything having been reported at all. Production creative `81632089` established a spend of
     * 79.61 USD across 11 withheld rows; it established NO revenue, and the card was about to claim
     * one.
     *
     * A withheld figure is displayable only when every part of the claim it makes is true:
     *
     *   · the converted value is genuinely absent (otherwise case A already answered)
     *   · rows were actually withheld — `*_withheld_rows > 0` is the sync saying «I had an amount
     *     and refused to convert it», which a summed original alone never says
     *   · the original is POSITIVE. Zero is the value a sum takes when there was nothing to add.
     *   · exactly one original currency, and a real one. Two unconvertible currencies cannot be
     *     added into one amount, and a label over their sum would be a wrong label — the same rule
     *     `money_original_currencies` already enforces for the reader.
     *
     * A converted value of 0.0 is a different thing entirely and stays answerable: the rate existed,
     * the conversion happened, and the answer was zero.
     *
     * @param  array<string, mixed>  $figures
     */
    private function moneyAnswerable(array $figures, string $key): bool
    {
        // A — converted, including a measured zero.
        if (($figures[$key] ?? null) !== null) {
            return true;
        }

        // B — withheld, and every part of the claim holds.
        $withheldRows = (int) ($figures[$key.'_withheld_rows'] ?? 0);
        $original = $figures[$key.'_original'] ?? null;
        $currency = $figures['money_original_currency'] ?? null;
        $currencies = (int) ($figures['money_original_currencies'] ?? 0);

        /*
         * CONTENT-C-REPORTED-ZERO — PREPARED, NOT RELEASED: valid only when the raw evidence shows the
         * platform sent a JSON zero for these rows AND #458 has stopped ingestion casting a JSON null
         * to 0. `*_withheld_rows > 0` already proves the set is not empty, so an original of exactly
         * zero over those rows is the platform's zero, not an empty sum — stated as 0, never as «—».
         */
        return $withheldRows > 0
            && is_numeric($original)
            && ((float) $original > 0.0 || ($key === 'spend' && (float) $original === 0.0))
            && $currencies === 1
            && is_string($currency)
            && trim($currency) !== '';
    }

    /**
     * Keep only the metrics THIS table can actually produce.
     *
     * `ObjectiveFamily` describes the canonical verdict for a family and is right at campaign level,
     * where `daily_metrics` carries leads, installs, registrations and in-app events.
     * `creative_daily_metrics` has none of those columns — a platform that breaks results down per
     * creative does not break every result type down — so a lead campaign's creative card would put
     * «no data» in its most prominent position, which is the opposite of an objective-aware card.
     *
     * The requirement is explicit: a metric must not reach the UI before the pipeline can supply it.
     * So the family list is filtered here rather than being trimmed at its definition, and the
     * definition stays true for the callers that CAN answer it.
     *
     * `spend` survives every filter, so a family whose whole verdict is unavailable still leads with
     * the figure that is always the question.
     *
     * @param  list<string>  $metrics
     * @return list<string>
     */
    /**
     * CONTENT-KPI-AVAILABILITY-001 — objective-aware AND availability-aware, in that order.
     *
     * Two filters, and they answer different questions. The first is about the PIPELINE: can this
     * service produce the metric at all? `ObjectiveFamily::App` names `installs` and `cpi`, and
     * `creative_daily_metrics` has no column for either — no creative will ever answer them, so a
     * card that promises one is promising an empty cell forever.
     *
     * The second is about THIS ROW: did the platform answer it, for this creative, in this window? A
     * sales creative whose family wants `revenue` and `roas` is not helped by two blank cells when
     * Snapchat reports neither at creative grain — and the same creative reports impressions, clicks
     * and CTR that the card had room to show.
     *
     * Order matters, and it is the whole design. The family's OWN metrics are kept first and in the
     * family's own order, because the first cell is the verdict and the objective is the only thing
     * that knows which figure that is. Only then is the remainder topped up, from the metrics that
     * are true of any campaign whatever it was bought for. A sales creative that CAN answer `orders`
     * still leads with `orders`; one that cannot does not get a blank where its verdict should be.
     *
     * Nothing here invents a value, and nothing is projected from another grain. A metric survives
     * only by being present in this row.
     *
     * With `$figures` null — a group with no aggregate, a card being described rather than rendered —
     * only the pipeline filter applies, which is the behaviour every caller had before.
     *
     * @param  list<string>  $metrics
     * @param  array<string, mixed>|null  $figures
     * @return list<string>
     */
    private function supportable(array $metrics, ?array $figures = null): array
    {
        /*
         * «Can this service produce the metric at all» — now a question about the GRAIN.
         *
         * This asked `SUMS`, the creative table's column list, and that was the whole truth when the
         * creative table was the only source. Since the ad-grain fallback, a creative's figures may
         * come from `entity_daily_metrics`, which holds the results `creative_daily_metrics` cannot
         * — so a lead campaign's `leads` and `cpl` were struck from the family's own list before the
         * availability test could ever see them present.
         *
         * The row itself decides: a figure is producible if this service can compute it for the
         * grain the row actually came from. With no figures to inspect — a card being described
         * rather than rendered — the conservative answer is the creative table's, which is what
         * every caller had before and is the right promise to make about a creative in general.
         */
        $fromAdGrain = ($figures['grain'] ?? null) === 'ad';

        $producible = fn (string $key): bool => array_key_exists($key, self::SUMS)
            || in_array($key, self::DERIVED, true)
            || ($fromAdGrain && (array_key_exists($key, self::AD_GRAIN_SUMS) || in_array($key, self::AD_GRAIN_DERIVED, true)))
            /*
             * Owner defect 95 — a figure the row demonstrably HOLDS is producible, whatever the grain
             * says. `grain` is one string, and an aggregate over a mixed scope legitimately carries
             * none: a group of Meta creatives pooled with a Snapchat one has no single provenance, so
             * the flag above went null and struck `leads` and `cpl` off the family's own list while
             * the pooled figures sat in the array being filtered. A present, non-null value is not a
             * promise of an empty cell — it is the cell.
             */
            || ($figures !== null && ($figures[$key] ?? null) !== null);

        $kept = array_values(array_filter($metrics, $producible));

        if ($figures !== null) {
            $kept = array_values(array_filter($kept, fn (string $k): bool => $this->answerable($figures, $k)));
        }

        /*
         * Top up to the number of cells the card renders.
         *
         * `Unknown`'s metrics are the ones true of every campaign whatever it was bought to do, which
         * is exactly what a family that has run out of answerable figures needs. When `$figures` is
         * given these are held to the same availability test as the family's own — so a creative that
         * genuinely reported four figures shows four, and one that reported two shows two rather than
         * two figures and two apologies.
         */
        foreach (ObjectiveFamily::Unknown->headlineMetrics() as $universal) {
            if (count($kept) >= self::HEADLINE_MINIMUM) {
                break;
            }

            if (in_array($universal, $kept, true) || ! $producible($universal)) {
                continue;
            }

            if ($figures === null || $this->answerable($figures, $universal)) {
                $kept[] = $universal;
            }
        }

        /*
         * `spend` is the last resort and only when nothing at all survived — it is the one question
         * asked of every campaign. It is NOT added when figures were supplied and spend is not among
         * them: a card is better with three honest cells than four where the fourth is «no data».
         */
        if ($kept === []) {
            return $figures === null || $this->answerable($figures, 'spend') ? ['spend'] : [];
        }

        return $kept;
    }

    /** The family whose KPIs this objective is judged by — see {@see CampaignObjective::family()}. */
    public function familyFor(?string $objective): ObjectiveFamily
    {
        $case = $objective === null ? null : CampaignObjective::tryFrom($objective);

        return $case?->family() ?? ObjectiveFamily::Unknown;
    }

    public function pathFor(?string $objective): MarketingPath
    {
        $case = $objective === null ? null : CampaignObjective::tryFrom($objective);

        return $case?->path() ?? MarketingPath::Awareness;
    }

    /**
     * Whether two creatives may be ranked against each other on one axis, and why not when they cannot.
     *
     * §15.7 forbids declaring an overall «winner» between content serving different objectives. The
     * honest comparison is per-metric — best reach, best CTR, best CPA — and this returns the reason
     * so the UI can say it rather than silently dropping the verdict.
     *
     * @return array{comparable: bool, reason: string|null, reason_ar: string|null}
     */
    public function comparable(?string $objectiveA, ?string $objectiveB): array
    {
        $pathA = $this->pathFor($objectiveA);
        $pathB = $this->pathFor($objectiveB);

        if ($pathA === $pathB) {
            return ['comparable' => true, 'reason' => null, 'reason_ar' => null];
        }

        return [
            'comparable' => false,
            'reason' => "These creatives were bought for different jobs ({$pathA->value} vs {$pathB->value}), so one overall winner would be misleading. Compare them metric by metric instead.",
            'reason_ar' => 'المحتويان اشتُريا لهدفين مختلفين ('.$pathA->labels()['ar'].' مقابل '.$pathB->labels()['ar'].')، فإعلان فائز واحد سيكون مضلّلًا. قارنهما مؤشرًا بمؤشر.',
        ];
    }
}
