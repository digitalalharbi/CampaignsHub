<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Concerns\ProjectScope;
use App\Support\AdPlatforms;
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
        $included = [];
        $excluded = [];

        foreach ($rows as $row) {
            $objective = CampaignObjective::tryFrom((string) $row->objective) ?? CampaignObjective::Other;
            $path = $objective->path();
            $bucket = &$paths[$path->value];

            $bucket['spend'] += (float) $row->spend;
            $bucket['impressions'] += (float) $row->impressions;
            $bucket['clicks'] += (float) $row->clicks;
            $bucket['landing_page_views'] += (float) $row->landing_page_views;
            $bucket['orders'] += (float) $row->orders;
            $bucket['revenue'] += (float) $row->revenue;
            $bucket['campaigns'][] = [
                'id' => $row->unified_campaign_id,
                'name' => $row->name,
                'objective' => $objective->value,
                'objective_label_ar' => $objective->labels()['ar'],
                'objective_source' => $row->objective_source ?? 'unset',
                'spend' => round((float) $row->spend, 2),
            ];

            $totalSpend += (float) $row->spend;

            // The whole rule, in four lines: only a SALES campaign's money reaches the sales figures.
            if ($objective->isSales()) {
                $salesSpend += (float) $row->spend;
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
                'cpa' => $this->ratio($salesSpend, $salesOrders),
                'roas' => $this->ratio($salesRevenue, $salesSpend),
                'aov' => $this->ratio($salesRevenue, $salesOrders),
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
                'blended_cpa' => $this->ratio($totalSpend, $salesOrders),
                'blended_roas' => $this->ratio($salesRevenue, $totalSpend),
                'formula' => [
                    'blended_cpa' => 'spend on EVERY path ÷ sales-path orders',
                    'blended_roas' => 'sales-attributed revenue ÷ spend on EVERY path',
                ],
                'includes_non_sales_spend' => round($totalSpend - $salesSpend, 2),
                'never_substitutes_direct' => true,
            ],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ];
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
                'campaigns' => 0,
            ];

            foreach (['spend', 'impressions', 'clicks', 'landing_page_views', 'orders', 'revenue'] as $key) {
                $bucket[$provider][$key] += (float) $row->{$key};
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

        /** @var array<string, array<string, array{spend: float, results: float, revenue: float, impressions: float, clicks: float}>> $byPath */
        $byPath = [];

        foreach ($rows as $row) {
            $objective = CampaignObjective::tryFrom((string) $row->objective) ?? CampaignObjective::Other;
            $path = $objective->path()->value;
            $date = (string) $row->metric_date;

            $byPath[$path][$date] ??= ['spend' => 0.0, 'results' => 0.0, 'revenue' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0];
            $byPath[$path][$date]['spend'] += (float) $row->spend;
            $byPath[$path][$date]['results'] += (float) $row->orders;
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

            $series = [];

            foreach ($days as $date) {
                $point = $measured[$date] ?? null;

                $series[] = [
                    'date' => $date,
                    'reported' => $point !== null,
                    'spend' => $point === null ? null : round($point['spend'], 2),
                    'results' => $point === null ? null : round($point['results'], 2),
                    'revenue' => $point === null ? null : round($point['revenue'], 2),
                    // Derived from THIS day's own sums — never from the window's, which is a
                    // different number the moment a result lands after the spend that bought it.
                    'cost_per_result' => $point === null || $point['results'] <= 0
                        ? null
                        : round($point['spend'] / $point['results'], 2),
                    'cpm' => $point === null || $point['impressions'] <= 0
                        ? null
                        : round(($point['spend'] / $point['impressions']) * 1000, 2),
                ];
            }

            $out[] = [
                'path' => $path->value,
                'label_ar' => $path->labels()['ar'],
                'label_en' => $path->labels()['en'],
                'headline_metrics' => $path->headlineMetrics(),
                'days' => $series,
                'days_reported' => count($measured),
                'days_in_window' => count($days),
            ];
        }

        return ['paths' => $out];
    }

    /** The same bounded read as {@see rows()}, one row per (day, campaign, platform). */
    private function dailyRows(Carbon $from, Carbon $to): Collection
    {
        return DailyMetric::query()
            ->when($this->projectIds !== null, fn ($q) => $q->withoutGlobalScope(ProjectScope::class))
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->join('unified_campaigns', 'unified_campaigns.id', '=', 'daily_metrics.unified_campaign_id')
            ->when($this->projectIds !== null, fn ($q) => $q->whereIn(
                'daily_metrics.project_id',
                $this->projectIds ?: ['00000000-0000-0000-0000-000000000000'],
            ))
            ->when($this->campaignIds !== null, fn ($q) => $q->whereIn(
                'daily_metrics.unified_campaign_id',
                $this->campaignIds ?: ['00000000-0000-0000-0000-000000000000'],
            ))
            ->when($this->providers !== null, fn ($q) => $q->whereIn('daily_metrics.provider', $this->providers ?: ['__none__']))
            ->when($this->accountIds !== null, fn ($q) => $q->whereIn(
                'daily_metrics.external_account_id',
                $this->accountIds ?: ['00000000-0000-0000-0000-000000000000'],
            ))
            ->groupBy('daily_metrics.metric_date', 'unified_campaigns.objective')
            ->select('daily_metrics.metric_date', 'unified_campaigns.objective')
            ->selectRaw($this->sum('spend'))
            ->selectRaw($this->sum('impressions'))
            ->selectRaw($this->sum('clicks'))
            ->selectRaw($this->sum('revenue'))
            ->selectRaw("COALESCE(SUM(daily_metrics.value) FILTER (WHERE metric_key = 'conversions'), 0) AS orders")
            ->toBase()
            ->get();
    }

    private function rows(Carbon $from, Carbon $to): Collection
    {
        $query = DailyMetric::query()
            ->when($this->projectIds !== null, fn ($q) => $q->withoutGlobalScope(ProjectScope::class))
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->join('unified_campaigns', 'unified_campaigns.id', '=', 'daily_metrics.unified_campaign_id')
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
            ))
            // `provider` joins the key so one row per (campaign, platform) exists — the grain
            // `byPlatform()` reads. The path totals above sum these rows and are unchanged by it.
            ->groupBy('daily_metrics.unified_campaign_id', 'daily_metrics.provider', 'unified_campaigns.name', 'unified_campaigns.objective', 'unified_campaigns.objective_source')
            ->select('daily_metrics.unified_campaign_id', 'daily_metrics.provider', 'unified_campaigns.name', 'unified_campaigns.objective', 'unified_campaigns.objective_source')
            ->selectRaw($this->sum('spend'))
            ->selectRaw($this->sum('impressions'))
            ->selectRaw($this->sum('clicks'))
            ->selectRaw($this->sum('landing_page_views'))
            ->selectRaw($this->sum('revenue'))
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

        return [
            ...$b,
            'spend' => round($b['spend'], 2),
            'impressions' => round($b['impressions']),
            'clicks' => round($b['clicks']),
            'landing_page_views' => round($b['landing_page_views']),
            'orders' => round($b['orders']),
            'revenue' => round($b['revenue'], 2),
            'cpm' => $b['impressions'] > 0 ? round($b['spend'] / $b['impressions'] * 1000, 2) : null,
            'cpc' => $this->ratio($b['spend'], $b['clicks']),
            'ctr' => $b['impressions'] > 0 ? round($b['clicks'] / $b['impressions'], 4) : null,
            'cost_per_lpv' => $this->ratio($b['spend'], $b['landing_page_views']),
            // Null on every path that does not sell — see `$sellsThings` above.
            'cpa' => $sellsThings ? $this->ratio($b['spend'], $b['orders']) : null,
            'roas' => $sellsThings ? $this->ratio($b['revenue'], $b['spend']) : null,
            'result_metrics_apply' => $sellsThings,
        ];
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
