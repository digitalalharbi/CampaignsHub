<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Concerns\ProjectScope;
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
    /** @param  list<string>|null  $projectIds  null = whatever the project scope already bounds */
    public function __construct(
        private readonly ?array $projectIds = null,
        private readonly ?array $campaignIds = null,
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
            ->groupBy('daily_metrics.unified_campaign_id', 'unified_campaigns.name', 'unified_campaigns.objective', 'unified_campaigns.objective_source')
            ->select('daily_metrics.unified_campaign_id', 'unified_campaigns.name', 'unified_campaigns.objective', 'unified_campaigns.objective_source')
            ->selectRaw($this->sum('spend'))
            ->selectRaw($this->sum('impressions'))
            ->selectRaw($this->sum('clicks'))
            ->selectRaw($this->sum('landing_page_views'))
            ->selectRaw($this->sum('revenue'))
            // An «order» is a purchase where the store confirmed one, and a platform-reported
            // conversion otherwise. Summing both keys would count the same sale twice on any
            // integration that reports it under each.
            ->selectRaw("COALESCE(SUM(daily_metrics.value) FILTER (WHERE metric_key = 'purchases'), 0)
                       + COALESCE(SUM(daily_metrics.value) FILTER (WHERE metric_key = 'conversions'), 0) AS orders");

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
