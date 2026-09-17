<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\ObjectivePerformance;
use Illuminate\Support\Carbon;

/**
 * REPORT-DRILLDOWN-001 — ONE model of «one platform, opened», for every surface that draws it.
 *
 * The live drawer and the PDF's optional drill-down section answer the same question and must not
 * compute it twice: a PDF that read a platform's share on a different outcome, or rounded a ratio
 * differently, would be a second answer to one question in a file the client keeps. Each caller
 * supplies its own bounds (a share's ceiling, a report's scope) as an already-scoped engine and an
 * `ObjectivePerformance`, plus its own content lists; everything derived from them is decided here.
 *
 * Nothing in the output names a campaign: objective blocks carry the path and its figures only.
 */
final class PlatformDrilldownBuilder
{
    /**
     * @param  list<array<string, mixed>>  $whole  every platform's row over the same bounds (byProvider)
     * @return array<string, mixed>
     */
    public function build(
        string $provider,
        array $whole,
        MetricsAggregator $engine,
        ObjectivePerformance $objectives,
        ReportObjectiveLens $lens,
        Carbon $from,
        Carbon $to,
        bool $hideSpend,
        bool $hideRevenue,
        bool $withObjectives = true,
    ): array {
        $row = collect($whole)->firstWhere('provider', $provider) ?? [];
        $outcome = $this->outcomeMetric($lens, $hideRevenue);

        $sum = static fn (string $key): float => array_sum(array_map(static fn (array $r): float => (float) ($r[$key] ?? 0), $whole));
        $shareOf = static fn (float $value, float $total): ?float => $total > 0 ? round($value / $total, 4) : null;

        $totals = ClientEntityBoundary::coverage($engine->totals($from, $to));

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $from->diffInDays($to) + 1],
            'provider' => $provider,
            'objective' => ['key' => $lens->value(), 'ranking' => $lens->rankingMetric()['key']],
            'objectives' => $withObjectives ? $this->objectiveBlocks($objectives->build($from, $to), $totals) : [],
            'totals' => $totals,
            'timeseries' => $engine->timeseries($from, $to),
            'shares' => [
                'spend' => $hideSpend ? null : [
                    'value' => (float) ($row['spend'] ?? 0),
                    'total' => $sum('spend'),
                    'share' => $shareOf((float) ($row['spend'] ?? 0), $sum('spend')),
                ],
                'outcome' => [
                    'metric' => $outcome,
                    'value' => (float) ($row[$outcome] ?? 0),
                    'total' => $sum($outcome),
                    'share' => $shareOf((float) ($row[$outcome] ?? 0), $sum($outcome)),
                ],
            ],
        ];
    }

    /**
     * The objective blocks of one platform — the paths it spent on, each with its own headline metrics.
     *
     * SEAM — lane `report-objective-analytics` is building the canonical objective → metric mapping
     * around `ObjectivePerformance`. Until it lands, the mapping is `MarketingPath::headlineMetrics()`
     * as `ObjectivePerformance` already derives it; a metric that service does not compute is left
     * out rather than printed as «—», because it is not unavailable — it is not asked here. Swap this
     * method's body for that service and the payload shape stays.
     *
     * Campaign lists are never read: only the path, its labels and its figures leave this method.
     *
     * @param  array<string, mixed>  $objectives
     * @param  array<string, mixed>  $totals
     * @return list<array<string, mixed>>
     */
    private function objectiveBlocks(array $objectives, array $totals): array
    {
        /*
         * MONEY-TRUTH — a path's money is a sum of CONVERTED rows, and `ObjectivePerformance` does not
         * carry which of them were withheld for want of a rate. Where the platform's own totals say
         * some were, every figure built on that money is unavailable here («—»), never the converted
         * subset presented as the whole.
         */
        $unavailable = array_merge(
            (int) ($totals['spend_withheld_rows'] ?? 0) > 0 ? ['spend', 'cpa', 'cpc', 'cpm', 'cost_per_lpv', 'roas'] : [],
            (int) ($totals['revenue_withheld_rows'] ?? 0) > 0 ? ['revenue', 'roas', 'aov'] : [],
        );

        $blocks = [];
        foreach ((array) ($objectives['paths'] ?? []) as $path) {
            if ((float) ($path['spend'] ?? 0) <= 0 && (float) ($path['impressions'] ?? 0) <= 0) {
                continue;
            }
            $metrics = [];
            foreach ((array) ($path['headline_metrics'] ?? []) as $key) {
                if (array_key_exists($key, $path)) {
                    $metrics[$key] = in_array($key, $unavailable, true) ? null : $path[$key];
                }
            }
            $blocks[] = [
                'path' => $path['path'],
                'label_ar' => $path['label_ar'],
                'label_en' => $path['label_en'],
                'metrics' => $metrics,
            ];
        }

        return $blocks;
    }

    /**
     * What «the outcome» is for this report's objective — the figure a platform's share is read on.
     *
     * Revenue for sales, unless the link hides revenue: then results, because a share of hidden
     * revenue discloses its distribution. Reach-type objectives are read on what they buy.
     */
    private function outcomeMetric(ReportObjectiveLens $lens, bool $hideRevenue): string
    {
        return match ($lens->rankingMetric()['key']) {
            'roas' => $hideRevenue ? 'conversions' : 'revenue',
            'cpc' => 'clicks',
            'cpm' => 'impressions',
            default => 'conversions',
        };
    }
}
