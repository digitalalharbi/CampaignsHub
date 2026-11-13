<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Support\ObjectiveResult;
use App\Domains\Campaigns\Support\PathObjective;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Projects\Concerns\ProjectScope;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Support\ReportReadMemo;
use App\Domains\Tenancy\Context\TenantContext;
use App\Support\AdPlatforms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Spend and results, separated by the path the money was spent on (REPORT-OBJECTIVE-001/003).
 *
 * ## The defect this exists to make impossible
 *
 * `total spend ÷ sales orders`, printed as CPA. It is not a conservative estimate; it is a wrong
 * number. A brand campaign that ran all month and was never meant to sell anything puts its whole
 * budget in the numerator and nothing in the denominator, so what a client reads as «what an order
 * costs me» is inflated by an amount they cannot see — and they set next month's budget on it.
 *
 * ## What it returns, and why in this shape
 *
 * Two figures that are never the same figure:
 *
 *   - **direct** — conversion-path campaigns alone. `Sales CPA = sales-path spend ÷ sales-path
 *     orders`, `Sales ROAS = sales-path revenue ÷ sales-path spend`. This is the honest answer to
 *     «what does an order cost», and it is the one that may be called CPA without qualification.
 *   - **blended** — every path's spend against the same orders. A legitimate question («what did
 *     this whole programme cost per order?») and a different one, so it is returned under different
 *     keys and is NEVER substituted for `direct`. The contract is explicit: «المؤشر المدمج لا يحل
 *     محل المؤشر المباشر أبدًا».
 *
 * Both carry `included_campaigns`, `excluded_campaigns`, `formula` and the objective of every
 * campaign counted, because a metric a reader cannot audit is a metric they have to take on trust —
 * and this is the exact figure that was worth distrusting.
 *
 * A ratio over a zero denominator is **null**, never 0. A zero CPA reads as «orders are free»; null
 * reads as «there is nothing to divide», which is what actually happened.
 */
final class ObjectivePerformance
{
    /**
     * @param  list<string>|null  $projectIds  null = whatever the project scope already bounds
     * @param  list<string>|null  $campaignIds  null = every campaign in that bound
     * @param  list<string>|null  $providers  null = every platform (§14.5 — the report scope's platform axis)
     * @param  list<string>|null  $accountIds  null = every ad account (§14.5 — the account axis)
     *
     * Platforms and accounts are accepted here rather than being approximated by the caller, because
     * this service is where the split slide's figures come from. A report scoped to one platform whose
     * Direct/Blended block still counted the others would contradict its own KPI cards — and that
     * block is the one a reader consults precisely when the headline number looks wrong.
     */
    public function __construct(
        private readonly ?array $projectIds = null,
        private readonly ?array $campaignIds = null,
        private readonly ?array $providers = null,
        private readonly ?array $accountIds = null,
    ) {}

    /** @return array<string,mixed> */
    public function build(Carbon $from, Carbon $to): array
    {
        $rows = $this->rows($from, $to);

        $paths = [];
        foreach (MarketingPath::cases() as $path) {
            $paths[$path->value] = $this->emptyPath($path);
        }

        $salesSpend = 0.0;
        $salesOrders = 0.0;
        $salesRevenue = 0.0;
        $totalSpend = 0.0;
        // DERIVED-WITHHELD-001 — rows whose money was withheld; a ratio over them is not stated.
        $totalSpendWithheld = 0;
        $salesSpendWithheld = 0;
        $salesRevenueWithheld = 0;
        $included = [];
        $excluded = [];

        foreach ($rows as $row) {
            $objective = CampaignObjective::tryFrom((string) $row->objective) ?? CampaignObjective::Other;
            $path = $objective->path();
            $bucket = &$paths[$path->value];

            $bucket['spend'] += (float) $row->spend;
            $bucket['spend_withheld_rows'] = (int) ($bucket['spend_withheld_rows'] ?? 0) + (int) ($row->spend_withheld_rows ?? 0);
            $bucket['revenue_withheld_rows'] = (int) ($bucket['revenue_withheld_rows'] ?? 0) + (int) ($row->revenue_withheld_rows ?? 0);
            $bucket['impressions'] += (float) $row->impressions;
            $bucket['clicks'] += (float) $row->clicks;
            $bucket['landing_page_views'] += (float) $row->landing_page_views;
            $bucket['orders'] += (float) $row->orders;
            /*
             * CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — WHAT the orders on this path are made of.
             *
             * The conversion path is Leads, App installs, Add to cart, Sales, Conversions and
             * Purchases together — `CampaignObjective::path()` says so. Their `orders` were summed
             * into one figure and `cpa` divided the path's whole spend by it, so a project running a
             * lead programme beside a purchase campaign was shown ONE «cost per result» blending the
             * cost of a lead with the cost of a sale. It is always the flattering direction: leads
             * are many and cheap, purchases few and dear, and the blend hides the price of a sale
             * behind the volume of leads. This reaches a paying client — `LiveDetailTables` and
             * `PrintDocument` both render this `cpa` as cost per result.
             *
             * The aggregate stays, because every caller sums it and because the ratio IS recomputed
             * from the aggregate numerator and denominator rather than averaged. What it may no
             * longer do is travel alone: each objective's own count is kept beside it, so no surface
             * can show the blend without being able to say what went into it.
             */
            $bucket['result_composition'][$objective->value]
                = ($bucket['result_composition'][$objective->value] ?? 0.0) + (float) $row->orders;
            $bucket['revenue'] += (float) $row->revenue;
            // OBJECTIVE-RESULT-001 — kept for the path's own result, resolved once in `derivePath`.
            $bucket['_result_rows'][] = ['conversions' => (float) $row->orders] + (array) $row;
            $bucket['campaigns'][] = [
                'id' => $row->unified_campaign_id,
                'name' => $row->name,
                'objective' => $objective->value,
                'objective_label_ar' => $objective->labels()['ar'],
                'objective_source' => $row->objective_source ?? 'unset',
                'spend' => round((float) $row->spend, 2),
            ];

            /*
             * AGGREGATION-TRUTH-001 — the coverage has to describe the path it is attached to.
             *
             * Every path starts as `emptyPath()`, which states `no_contributors` because that is
             * true of a path nothing has been added to yet. Rows were then accumulated into it —
             * spend, orders, revenue, campaigns — and the coverage was never touched again. So the
             * conversion path on the owner's live client report carried 9,437.86 in spend and 566
             * orders while reporting that NOTHING had contributed to it.
             *
             * That is the inverse of the failure the state exists to prevent. Its own note says a
             * surface can now say «no campaigns on this path» instead of printing a row of zeros;
             * it was saying that over nine thousand of somebody's money.
             *
             * The contributor is the campaign just added, and it is recorded here rather than
             * recomputed later so the state and the figures can never disagree — they are written
             * by the same line of reasoning.
             */
            $bucket['coverage']['state'] = 'complete';

            /*
             * The PLATFORM contributed, not the campaign — CLIENT-REPORT-ENTITY-BOUNDARY-001.
             *
             * A first version of this recorded `unified_campaign_id`, and
             * `LiveReportShareTest > a client link never carries a campaign or ad set name` refused
             * it: "an internal id reached the client". It was right. A shared link carries
             * performance and never the campaign plan, and an id in a coverage list is the plan
             * arriving through a side door — readable in the JSON whatever the page draws.
             *
             * The provider is already published to a client in this same path's `platforms`
             * breakdown, so naming it here adds nothing they cannot see, and it answers the question
             * the field is actually for: which platforms are behind these figures.
             */
            if (! in_array((string) $row->provider, $bucket['coverage']['included_contributors'], true)) {
                $bucket['coverage']['included_contributors'][] = (string) $row->provider;
            }

            $totalSpend += (float) $row->spend;
            $totalSpendWithheld += (int) ($row->spend_withheld_rows ?? 0);

            // The whole rule, in four lines: only a SALES campaign's money reaches the sales figures.
            if ($objective->isSales()) {
                $salesSpend += (float) $row->spend;
                $salesSpendWithheld += (int) ($row->spend_withheld_rows ?? 0);
                $salesRevenueWithheld += (int) ($row->revenue_withheld_rows ?? 0);
                $salesOrders += (float) $row->orders;
                $salesRevenue += (float) $row->revenue;
                $included[] = ['id' => $row->unified_campaign_id, 'name' => $row->name, 'objective' => $objective->value];
            } else {
                $excluded[] = [
                    'id' => $row->unified_campaign_id, 'name' => $row->name, 'objective' => $objective->value,
                    'spend' => round((float) $row->spend, 2),
                    'reason' => 'not_a_sales_objective',
                ];
            }
            unset($bucket);
        }

        foreach ($paths as $key => $bucket) {
            $paths[$key] = $this->derivePath($bucket, MarketingPath::from($key));
        }

        return [
            'paths' => array_values($paths),
            'direct' => [
                'label_ar' => 'الأداء المباشر',
                'label_en' => 'Direct performance',
                'spend' => round($salesSpend, 2),
                'orders' => round($salesOrders, 2),
                'revenue' => round($salesRevenue, 2),
                'cpa' => $salesSpendWithheld > 0 ? null : $this->ratio($salesSpend, $salesOrders),
                'roas' => $salesSpendWithheld > 0 || $salesRevenueWithheld > 0 ? null : $this->ratio($salesRevenue, $salesSpend),
                'aov' => $salesRevenueWithheld > 0 ? null : $this->ratio($salesRevenue, $salesOrders),
                'spend_withheld_rows' => $salesSpendWithheld,
                'revenue_withheld_rows' => $salesRevenueWithheld,
                'formula' => [
                    'cpa' => 'sales-path spend ÷ sales-path orders',
                    'roas' => 'sales-attributed revenue ÷ sales-path spend',
                ],
                'included_campaigns' => $included,
                'excluded_campaigns' => $excluded,
            ],
            /*
             * Returned beside `direct`, never instead of it, and labelled in both languages so an
             * interface cannot print it as «CPA». It answers a real question — what the whole
             * programme cost per order — and it is not the answer to «what does an order cost».
             */
            'blended' => [
                'label_ar' => 'الأداء المدمج',
                'label_en' => 'Blended performance',
                'spend' => round($totalSpend, 2),
                'orders' => round($salesOrders, 2),
                'revenue' => round($salesRevenue, 2),
                'blended_cpa' => $totalSpendWithheld > 0 ? null : $this->ratio($totalSpend, $salesOrders),
                'blended_roas' => $totalSpendWithheld > 0 || $salesRevenueWithheld > 0 ? null : $this->ratio($salesRevenue, $totalSpend),
                'spend_withheld_rows' => $totalSpendWithheld,
                'formula' => [
                    'blended_cpa' => 'spend on EVERY path ÷ sales-path orders',
                    'blended_roas' => 'sales-attributed revenue ÷ spend on EVERY path',
                ],
                'includes_non_sales_spend' => round($totalSpend - $salesSpend, 2),
                'never_substitutes_direct' => true,
            ],
            /*
             * OBJECTIVE-PATHS-001 — ONE total spend, split by what each ad set was bought to do.
             * See {@see objectivePaths()}.
             */
            'objective_paths' => $this->objectivePaths($from, $to),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ];
    }

    /**
     * OBJECTIVE-PATHS-001 — one total spend, and under it one card per objective PATH.
     *
     * ## The defect
     *
     * 1KCOFFEE runs campaigns with two paths: ad sets bought for online-store purchases and ad sets
     * bought for branch visits. Every figure here was keyed on the CAMPAIGN objective, so a sales
     * campaign's ROAS divided the store's revenue by the branches' spend too, and a campaign filed as
     * visits ignored its sales.
     *
     * ## The rule (Owner, 2026-11-13)
     *
     *   - Each ad set / ad squad is classified by its own optimisation goal ({@see PathObjective});
     *     one with no decisive goal keeps its campaign's family.
     *   - Its spend and results go to that family's path; a campaign without ad-set figures goes whole
     *     to its campaign's family. Revenue and ROAS exist on the sales path only, and that ROAS is
     *     sales-path revenue ÷ sales-path spend — never over the total.
     *   - `total_spend` is stated ONCE and is exactly the sum of the paths' spend: an ad set's money is
     *     read from the ad-set grain only when that grain accounts for its campaign's spend (within
     *     half a percent); the remainder, if any, stays with the campaign's own family so nothing is
     *     counted twice or dropped. A campaign whose ad-set money was withheld (no rate) is read whole.
     *   - `share` is each path's part of that one total, for the split bar.
     *
     * No campaign names: this block reaches the client's link as it is.
     *
     * @return array<string, mixed>
     */
    public function objectivePaths(Carbon $from, Carbon $to): array
    {
        ['pieces' => $pieces, 'mixed' => $mixedCampaigns, 'withheld' => $withheld] = $this->pathPieces($from, $to);

        /** @var array<string, array{rows: list<array<string, mixed>>, reported: list<array<string, bool>>, campaigns: array<string, true>, ad_sets: int}> $paths */
        $paths = [];
        foreach ($pieces as $piece) {
            $family = $piece['family'];
            $paths[$family] ??= ['rows' => [], 'reported' => [], 'campaigns' => [], 'ad_sets' => 0];
            $paths[$family]['rows'][] = $piece['row'];
            $paths[$family]['reported'][] = $piece['reported'];
            $paths[$family]['campaigns'][$piece['campaign']] = true;
            $paths[$family]['ad_sets'] += $piece['ad_sets'];
        }

        $total = 0.0;
        foreach ($paths as $p) {
            $total += array_sum(array_map(static fn (array $row): float => (float) ($row['spend'] ?? 0), $p['rows']));
        }

        $out = [];
        foreach ($paths as $familyValue => $p) {
            $family = ObjectiveFamily::from($familyValue);
            $spend = array_sum(array_map(static fn (array $row): float => (float) ($row['spend'] ?? 0), $p['rows']));
            $pathWithheld = (int) array_sum(array_map(static fn (array $row): int => (int) ($row['spend_withheld_rows'] ?? 0), $p['rows']));
            // A residual row carries money and no figures; it must not turn a measured result into «no data».
            $measured = array_keys(array_filter($p['reported'], static fn (array $rep): bool => $rep !== []));
            $result = ObjectiveResult::combine(
                $family,
                array_values(array_intersect_key($p['rows'], array_flip($measured))),
                array_values(array_intersect_key($p['reported'], array_flip($measured))),
            );
            // The result's cost is over the WHOLE path's spend, residual included.
            $result['cost_per_result'] = $result['result'] === null || $result['result'] <= 0 || $pathWithheld > 0
                ? null
                : round($spend / ((float) $result['result'] / (in_array($result['result_key'], ['reach', 'impressions'], true) ? 1000.0 : 1.0)), 2);
            $revenue = array_sum(array_map(static fn (array $row): float => (float) ($row['revenue'] ?? 0), $p['rows']));
            $isSales = $family === ObjectiveFamily::Sales;

            $out[] = [
                'family' => $family->value,
                'label' => $family->label(),
                'spend' => round($spend, 2),
                'share' => $total > 0 ? round($spend / $total, 4) : null,
                // Revenue and ROAS belong to the sales path only; elsewhere they are absent, not zero.
                'revenue' => $isSales ? round($revenue, 2) : null,
                'roas' => $isSales && $pathWithheld === 0 ? $this->ratio($revenue, $spend) : null,
                'campaigns' => count($p['campaigns']),
                'ad_sets' => $p['ad_sets'],
                'spend_withheld_rows' => $pathWithheld,
            ] + $result;
        }

        usort($out, static fn (array $a, array $b): int => [$b['spend'], $a['family']] <=> [$a['spend'], $b['family']]);

        return [
            'total_spend' => round($total, 2),
            'spend_withheld_rows' => $withheld,
            'mixed' => count($out) > 1,
            'mixed_campaigns' => count($mixedCampaigns),
            'paths' => $out,
            'formula' => [
                'total_spend' => 'sum of every path\'s spend — stated once',
                'roas' => 'sales-path revenue ÷ sales-path spend',
            ],
        ];
    }

    /**
     * OBJECTIVE-PATHS-001 — the sales path of every campaign whose ad sets buy more than one thing.
     *
     * The alert sweep judges ROAS and CPA per campaign. A sales campaign with a branches squad beside
     * its store squads would have its ROAS diluted by the branches' spend; for exactly those campaigns
     * this returns the store path's own money and orders, so a ROAS-drop or CPA-rise alert is computed
     * on the sales path only. Campaigns with one path are not listed — their campaign figures are
     * already their path's figures.
     *
     * @return array<string, array{spend: float, revenue: float, conversions: float, roas: float|null, cpa: float|null}>
     */
    public function salesPathByCampaign(Carbon $from, Carbon $to): array
    {
        ['pieces' => $pieces, 'mixed' => $mixed] = $this->pathPieces($from, $to);

        $out = [];
        foreach ($pieces as $piece) {
            $campaign = $piece['campaign'];
            if (! isset($mixed[$campaign]) || ! in_array(ObjectiveFamily::Sales->value, $mixed[$campaign], true) || $piece['family'] !== ObjectiveFamily::Sales->value) {
                continue;
            }
            $out[$campaign] ??= ['spend' => 0.0, 'revenue' => 0.0, 'conversions' => 0.0];
            foreach (['spend', 'revenue', 'conversions'] as $k) {
                $out[$campaign][$k] += (float) ($piece['row'][$k] ?? 0);
            }
        }

        foreach ($out as $campaign => $sums) {
            $out[$campaign]['roas'] = $this->ratio($sums['revenue'], $sums['spend']);
            $out[$campaign]['cpa'] = $this->ratio($sums['spend'], $sums['conversions']);
        }

        return $out;
    }

    /**
     * Every bounded (campaign, platform) row cut into the paths it bought — see {@see objectivePaths()}.
     *
     * @return array{pieces: list<array{family: string, row: array<string, mixed>, reported: array<string, bool>, campaign: string, ad_sets: int}>, mixed: array<string, list<string>>, withheld: int}
     */
    private function pathPieces(Carbon $from, Carbon $to): array
    {
        $campaignRows = $this->rows($from, $to);
        $adSets = $this->adSetRows($from, $to, $campaignRows->pluck('unified_campaign_id')->filter()->unique()->values()->all());

        $pieces = [];
        $mixed = [];
        $withheld = 0;
        $put = static function (string $family, array $row, array $reported, string $campaign, int $sets) use (&$pieces): void {
            $pieces[] = ['family' => $family, 'row' => $row, 'reported' => $reported, 'campaign' => $campaign, 'ad_sets' => $sets];
        };

        foreach ($campaignRows as $r) {
            $row = (array) $r;
            $campaign = (string) $row['unified_campaign_id'];
            $provider = (string) $row['provider'];
            $campaignFamily = ObjectiveResult::familyOf(isset($row['objective']) ? (string) $row['objective'] : null);
            $spend = (float) $row['spend'];
            $withheld += (int) ($row['spend_withheld_rows'] ?? 0);
            $row['conversions'] = (float) $row['orders'];
            $sets = $adSets[$campaign.'|'.$provider] ?? [];
            $setSpend = array_sum(array_map(static fn (array $s): float => (float) $s['spend'], $sets));
            $covered = $sets !== []
                && (int) ($row['spend_withheld_rows'] ?? 0) === 0
                && array_sum(array_column($sets, 'spend_withheld')) === 0
                && abs($setSpend - $spend) <= max(0.01, 0.005 * $spend);

            if (! $covered) {
                $put($campaignFamily->value, $row, self::rowReported($row), $campaign, 0);

                continue;
            }

            $families = [];
            foreach ($sets as $set) {
                $family = PathObjective::familyOf($provider, $set['optimization_goal'], $campaignFamily)->value;
                $families[$family] = true;
                $put($family, ['provider' => $provider, 'spend' => (float) $set['spend'], 'spend_withheld_rows' => 0] + $set['figures'], $set['reported'], $campaign, 1);
            }
            // What the ad sets do not account for (rounding, at most half a percent) stays with the campaign's own family.
            $residual = round($spend - $setSpend, 6);
            if ($residual !== 0.0) {
                $put($campaignFamily->value, ['provider' => $provider, 'spend' => $residual, 'spend_withheld_rows' => 0], [], $campaign, 0);
            }
            if (count($families) > 1) {
                $mixed[$campaign] = array_keys($families);
            }
        }

        return ['pieces' => $pieces, 'mixed' => $mixed, 'withheld' => $withheld];
    }

    /**
     * OBJECTIVE-PATHS-001 — each bounded campaign's ad sets in the window, with their goal and figures.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, list<array{spend: float, spend_withheld: int, optimization_goal: string|null, figures: array<string, float>, reported: array<string, bool>}>> keyed «campaign|provider»
     */
    private function adSetRows(Carbon $from, Carbon $to, array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $keys = ['impressions', 'clicks', 'landing_page_views', 'engagements', 'video_views', 'conversions', 'leads', 'installs', 'revenue'];
        $query = EntityDailyMetric::query()
            ->join('external_ad_sets', 'external_ad_sets.id', '=', 'entity_daily_metrics.entity_id')
            ->where('entity_daily_metrics.entity_type', EntityDailyMetric::AD_SET)
            ->whereIn('external_ad_sets.unified_campaign_id', $campaignIds)
            ->whereBetween('entity_daily_metrics.metric_date', [$from->toDateString(), $to->toDateString()])
            ->when($this->providers !== null, fn ($q) => $q->whereIn('entity_daily_metrics.provider', $this->providers ?: ['__none__']))
            ->when($this->accountIds !== null, fn ($q) => $q->whereIn('entity_daily_metrics.external_account_id', $this->accountIds ?: ['00000000-0000-0000-0000-000000000000']))
            ->when($this->excludesDemo(), fn ($q) => $q->where('entity_daily_metrics.is_demo', false))
            ->groupBy('external_ad_sets.id', 'external_ad_sets.unified_campaign_id', 'entity_daily_metrics.provider', 'external_ad_sets.optimization_goal')
            ->select('external_ad_sets.unified_campaign_id', 'entity_daily_metrics.provider', 'external_ad_sets.optimization_goal')
            ->selectRaw('COALESCE(SUM(entity_daily_metrics.spend), 0) AS spend')
            ->selectRaw('COUNT(*) FILTER (WHERE entity_daily_metrics.spend IS NULL AND entity_daily_metrics.spend_original IS NOT NULL) AS spend_withheld');
        foreach ($keys as $k) {
            $query->selectRaw("SUM(entity_daily_metrics.{$k}) AS {$k}");
        }

        $out = [];
        foreach ($query->toBase()->get() as $r) {
            $figures = [];
            $reported = [];
            foreach ($keys as $k) {
                $figures[$k] = (float) ($r->{$k} ?? 0);
                // A column left null on every day was not sent; a stated 0 is a measurement.
                $reported[$k] = $r->{$k} !== null;
            }
            // Snapchat answers 0 for a page nobody measured (ObjectiveResult::LPV_ZERO_MEANS_UNTRACKED).
            if (in_array((string) $r->provider, ObjectiveResult::LPV_ZERO_MEANS_UNTRACKED, true)) {
                $reported['landing_page_views'] = $figures['landing_page_views'] > 0;
            }
            $reported['reach'] = false;
            $out[(string) $r->unified_campaign_id.'|'.(string) $r->provider][] = [
                'spend' => (float) $r->spend,
                'spend_withheld' => (int) $r->spend_withheld,
                'optimization_goal' => $r->optimization_goal === null ? null : (string) $r->optimization_goal,
                'figures' => $figures,
                'reported' => $reported,
            ];
        }

        return $out;
    }

    /** One row per campaign, with its objective and its results, from the one metrics table. */
    /**
     * PLATFORM-DECISION-ANALYTICS-001 — each platform's contribution to each marketing path.
     *
     * ## The question the Platforms surface could not answer
     *
     * It listed platforms with one set of figures. «Which platform is contributing most to THIS
     * objective» had no answer, and the only comparison available — one number per platform, across
     * every objective at once — is the comparison that must never be made: a platform running
     * awareness and a platform running sales are not better or worse than each other, and a ranking
     * that puts them in one column invents a verdict out of the mix of work each was given.
     *
     * ## Grouped by PATH first, platform second
     *
     * Within a path the comparison is real: two platforms both buying reach can be compared on cost
     * per thousand. Across paths it is not, and the shape of this payload is what stops the second
     * one being written — there is no list that contains platforms from two paths.
     *
     * ## Comparable is a per-path fact, and often false
     *
     * A path where one platform ran is not a ranking, it is a single row; saying «Meta is the best
     * platform for awareness» when Meta is the only platform that ran awareness is a sentence with
     * no evidence behind it. `comparable` is true only where at least two platforms actually spent
     * on that path, and the reason travels with it.
     *
     * Reuses `rows()` — the same query, the same objective classification, the same fail-closed
     * bounds. A second query would eventually disagree with the paths above it about which campaign
     * is a sales campaign.
     *
     * @return array<string,mixed>
     */
    public function byPlatform(Carbon $from, Carbon $to): array
    {
        /**
         * The shape is declared because the accumulation below reaches it through a REFERENCE, and a
         * reference is where static analysis loses the type: from `'platforms' => []` alone the
         * platform map is an empty array forever, and every read of it afterwards is «offset does
         * not exist on array{}». Writing the shape down is also the only place a reader can see what
         * a platform row contains without executing the loop.
         *
         * @var array<string, array{
         *     path: string, label_ar: string, label_en: string, headline_metrics: list<string>,
         *     platforms: array<string, array<string, float|int|string|null>>
         * }> $paths
         */
        $paths = [];

        foreach (MarketingPath::cases() as $path) {
            $paths[$path->value] = [
                'path' => $path->value,
                'label_ar' => $path->labels()['ar'],
                'label_en' => $path->labels()['en'],
                'headline_metrics' => $path->headlineMetrics(),
                'platforms' => [],
            ];
        }

        foreach ($this->rows($from, $to) as $row) {
            $objective = CampaignObjective::tryFrom((string) $row->objective) ?? CampaignObjective::Other;
            $provider = (string) $row->provider;
            $bucket = &$paths[$objective->path()->value]['platforms'];

            $bucket[$provider] ??= [
                'provider' => $provider,
                'spend' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0,
                'landing_page_views' => 0.0, 'orders' => 0.0, 'revenue' => 0.0,
                // The money truth travels with the money — AGGREGATION-TRUTH-001.
                'spend_original' => 0.0, 'spend_withheld_rows' => 0,
                'revenue_original' => 0.0, 'revenue_withheld_rows' => 0,
                'campaigns' => 0,
            ];

            foreach (['spend', 'impressions', 'clicks', 'landing_page_views', 'orders', 'revenue', 'spend_original', 'revenue_original'] as $key) {
                $bucket[$provider][$key] += (float) ($row->{$key} ?? 0);
            }
            foreach (['spend_withheld_rows', 'revenue_withheld_rows'] as $key) {
                $bucket[$provider][$key] += (int) ($row->{$key} ?? 0);
            }
            $bucket[$provider]['campaigns']++;
        }

        $out = [];

        foreach ($paths as $path) {
            $platforms = array_values($path['platforms']);
            $spending = array_values(array_filter($platforms, static fn (array $p): bool => $p['spend'] > 0));
            $total = array_sum(array_map(static fn (array $p): float => $p['spend'], $platforms));

            foreach ($platforms as $i => $platform) {
                // Share of the PATH's spend, never of the project's: «40% of awareness» is a fact
                // about a decision somebody made; «40% of everything» is a fact about the mix.
                $platforms[$i]['spend_share'] = $total > 0 ? round($platform['spend'] / $total, 4) : null;
                foreach (['spend', 'impressions', 'clicks', 'landing_page_views', 'orders', 'revenue'] as $key) {
                    $platforms[$i][$key] = round($platform[$key], 2);
                }
            }

            $path['platforms'] = AdPlatforms::sortRows($platforms, 'provider');
            $path['spend'] = round($total, 2);
            /*
             * Two platforms that SPENT, not two that exist. A connected platform with no campaign on
             * this path contributes a row of zeros, and «Meta beat TikTok on awareness» where TikTok
             * ran no awareness is the fabricated verdict this flag exists to prevent.
             */
            $path['comparable'] = count($spending) > 1;
            $path['comparable_reason'] = match (true) {
                count($spending) > 1 => 'two_or_more_platforms_spent',
                count($spending) === 1 => 'only_one_platform_spent',
                default => 'nothing_spent_on_this_path',
            };
            $out[] = $path;
        }

        return [
            'paths' => $out,
            /*
             * Said in the payload rather than left to each surface to remember: this is the sentence
             * a «best platform» card would have to contradict in order to exist.
             */
            'cross_path_comparison' => false,
            'cross_path_reason_ar' => 'المنصات لا تُقارن عبر المسارات: منصة تشتري وعيًا ومنصة تشتري مبيعات لا تفضل إحداهما الأخرى — الفرق في العمل المُسند إليها، لا في أدائها.',
            'cross_path_reason_en' => 'Platforms are not compared across paths: one buying awareness and one buying sales are not better or worse than each other — what differs is the work each was given.',
        ];
    }

    /**
     * REPORT-RECOMMENDATION-BLOCKS-001 — the same rows, summed per (objective FAMILY, platform).
     *
     * `byPlatform()` groups by marketing PATH, and the conversion path holds leads and sales together:
     * judged there, a cost per lead and a cost per sale become one blended «cost per result», which is
     * the flattering mistake `result_composition` already exists to expose. A finding about what a
     * lead costs has to be computed from lead campaigns alone, so this groups by the family the
     * objective belongs to instead.
     *
     * Sums only — every ratio is left to the caller to derive from these numerators and denominators,
     * never averaged. The withheld-money counts travel beside the money for the reason `heldMoney()`
     * states: a converted sum that silently skipped rows is not the spend.
     *
     * @return list<array{family: string, provider: string, spend: float, impressions: float, clicks: float, landing_page_views: float, results: float, revenue: float, spend_withheld_rows: int, revenue_withheld_rows: int}>
     */
    public function byFamilyAndPlatform(Carbon $from, Carbon $to): array
    {
        $out = [];
        $rowsByKey = [];

        foreach ($this->rows($from, $to) as $row) {
            $objective = CampaignObjective::tryFrom((string) $row->objective) ?? CampaignObjective::Other;
            $key = $objective->family()->value.'|'.(string) $row->provider;

            $out[$key] ??= [
                'family' => $objective->family()->value,
                'provider' => (string) $row->provider,
                'spend' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0, 'landing_page_views' => 0.0,
                'results' => 0.0, 'revenue' => 0.0, 'spend_withheld_rows' => 0, 'revenue_withheld_rows' => 0,
            ];

            foreach (['spend', 'impressions', 'clicks', 'landing_page_views', 'revenue'] as $metric) {
                $out[$key][$metric] += (float) ($row->{$metric} ?? 0);
            }
            $rowsByKey[$key][] = ['conversions' => (float) ($row->orders ?? 0), 'reach' => null] + (array) $row;
            $out[$key]['spend_withheld_rows'] += (int) ($row->spend_withheld_rows ?? 0);
            $out[$key]['revenue_withheld_rows'] += (int) ($row->revenue_withheld_rows ?? 0);
        }

        /*
         * OBJECTIVE-RESULT-001 — `results` is the FAMILY's result: leads for a leads family, orders
         * for sales, visits or clicks for traffic. It was orders for every family, so a lead
         * programme's cost per lead divided its spend by purchases.
         */
        foreach ($out as $key => $row) {
            $rows = $rowsByKey[$key] ?? [];
            $result = ObjectiveResult::combine((string) $row['family'], $rows, array_map(self::rowReported(...), $rows));
            // Null when nothing was reported — «بدون بيانات», never a measured 0.
            $out[$key]['results'] = $result['result'];
            $out[$key]['result_key'] = $result['result_key'];
        }

        return array_values($out);
    }

    /**
     * OBJECTIVE-ANALYTICS-DEPTH-001 — the strongest and weakest campaign INSIDE each path.
     *
     * The same rule as `byPlatform()`, one level down. A leads campaign and an awareness campaign
     * are not better or worse than each other, so a single «top campaigns» list across a mixed
     * programme ranks them by whichever metric happens to be shared — which is how a brand campaign
     * comes to sit at the bottom of a table for not producing revenue it never sought.
     *
     * Within a path the comparison is real, and the metric is the path's own.
     *
     * `comparable` is false where fewer than two campaigns spent on the path: a strongest of one is
     * a figure wearing a superlative, and «your best sales campaign» said of the only sales campaign
     * tells a client nothing they did not already know while implying a choice was made.
     *
     * @return array<string,mixed>
     */
    /**
     * @param  'campaign'|'provider'  $by  which entity the two ends of each path name
     */
    public function leadersByPath(Carbon $from, Carbon $to, string $by = 'campaign'): array
    {
        /** @var array<string, array<string, array<string, float|int|string|null>>> $byPath */
        $byPath = [];

        foreach ($this->rows($from, $to) as $row) {
            $objective = CampaignObjective::tryFrom((string) $row->objective) ?? CampaignObjective::Other;
            /*
             * CLIENT-REPORT-ENTITY-BOUNDARY-001 — what the two ends of a path are ALLOWED to name.
             *
             * An operator asks «which campaign worked», and the campaign is the thing they can act
             * on. A client's report may not carry a campaign's internal name at all, so the same
             * question is answered one rung up: which PLATFORM worked, on this path, on this path's
             * own metric. Nothing else about the calculation changes — the same rows, the same
             * per-path metric, the same refusal to compare across paths — only what a bucket is.
             *
             * The grouping is a parameter rather than two methods because the ranking rules are the
             * product's answer to «what does better mean here», and a second copy of them would drift
             * into a second answer.
             */
            $id = $by === 'provider' ? (string) $row->provider : (string) $row->unified_campaign_id;
            $bucket = &$byPath[$objective->path()->value][$id];

            $bucket ??= [
                'id' => $id,
                'name' => $by === 'provider' ? (string) $row->provider : (string) $row->name,
                'objective' => $objective->value,
                'spend' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0,
                'landing_page_views' => 0.0, 'orders' => 0.0, 'revenue' => 0.0,
            ];

            foreach (['spend', 'impressions', 'clicks', 'landing_page_views', 'orders', 'revenue'] as $key) {
                $bucket[$key] += (float) $row->{$key};
            }
        }

        $out = [];

        foreach (MarketingPath::cases() as $path) {
            $campaigns = array_values($byPath[$path->value] ?? []);
            $spending = array_values(array_filter($campaigns, static fn (array $c): bool => $c['spend'] > 0));

            foreach ($spending as $i => $campaign) {
                // The derived figures the ranker reads. Computed here from this path's own sums, so a
                // campaign's CPA is its own and never the programme's blended one.
                $spending[$i]['cpa'] = $campaign['orders'] > 0 ? round($campaign['spend'] / $campaign['orders'], 2) : null;
                $spending[$i]['roas'] = $campaign['spend'] > 0 ? round($campaign['revenue'] / $campaign['spend'], 3) : null;
                $spending[$i]['ctr'] = $campaign['impressions'] > 0 ? round($campaign['clicks'] / $campaign['impressions'], 4) : null;
                $spending[$i]['cpm'] = $campaign['impressions'] > 0 ? round($campaign['spend'] / $campaign['impressions'] * 1000, 2) : null;
            }

            $comparable = count($spending) > 1;

            $out[] = [
                'path' => $path->value,
                'label_ar' => $path->labels()['ar'],
                'label_en' => $path->labels()['en'],
                'metric' => $path->headlineMetrics()[1] ?? 'spend',
                'comparable' => $comparable,
                'comparable_reason' => match (true) {
                    $comparable => 'two_or_more_campaigns_spent',
                    count($spending) === 1 => 'only_one_campaign_spent',
                    default => 'nothing_spent_on_this_path',
                },
                /*
                 * Both ends, or neither. A list of winners with no counterpart is where a report
                 * stops saying what to STOP — and the weakest campaign is the one an operator can
                 * act on this week.
                 */
                'strongest' => $comparable ? $this->extreme($spending, $path, best: true) : null,
                'weakest' => $comparable ? $this->extreme($spending, $path, best: false) : null,
                'campaigns' => count($spending),
            ];
        }

        return ['paths' => $out, 'cross_path_comparison' => false];
    }

    /**
     * FUNNEL-ANALYTICAL-PATTERN-001 — the funnel's shape, applied to the objective paths.
     *
     * The funnel does not draw a chart and leave the reader to interpret it. It says: here is the
     * SIGNAL, here is the CONTEXT it is measured against, here is the EXPLANATION of where it sits,
     * here is the EVIDENCE that supports it, and here is the ACTION — if the evidence supports one.
     * That sequence is the product's most-praised surface and it exists nowhere else.
     *
     * ## Every step can say nothing, and often does
     *
     * A path nobody ran has no signal. A path one campaign ran has no comparison, so it has no
     * signal either — a range needs two ends. Where there is no signal there is no action, and the
     * reason travels in its place. An action offered without evidence is worse than silence: it is
     * the product spending somebody's afternoon on its own guess.
     *
     * ## Nothing here is a benchmark
     *
     * The signal is the RANGE the path's own campaigns produced — «the cheapest order cost 10 and
     * the dearest 50» — which is arithmetic over this account's rows. No industry figure, no «good»
     * threshold, no multiple that triggers an alarm: those are numbers nobody here is entitled to
     * invent, and a reader who is told 50 is «bad» has been told something we do not know.
     *
     * The action names both ends and leaves the decision where it belongs.
     *
     * @return array<string,mixed>
     */
    public function explainByPath(Carbon $from, Carbon $to): array
    {
        $out = [];

        foreach ($this->leadersByPath($from, $to)['paths'] as $path) {
            $strongest = $path['strongest'];
            $weakest = $path['weakest'];
            $comparable = $path['comparable'] && $strongest !== null && $weakest !== null;

            $out[] = [
                'path' => $path['path'],
                'label_ar' => $path['label_ar'],
                'label_en' => $path['label_en'],
                /*
                 * The signal is a RANGE, not a verdict. Two campaigns bought for the same thing, and
                 * what each one cost — the reader can see the distance without being told what to
                 * think about it.
                 */
                'signal' => $comparable ? [
                    'metric' => $strongest['metric'],
                    'best' => ['campaign' => $strongest['name'], 'value' => $strongest['value']],
                    'worst' => ['campaign' => $weakest['name'], 'value' => $weakest['value']],
                ] : null,
                // What it is measured against: this path, in this window, and nothing outside it.
                'context' => $comparable ? [
                    'scope' => $path['path'],
                    'campaigns' => $path['campaigns'],
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ] : null,
                'explanation' => $comparable ? [
                    'ar' => 'الحملتان اشتُريتا لنفس الغرض على هذا المسار، فالفارق بينهما فارق في التنفيذ لا في الهدف.',
                    'en' => 'Both campaigns were bought for the same thing on this path, so the distance between them is a difference in execution rather than in intent.',
                ] : null,
                // The keys the reading rests on, named — so «why does it say that» has an answer.
                'evidence' => $comparable ? ['spend', $strongest['metric']] : [],
                /*
                 * An action ONLY where two comparable campaigns exist. It names both ends and stops:
                 * moving budget is a decision with a client's money in it, and the product's part is
                 * to put the two figures in front of the person who makes it.
                 */
                'action' => $comparable ? [
                    'ar' => "قارن «{$weakest['name']}» بـ«{$strongest['name']}» قبل أن يأخذ الأضعف مزيدًا من ميزانية هذا المسار.",
                    'en' => "Compare «{$weakest['name']}» against «{$strongest['name']}» before the weaker one takes more of this path's budget.",
                ] : null,
                // Why there is nothing to say, when there is nothing to say.
                'silent_reason' => $comparable ? null : $path['comparable_reason'],
            ];
        }

        return ['paths' => $out];
    }

    /**
     * The best or worst campaign on a path, by the path's own metric.
     *
     * Cost metrics invert: the lowest cost per order is the strongest, and reading them the same way
     * round as a volume metric is how «best» came to name the most expensive campaign.
     *
     * @param  list<array<string,mixed>>  $campaigns
     * @return array<string,mixed>|null
     */
    private function extreme(array $campaigns, MarketingPath $path, bool $best): ?array
    {
        $metric = match ($path) {
            MarketingPath::Awareness => 'cpm',
            MarketingPath::Traffic => 'ctr',
            MarketingPath::Conversion => 'cpa',
        };
        $lowerIsBetter = in_array($metric, ['cpm', 'cpa'], true);

        $withMetric = array_values(array_filter($campaigns, static fn (array $c): bool => $c[$metric] !== null));

        if ($withMetric === []) {
            return null;
        }

        usort($withMetric, static fn (array $a, array $b): int => $a[$metric] <=> $b[$metric]);

        $row = $best === $lowerIsBetter ? $withMetric[0] : $withMetric[count($withMetric) - 1];

        return ['id' => $row['id'], 'name' => $row['name'], 'metric' => $metric, 'value' => $row[$metric]];
    }

    /**
     * OBJECTIVE-ANALYTICS-DEPTH-001 — each path, day by day, in the metric that path was buying.
     *
     * ## Why a trend per PATH and not one trend with a path filter
     *
     * A single series over a mixed programme moves for reasons that have nothing to do with each
     * other: awareness spend rising while sales spend falls is one line going nowhere, and a reader
     * watching it concludes the account is flat. Separated, the same two weeks say «brand went up,
     * sales went down», which is a sentence somebody can act on.
     *
     * ## Every day in the window appears, including the empty ones
     *
     * A day with no row is not a day the chart may skip: skipping it draws the line straight through
     * a gap and turns a pause into a slope. Days that reported nothing carry `reported: false`, so a
     * renderer can break the line rather than inventing a value across it — the same rule the funnel
     * and the KPI cards follow for a metric no platform sent.
     *
     * ## The cost is derived per DAY, never averaged from the totals
     *
     * A window's cost per result is the window's spend over the window's results. A day's is that
     * day's, and the two are different numbers whenever a day's results land after its spend — which
     * is every attribution model there is. Deriving one from the other is how a chart comes to
     * disagree with the card above it.
     *
     * @return array<string,mixed>
     */
    public function trendByPath(Carbon $from, Carbon $to): array
    {
        $rows = $this->dailyRows($from, $to);

        /*
         * OBJECTIVE-RESULT-001 — the line's unit is the path TOTAL's unit, decided by the same rule
         * (`pathResult`) over the same window, so the chart and the table can never disagree: visits
         * only where every contributor measures them, else clicks; no line where the path mixes kinds.
         */
        $byPathRows = [];
        foreach ($this->rows($from, $to) as $row) {
            $objective = CampaignObjective::tryFrom((string) $row->objective) ?? CampaignObjective::Other;
            $byPathRows[$objective->path()->value][] = ['conversions' => (float) $row->orders] + (array) $row;
        }
        $pathKeys = [];
        foreach ($byPathRows as $value => $pathRows) {
            $pathKeys[$value] = self::pathResult(MarketingPath::from($value), $pathRows)['result_key'];
        }

        /** @var array<string, array<string, array{spend: float, results: float, revenue: float, impressions: float, clicks: float}>> $byPath */
        $byPath = [];

        foreach ($rows as $row) {
            $objective = CampaignObjective::tryFrom((string) $row->objective) ?? CampaignObjective::Other;
            $path = $objective->path()->value;
            $date = (string) $row->metric_date;

            $byPath[$path][$date] ??= ['spend' => 0.0, 'results' => 0.0, 'revenue' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0, 'landing_page_views' => 0.0, 'leads' => 0.0, 'form_leads' => 0.0, 'installs' => 0.0, 'withheld' => 0];
            $byPath[$path][$date]['withheld'] += (int) ($row->spend_withheld_rows ?? 0);
            $byPath[$path][$date]['spend'] += (float) $row->spend;
            $byPath[$path][$date]['results'] += (float) $row->orders;
            foreach (['landing_page_views', 'leads', 'form_leads', 'installs'] as $metric) {
                $byPath[$path][$date][$metric] += (float) ($row->{$metric} ?? 0);
            }
            $byPath[$path][$date]['revenue'] += (float) $row->revenue;
            $byPath[$path][$date]['impressions'] += (float) $row->impressions;
            $byPath[$path][$date]['clicks'] += (float) $row->clicks;
        }

        $days = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $days[] = $day->toDateString();
        }

        $out = [];

        foreach (MarketingPath::cases() as $path) {
            $measured = $byPath[$path->value] ?? [];

            // A path nobody spent on in this window is absent rather than a flat line at zero: a
            // chart of nothing reads as a result, and «nothing ran» is a different fact.
            if ($measured === []) {
                continue;
            }

            /*
             * OBJECTIVE-RESULT-001 — one result unit per path for the whole window, the same rule as
             * the path's total: impressions for awareness, visits (else clicks) for traffic, orders
             * for conversion. Chosen over the window so a line never changes unit halfway along.
             */
            $resultKey = $pathKeys[$path->value] ?? null;
            $resultKey = $resultKey === 'conversions' ? 'results' : $resultKey;
            foreach ($measured as $date => $point) {
                // A path with no common unit (sales, leads and installs together) draws no results line.
                $measured[$date]['results'] = $resultKey === null ? null : ($point[$resultKey] ?? null);
            }

            $series = [];

            foreach ($days as $date) {
                $point = $measured[$date] ?? null;

                $series[] = [
                    'date' => $date,
                    'reported' => $point !== null,
                    'spend' => $point === null ? null : round($point['spend'], 2),
                    'results' => $point === null || $point['results'] === null ? null : round($point['results'], 2),
                    'revenue' => $point === null ? null : round($point['revenue'], 2),
                    // Derived from THIS day's own sums — never from the window's, which is a
                    // different number the moment a result lands after the spend that bought it.
                    // DERIVED-WITHHELD-001 — a day with withheld spend states no cost.
                    'cost_per_result' => $point === null || $point['results'] === null || $point['results'] <= 0 || $point['withheld'] > 0
                        ? null
                        : round($point['spend'] / $point['results'], 2),
                    'cpm' => $point === null || $point['impressions'] <= 0 || $point['withheld'] > 0
                        ? null
                        : round(($point['spend'] / $point['impressions']) * 1000, 2),
                ];
            }

            $publicKey = $pathKeys[$path->value] ?? null;

            $out[] = [
                'path' => $path->value,
                'label_ar' => $path->labels()['ar'],
                'label_en' => $path->labels()['en'],
                'headline_metrics' => $path->headlineMetrics(),
                'result_key' => $publicKey,
                'result_label' => ObjectiveResult::label($publicKey),
                'days' => $series,
                'days_reported' => count($measured),
                'days_in_window' => count($days),
            ];
        }

        return ['paths' => $out];
    }

    /**
     * The bounds every read here shares — project, campaign, platform, account, and the active-binding rule.
     *
     * KPI-PARITY-001 — ONE statement of the bounds, so `rows()`, `dailyRows()` and the demo-policy check
     * cannot drift from each other. No date window: the demo policy is a property of the SCOPE, exactly
     * as {@see MetricsAggregator::excludesDemo()} reads it.
     *
     * @return Builder<DailyMetric>
     */
    private function bounded(): mixed
    {
        return DailyMetric::query()
            ->when($this->projectIds !== null, fn ($q) => $q->withoutGlobalScope(ProjectScope::class))
            // ACCOUNT-SCOPE-ISOLATION-001 — the same visibility rule the aggregator applies.
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->when($this->projectIds !== null, fn ($q) => $q->whereIn(
                'daily_metrics.project_id',
                $this->projectIds ?: ['00000000-0000-0000-0000-000000000000'],
            ))
            ->when($this->campaignIds !== null, fn ($q) => $q->whereIn(
                'daily_metrics.unified_campaign_id',
                $this->campaignIds ?: ['00000000-0000-0000-0000-000000000000'],
            ))
            // Empty list → match nothing, the same fail-closed reading the other bounds use.
            ->when($this->providers !== null, fn ($q) => $q->whereIn('daily_metrics.provider', $this->providers ?: ['__none__']))
            ->when($this->accountIds !== null, fn ($q) => $q->whereIn(
                'daily_metrics.external_account_id',
                $this->accountIds ?: ['00000000-0000-0000-0000-000000000000'],
            ));
    }

    /**
     * KPI-PARITY-001 — the aggregator's demo policy, applied here too.
     *
     * `MetricsAggregator::base()` drops `is_demo` rows the moment the scope holds a real one, so the
     * dashboard's spend is the customer's own money. This service read the same table WITHOUT that
     * rule, so the objective split on the analytics tab and on the client link carried seeded rows
     * the headline beside it had excluded — 2,999 under a total of 2,000 on the parity world. Same
     * question, same answer: a scope with any real row excludes every demo row.
     */
    private function excludesDemo(): bool
    {
        return $this->excludesDemo ??= ReportReadMemo::remember(
            'objective-performance-demo|'.$this->scopeKey(),
            fn (): bool => $this->bounded()->where('daily_metrics.is_demo', false)->exists(),
        );
    }

    private ?bool $excludesDemo = null;

    /** The same bounded read as {@see rows()}, one row per (day, campaign, platform). */
    private function dailyRows(Carbon $from, Carbon $to): Collection
    {
        return $this->bounded()
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->when($this->excludesDemo(), fn ($q) => $q->where('daily_metrics.is_demo', false))
            ->join('unified_campaigns', 'unified_campaigns.id', '=', 'daily_metrics.unified_campaign_id')
            ->groupBy('daily_metrics.metric_date', 'unified_campaigns.objective')
            ->select('daily_metrics.metric_date', 'unified_campaigns.objective')
            ->selectRaw($this->sum('spend'))
            ->selectRaw($this->heldMoney('spend'))
            ->selectRaw($this->sum('impressions'))
            ->selectRaw($this->sum('clicks'))
            ->selectRaw($this->sum('landing_page_views'))
            ->selectRaw($this->sum('leads'))
            ->selectRaw($this->sum('form_leads'))
            ->selectRaw($this->sum('installs'))
            ->selectRaw($this->sum('revenue'))
            ->selectRaw($this->heldMoney('revenue'))
            ->selectRaw("COALESCE(SUM(daily_metrics.value) FILTER (WHERE metric_key = 'conversions'), 0) AS orders")
            ->toBase()
            ->get();
    }

    private function rows(Carbon $from, Carbon $to): Collection
    {
        /*
         * PERF-RECOVERY-001 — one read per (scope, window) inside a report build.
         *
         * The path totals, the strongest-and-weakest, the family split and the attention findings each read these same rows, and the
         * read is a join and a grouped scan of `daily_metrics` apiece. Everything it depends on is in the key: the constructor's four
         * bounds, the window, and the tenant and project the global scopes read from. Callers only iterate it.
         */
        return ReportReadMemo::remember(
            'objective-performance|'.$this->scopeKey()
                .'|'.$from->toDateString().'|'.$to->toDateString(),
            fn (): Collection => $this->readRows($from, $to),
        );
    }

    /** Everything the bounded read depends on: the constructor's four bounds, and the tenant and project the global scopes read from. */
    private function scopeKey(): string
    {
        $bound = static fn (?array $list): string => $list === null ? '*' : implode(',', array_map('strval', $list));

        return app(TenantContext::class)->tenantId().'|'.app(ProjectContext::class)->projectId()
            .'|p='.$bound($this->projectIds).'|c='.$bound($this->campaignIds).'|v='.$bound($this->providers).'|a='.$bound($this->accountIds);
    }

    private function readRows(Carbon $from, Carbon $to): Collection
    {
        $query = $this->bounded()
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->when($this->excludesDemo(), fn ($q) => $q->where('daily_metrics.is_demo', false))
            ->join('unified_campaigns', 'unified_campaigns.id', '=', 'daily_metrics.unified_campaign_id')
            // `provider` joins the key so one row per (campaign, platform) exists — the grain
            // `byPlatform()` reads. The path totals above sum these rows and are unchanged by it.
            ->groupBy('daily_metrics.unified_campaign_id', 'daily_metrics.provider', 'unified_campaigns.name', 'unified_campaigns.objective', 'unified_campaigns.objective_source')
            ->select('daily_metrics.unified_campaign_id', 'daily_metrics.provider', 'unified_campaigns.name', 'unified_campaigns.objective', 'unified_campaigns.objective_source')
            ->selectRaw($this->sum('spend'))
            ->selectRaw($this->heldMoney('spend'))
            ->selectRaw($this->sum('impressions'))
            ->selectRaw($this->sum('clicks'))
            ->selectRaw($this->sum('landing_page_views'))
            // OBJECTIVE-RESULT-001 — whether this platform SENT landing page views at all (a reported 0 counts).
            ->selectRaw("COUNT(*) FILTER (WHERE metric_key = 'landing_page_views') AS lpv_rows")
            // OBJECTIVE-RESULT-001 — each family's own result, so no family is judged on orders it never bought.
            ->selectRaw($this->sum('engagements'))
            ->selectRaw($this->sum('video_views'))
            ->selectRaw($this->sum('leads'))
            ->selectRaw($this->sum('form_leads'))
            ->selectRaw($this->sum('installs'))
            ->selectRaw($this->sum('revenue'))
            ->selectRaw($this->heldMoney('revenue'))
            /*
             * An «order» is a `conversions` row, and ONLY a `conversions` row.
             *
             * `purchases` is also stored and also read — `MetricsAggregator` reports it as its own
             * figure — but the product's cost-per-order has always been `spend ÷ conversions`, on
             * the dashboard, in the analytics breakdowns and in the report's own `kpis`. Counting
             * `purchases + conversions` here made this service more complete and made it DISAGREE
             * with every other surface, which is worse: two definitions of an order means the
             * report's CPA and the dashboard's differ for the same scope, and nobody can say which
             * is the product's answer. One definition, applied everywhere, beats a better
             * definition applied in one place.
             *
             * Summing them would also double-count outright on any integration that reports the same
             * sale under both keys.
             */
            ->selectRaw("COALESCE(SUM(daily_metrics.value) FILTER (WHERE metric_key = 'conversions'), 0) AS orders");

        return $query->toBase()->get();
    }

    private function sum(string $key): string
    {
        return "COALESCE(SUM(daily_metrics.value) FILTER (WHERE metric_key = '{$key}'), 0) AS {$key}";
    }

    /**
     * AGGREGATION-TRUTH-001 — the money this service was reporting as zero.
     *
     * `sum()` reads `value`, the CONVERTED column, and coalesces null to 0. FX-001 withholds a
     * conversion when no rate exists rather than inventing one, so a withheld row is exactly
     * `value IS NULL` with `original_amount` intact — and `MetricsAggregator` says production's rows
     * are «entirely withheld and entirely USD».
     *
     * Measured on a real row: with the row withheld, this service's spend came back 0 while 175 was
     * sitting in `original_amount`. Every path then read as zero spend, `LiveDetailTables` drops
     * paths at `spend > 0`, and the objective decomposition disappeared from the analytics tab AND
     * the client report — a whole section gone, on the accounts where the money is real.
     *
     * So the truth travels beside the figure, exactly as `MetricsAggregator::MONEY_TRUTH` does:
     * what was held, and how many rows held it. A summed original with no withheld rows behind it
     * claims nothing — that is what a sum of nothing produces.
     */
    private function heldMoney(string $key): string
    {
        return "COALESCE(SUM(daily_metrics.original_amount) FILTER (WHERE metric_key = '{$key}' AND daily_metrics.value IS NULL AND daily_metrics.original_amount IS NOT NULL), 0) AS {$key}_original, "
            ."COUNT(*) FILTER (WHERE metric_key = '{$key}' AND daily_metrics.value IS NULL AND daily_metrics.original_amount IS NOT NULL) AS {$key}_withheld_rows";
    }

    private function emptyPath(MarketingPath $path): array
    {
        return [
            'path' => $path->value,
            'label_ar' => $path->labels()['ar'],
            'label_en' => $path->labels()['en'],
            'headline_metrics' => $path->headlineMetrics(),
            'spend' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0,
            'landing_page_views' => 0.0, 'orders' => 0.0, 'revenue' => 0.0,
            'campaigns' => [],
            /* Objective value => that objective's own orders. Emptied into a list by `derivePath`. */
            'result_composition' => [],
            /*
             * AGGREGATION-TRUTH-001 — these zeros describe an EMPTY PATH, not a quiet one.
             *
             * A project with no awareness campaign at all and a project whose awareness campaigns
             * spent nothing produce the identical row, and they are different facts: the first has no
             * contributor, the second has one that did nothing. Read as spend, the zeros say «we ran
             * awareness and it cost nothing», which is a claim about money that was never budgeted.
             *
             * The figures stay zero because callers sum them and a null would break that arithmetic;
             * the state travels beside them instead, which is the same separation `AggregateCoverage`
             * makes. A surface that shows this path can now say «no campaigns on this path» rather
             * than printing a row of zeros that invites a comparison against the paths that ran.
             */
            'coverage' => [
                'state' => 'no_contributors',
                'expected_contributors' => [],
                'included_contributors' => [],
                'excluded_contributors' => [],
            ],
        ];
    }

    private function derivePath(array $b, MarketingPath $path): array
    {
        /*
         * Cost per order and return on spend are NOT APPLICABLE outside the conversion path, and
         * that is different from being zero.
         *
         * The arithmetic would happily produce one: an awareness path that spent 4000 and was
         * attributed no revenue divides to a ROAS of exactly 0. It is true and it is a claim — «this
         * money returned nothing» — about money that was never spent to return anything. A reader
         * comparing that 0 against the sales path's 10 draws the obvious and wrong conclusion, which
         * is the same misreading this whole unit exists to prevent, arrived at from the other side.
         */
        $sellsThings = $path === MarketingPath::Conversion;

        /*
         * The composition, ordered by size, naming only objectives that actually produced a result.
         *
         * An objective that ran and converted nobody is not part of what the number is made of, and
         * listing it at zero would invite a reader to divide by it. `Other` appears under its own
         * label rather than being folded away — an objective the product could not classify is a
         * fact about the data, and hiding it inside a neighbour is the failure this block prevents.
         */
        $contributed = array_filter($b['result_composition'] ?? [], static fn (float $n): bool => $n > 0);
        arsort($contributed);

        $composition = [];
        foreach ($contributed as $value => $orders) {
            $objective = CampaignObjective::tryFrom((string) $value) ?? CampaignObjective::Other;

            $composition[] = [
                'objective' => $objective->value,
                'label_ar' => $objective->labels()['ar'],
                'label_en' => $objective->labels()['en'],
                'orders' => round($orders),
            ];
        }

        $mixed = count($composition) > 1;

        $spendWithheld = (int) ($b['spend_withheld_rows'] ?? 0) > 0;
        $revenueWithheld = (int) ($b['revenue_withheld_rows'] ?? 0) > 0;

        $result = self::pathResult($path, $b['_result_rows'] ?? []);
        // A path is not a family; the family the rule was borrowed from is not a fact about the path.
        unset($b['_result_rows'], $result['result_family']);

        return [
            ...$b,
            ...$result,
            'spend' => round($b['spend'], 2),
            'impressions' => round($b['impressions']),
            'clicks' => round($b['clicks']),
            'landing_page_views' => round($b['landing_page_views']),
            'orders' => round($b['orders']),
            'revenue' => round($b['revenue'], 2),
            'cpm' => ! $spendWithheld && $b['impressions'] > 0 ? round($b['spend'] / $b['impressions'] * 1000, 2) : null,
            'cpc' => $spendWithheld ? null : $this->ratio($b['spend'], $b['clicks']),
            'ctr' => $b['impressions'] > 0 ? round($b['clicks'] / $b['impressions'], 4) : null,
            'cost_per_lpv' => $spendWithheld ? null : $this->ratio($b['spend'], $b['landing_page_views']),
            // Null on every path that does not sell — see `$sellsThings` above.
            'cpa' => $sellsThings && ! $spendWithheld ? $this->ratio($b['spend'], $b['orders']) : null,
            'roas' => $sellsThings && ! $spendWithheld && ! $revenueWithheld ? $this->ratio($b['revenue'], $b['spend']) : null,
            'result_metrics_apply' => $sellsThings,
            'result_composition' => $composition,
            /*
             * True when more than one KIND of result is inside `orders` — so a surface showing the
             * figure, or the `cpa` derived from it, has to say so. False for a path whose results are
             * all one kind, which is the ordinary case and must not be decorated with a warning.
             */
            'results_mixed' => $mixed,
            'cpa_mixes_result_types' => $sellsThings && $mixed,
        ];
    }

    /**
     * OBJECTIVE-RESULT-001 — the result a PATH is judged on, in one unit.
     *
     * Every path's «النتائج» column was its orders, so the traffic path of a project whose traffic
     * campaigns brought thousands of visits read «0». The awareness path's families (awareness,
     * engagement, video) share no result but impressions, and reach is never additive; the traffic
     * path reads landing page views where every contributor has them, else clicks; the conversion
     * path keeps orders, with the mix of result types it already discloses.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function pathResult(MarketingPath $path, array $rows): array
    {
        $reported = array_map(self::rowReported(...), $rows);

        if ($path === MarketingPath::Awareness) {
            return ObjectiveResult::combine(ObjectiveFamily::Awareness, array_map(static fn (array $r): array => ['reach' => null] + $r, $rows), $reported);
        }

        if ($path === MarketingPath::Traffic) {
            return ObjectiveResult::combine(ObjectiveFamily::Traffic, $rows, $reported);
        }

        /*
         * The conversion path holds sales, leads and app installs. One family: its own result. More
         * than one: no total — an order, a lead and an install are not one unit, and naming a lead
         * «الطلبات» on the client's table is the defect this path had.
         */
        $families = array_values(array_unique(array_map(
            static fn (array $r): string => ObjectiveResult::familyOf(isset($r['objective']) ? (string) $r['objective'] : null)->value,
            $rows,
        )));

        return count($families) === 1
            ? ObjectiveResult::combine($families[0], $rows, $reported)
            : ObjectiveResult::none($rows === [] ? 'not_reported' : 'mixed', $rows);
    }

    /**
     * OBJECTIVE-RESULT-001 — what one (campaign, platform) row reported, for the traffic rule.
     *
     * Landing page views count as reported when the platform sent the metric — except a platform
     * whose 0 means «not tracked» ({@see ObjectiveResult::LPV_ZERO_MEANS_UNTRACKED}), which counts
     * only from a positive figure. Every other key reads as the figures-only rule would.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, bool>
     */
    private static function rowReported(array $row): array
    {
        $untracked = in_array((string) ($row['provider'] ?? ''), ObjectiveResult::LPV_ZERO_MEANS_UNTRACKED, true);
        $lpv = $untracked ? (float) ($row['landing_page_views'] ?? 0) > 0 : (int) ($row['lpv_rows'] ?? 0) > 0;

        return ['landing_page_views' => $lpv] + array_fill_keys(['reach', 'impressions', 'clicks', 'engagements', 'video_views', 'leads', 'form_leads', 'conversions', 'installs'], true);
    }

    /** Null on a zero denominator — a 0 would read as a real, excellent result. */
    private function ratio(float $numerator, float $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator, 2) : null;
    }

    public static function scoped(?array $projectIds = null, ?array $campaignIds = null): self
    {
        return new self($projectIds, $campaignIds);
    }
}
