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

    /** When set, every aggregation is scoped to this single unified campaign (command center). */
    private ?string $campaignId = null;

    /** When set, every aggregation is scoped to these project ids (a client spans many projects). */
    private ?array $projectIds = null;

    /**
     * Return a campaign-scoped copy of the aggregator — every subsequent totals/byProvider/timeseries/
     * funnel/budget call is filtered to this campaign's metrics (on top of the project/tenant scope).
     * A clone keeps the shared singleton stateless.
     */
    public function forCampaign(?string $campaignId): self
    {
        $clone = clone $this;
        $clone->campaignId = $campaignId;

        return $clone;
    }

    /**
     * Return a copy scoped to a set of project ids — used by the Client Command Center to aggregate a
     * single client's metrics across ALL its projects, while the tenant scope still fails closed.
     * Reuses every derived-KPI formula unchanged (no parallel metrics engine).
     *
     * @param  list<string>  $projectIds
     */
    public function forProjects(array $projectIds): self
    {
        $clone = clone $this;
        $clone->projectIds = $projectIds;

        return $clone;
    }

    private function base(Carbon $from, Carbon $to): Builder
    {
        // Reuse the model's project/tenant scope, then drop to the query builder for aggregation.
        $query = DailyMetric::query()
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()]);

        if ($this->campaignId !== null) {
            $query->where('unified_campaign_id', $this->campaignId);
        }

        if ($this->projectIds !== null) {
            // Empty set → match nothing (a client with no projects has no metrics), never "all".
            // A never-matching UUID keeps the column type valid on Postgres. Column is qualified because
            // byCampaign() left-joins unified_campaigns (which also has a project_id).
            $query->whereIn('daily_metrics.project_id', $this->projectIds ?: ['00000000-0000-0000-0000-000000000000']);
        }

        return $query->toBase();
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
            ->select('daily_metrics.unified_campaign_id as campaign_id', 'unified_campaigns.name as campaign_name', 'unified_campaigns.client_display_name as client_display_name')
            ->selectRaw('MAX(daily_metrics.provider) AS provider')
            ->selectRaw(implode(', ', array_map(
                fn ($e, $a) => str_replace('value', 'daily_metrics.value', str_replace('metric_key', 'daily_metrics.metric_key', $e))." AS {$a}",
                self::PIVOT,
                array_keys(self::PIVOT),
            )))
            ->groupBy('daily_metrics.unified_campaign_id', 'unified_campaigns.name', 'unified_campaigns.client_display_name')
            ->get()
            ->map(fn ($r) => [
                'campaign_id' => $r->campaign_id,
                'campaign_name' => $r->campaign_name,
                'client_display_name' => $r->client_display_name,
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

    /** @return array<string, list<array<string,mixed>>> daily series per provider (for per-platform charts). */
    public function timeseriesByProvider(Carbon $from, Carbon $to): array
    {
        $rows = $this->base($from, $to)
            ->select('provider', 'metric_date')
            ->selectRaw(implode(', ', array_map(fn ($e, $a) => "{$e} AS {$a}", self::PIVOT, array_keys(self::PIVOT))))
            ->groupBy('provider', 'metric_date')
            ->orderBy('metric_date')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->provider][] = ['date' => Carbon::parse($r->metric_date)->toDateString()] + $this->withDerived((array) $r);
        }

        return $out;
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
