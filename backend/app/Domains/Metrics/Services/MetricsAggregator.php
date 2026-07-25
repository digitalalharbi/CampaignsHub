<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Metrics\Models\DailyMetric;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-side aggregation over daily_metrics for the ACTIVE project (BelongsToProject scope applies).
 * All figures are project-currency normalized. Base metrics are summed with conditional aggregation
 * (one pass, no N+1); derived KPIs (ROAS/CPA/CTR/CPC/CPM) are computed from the sums, never summed.
 */
final class MetricsAggregator
{
    /** Conditional-sum expressions that pivot the tall metric table into wide base columns. */
    private const PIVOT = [
        'impressions' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'impressions'), 0)",
        'clicks' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'clicks'), 0)",
        'conversions' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'conversions'), 0)",
        'spend' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'spend'), 0)",
        'revenue' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'revenue'), 0)",
    ];

    private function base(Carbon $from, Carbon $to): Builder
    {
        // Reuse the model's project/tenant scope, then drop to the query builder for aggregation.
        return DailyMetric::query()
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->toBase();
    }

    /** @return array<string, float> base sums + derived KPIs for the period. */
    public function totals(Carbon $from, Carbon $to): array
    {
        $row = $this->base($from, $to)->selectRaw(implode(', ', array_map(
            fn ($expr, $alias) => "{$expr} AS {$alias}",
            self::PIVOT,
            array_keys(self::PIVOT),
        )))->first();

        return $this->withDerived((array) $row);
    }

    /** @return list<array<string, mixed>> one row per provider, ordered by spend desc, with share of spend. */
    public function byProvider(Carbon $from, Carbon $to): array
    {
        $rows = $this->base($from, $to)
            ->select('provider')
            ->selectRaw(implode(', ', array_map(fn ($e, $a) => "{$e} AS {$a}", self::PIVOT, array_keys(self::PIVOT))))
            ->groupBy('provider')
            ->get()
            ->map(fn ($r) => ['provider' => $r->provider] + $this->withDerived((array) $r))
            ->all();

        $totalSpend = array_sum(array_column($rows, 'spend')) ?: 1;
        foreach ($rows as &$r) {
            $r['spend_share'] = round($r['spend'] / $totalSpend, 4);
        }
        usort($rows, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        return $rows;
    }

    /** @return list<array<string, mixed>> one row per unified campaign (id/name/provider) ranked by spend. */
    public function byCampaign(Carbon $from, Carbon $to): array
    {
        $rows = $this->base($from, $to)
            ->leftJoin('unified_campaigns', 'unified_campaigns.id', '=', 'daily_metrics.unified_campaign_id')
            ->select('daily_metrics.unified_campaign_id as campaign_id', 'unified_campaigns.name as campaign_name')
            ->selectRaw('MAX(daily_metrics.provider) AS provider')
            ->selectRaw(implode(', ', array_map(
                fn ($e, $a) => str_replace('value', 'daily_metrics.value', str_replace('metric_key', 'daily_metrics.metric_key', $e))." AS {$a}",
                self::PIVOT,
                array_keys(self::PIVOT),
            )))
            ->groupBy('daily_metrics.unified_campaign_id', 'unified_campaigns.name')
            ->get()
            ->map(fn ($r) => [
                'campaign_id' => $r->campaign_id,
                'campaign_name' => $r->campaign_name,
                'provider' => $r->provider,
            ] + $this->withDerived((array) $r))
            ->all();

        usort($rows, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        return $rows;
    }

    /** @return list<array<string, mixed>> daily rows: date + requested base metrics + derived roas/cpa. */
    public function timeseries(Carbon $from, Carbon $to): array
    {
        return $this->base($from, $to)
            ->select('metric_date')
            ->selectRaw(implode(', ', array_map(fn ($e, $a) => "{$e} AS {$a}", self::PIVOT, array_keys(self::PIVOT))))
            ->groupBy('metric_date')
            ->orderBy('metric_date')
            ->get()
            ->map(fn ($r) => ['date' => Carbon::parse($r->metric_date)->toDateString()] + $this->withDerived((array) $r))
            ->all();
    }

    /** Conversion funnel with per-step transition rate, drop-off and cost per stage. */
    public function funnel(Carbon $from, Carbon $to): array
    {
        $stages = ['impressions', 'clicks', 'landing_page_views', 'add_to_cart', 'checkout', 'conversions'];
        $labels = [
            'impressions' => 'Impressions', 'clicks' => 'Clicks', 'landing_page_views' => 'Landing Page View',
            'add_to_cart' => 'Add to Cart', 'checkout' => 'Checkout', 'conversions' => 'Purchase',
        ];
        $selects = array_map(fn ($s) => "COALESCE(SUM(value) FILTER (WHERE metric_key = '{$s}'), 0) AS {$s}", $stages);
        $selects[] = "COALESCE(SUM(value) FILTER (WHERE metric_key = 'spend'), 0) AS spend";
        $row = (array) $this->base($from, $to)->selectRaw(implode(', ', $selects))->first();

        $spend = (float) ($row['spend'] ?? 0);
        $out = [];
        $prev = null;
        foreach ($stages as $s) {
            $count = (float) ($row[$s] ?? 0);
            $out[] = [
                'stage' => $s,
                'label' => $labels[$s],
                'count' => round($count),
                'step_rate' => $prev !== null && $prev > 0 ? round($count / $prev, 4) : null,
                'drop_off' => $prev !== null && $prev > 0 ? round(1 - $count / $prev, 4) : null,
                'cost_per' => $count > 0 ? round($spend / $count, 2) : null,
            ];
            $prev = $count;
        }

        return $out;
    }

    /** Planned vs spent budget with pacing (over/under) and a linear end-of-period projection. */
    public function budgetPacing(Carbon $from, Carbon $to, Carbon $today): array
    {
        $spentByCampaign = $this->base($from, $to)
            ->select('unified_campaign_id')
            ->selectRaw("COALESCE(SUM(value) FILTER (WHERE metric_key = 'spend'), 0) AS spent")
            ->groupBy('unified_campaign_id')
            ->pluck('spent', 'unified_campaign_id');

        $periodDays = max(1, $from->diffInDays($to) + 1);
        $elapsedDays = max(1, $from->diffInDays($today->min($to)) + 1);
        $elapsedFraction = min(1.0, $elapsedDays / $periodDays);

        // Metrics not linked to a unified campaign group under a null key — exclude it so we never
        // pass an empty string to a uuid column.
        $campaignIds = $spentByCampaign->keys()->filter(fn ($k) => $k !== null && $k !== '')->values();
        $campaigns = $campaignIds->isEmpty()
            ? collect()
            : DB::table('unified_campaigns')
                ->whereIn('id', $campaignIds->all())
                ->get(['id', 'name', 'total_budget', 'budget_currency', 'status']);

        $rows = [];
        foreach ($campaigns as $c) {
            $budget = (float) ($c->total_budget ?? 0);
            $spent = (float) ($spentByCampaign[$c->id] ?? 0);
            $expected = $budget * $elapsedFraction;
            $projected = $elapsedFraction > 0 ? $spent / $elapsedFraction : $spent;
            $rows[] = [
                'campaign_id' => $c->id,
                'campaign_name' => $c->name,
                'status' => $c->status,
                'budget' => round($budget, 2),
                'spent' => round($spent, 2),
                'remaining' => round($budget - $spent, 2),
                'consumed_pct' => $budget > 0 ? round($spent / $budget, 4) : null,
                'pace' => $expected > 0 ? round($spent / $expected, 3) : null, // >1 over-pacing, <1 under
                'projected_spend' => round($projected, 2),
            ];
        }
        usort($rows, fn ($a, $b) => ($b['pace'] ?? 0) <=> ($a['pace'] ?? 0));

        return $rows;
    }

    /** Adds ROAS/CPA/CTR/CPC/CPM to a row of base sums. */
    private function withDerived(array $row): array
    {
        $impr = (float) ($row['impressions'] ?? 0);
        $clicks = (float) ($row['clicks'] ?? 0);
        $conv = (float) ($row['conversions'] ?? 0);
        $spend = (float) ($row['spend'] ?? 0);
        $revenue = (float) ($row['revenue'] ?? 0);

        return [
            'impressions' => round($impr, 2),
            'clicks' => round($clicks, 2),
            'conversions' => round($conv, 2),
            'spend' => round($spend, 2),
            'revenue' => round($revenue, 2),
            'roas' => $spend > 0 ? round($revenue / $spend, 3) : null,
            'cpa' => $conv > 0 ? round($spend / $conv, 2) : null,
            'ctr' => $impr > 0 ? round($clicks / $impr, 5) : null,
            'cpc' => $clicks > 0 ? round($spend / $clicks, 3) : null,
            'cpm' => $impr > 0 ? round($spend / $impr * 1000, 2) : null,
        ];
    }
}
