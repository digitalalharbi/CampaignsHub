<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Concerns\ProjectScope;
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
        // DASH-010-D: objective-specific base metrics (tall table → new keys, no schema change).
        'reach' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'reach'), 0)",
        'video_views' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'video_views'), 0)",
        'video_completions' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'video_completions'), 0)",
        'landing_page_views' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'landing_page_views'), 0)",
        'leads' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'leads'), 0)",
        'qualified_leads' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'qualified_leads'), 0)",
        'purchases' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'purchases'), 0)",
        'installs' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'installs'), 0)",
        'registrations' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'registrations'), 0)",
        'in_app_events' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'in_app_events'), 0)",
        'engagements' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'engagements'), 0)",
    ];

    /** The funnel's stages. Two of them (`add_to_cart`, `checkout`) are read HERE and nowhere else. */
    private const FUNNEL_STAGES = ['impressions', 'clicks', 'landing_page_views', 'add_to_cart', 'checkout', 'conversions'];

    /**
     * Every `metric_key` this engine reads (NORM-001).
     *
     * The union of the pivot and the funnel, not the pivot alone: `add_to_cart` and `checkout` are
     * stored, are absent from `PIVOT`, and are funnel stages. A caller asking «which of my metrics does
     * nothing read?» against `PIVOT` would be told those two are ignored when both are counted.
     *
     * @return list<string>
     */
    public static function readKeys(): array
    {
        return array_values(array_unique([...array_keys(self::PIVOT), ...self::FUNNEL_STAGES]));
    }

    /** When set, every aggregation is scoped to this single unified campaign (command center). */
    private ?string $campaignId = null;

    /** When set, every aggregation is scoped to this set of unified campaigns (a shared link's ceiling). */
    private ?array $campaignIds = null;

    /** When set, every aggregation is scoped to these project ids (a client spans many projects). */
    private ?array $projectIds = null;

    /** When set, every aggregation is scoped to these ad platforms (dashboard command-center filter). */
    private ?array $providers = null;

    /** When set, every aggregation is scoped to campaigns with these objectives (objective-KPI filter). */
    private ?array $objectives = null;

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
     * Return a copy scoped to a SET of unified campaigns (LIVEREP-001 — a shared link's ceiling).
     *
     * `forCampaign()` takes one id because the command centre looks at one campaign. A shared link is
     * given a chosen handful, and passing an empty set must mean «no campaigns», never «all of them» —
     * the same fail-closed rule `forProjects()` already follows, and for the same reason: this bound is
     * the only thing standing between a client's link and somebody else's numbers.
     *
     * @param  list<string>  $campaignIds
     */
    public function forCampaigns(array $campaignIds): self
    {
        $clone = clone $this;
        $clone->campaignIds = array_values(array_unique(array_filter($campaignIds)));

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

    /**
     * Return a copy scoped to a set of ad platforms (Snapchat/TikTok/Meta/Google Ads/X/LinkedIn). Empty →
     * no platform filter. Backend-supported dashboard platform filter — every KPI/chart/breakdown it
     * produces is limited to these providers (never a React-only filter).
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
     * Return a copy scoped to campaigns with these objectives (DASH-010-D objective filter). Objective lives
     * on unified_campaigns; we scope daily_metrics via the campaign id. Empty → no objective filter.
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
     * When true the ACTIVE-project scope is lifted; the tenant scope always stays (see acrossProjects()).
     */
    private bool $acrossProjects = false;

    /**
     * A copy that is not confined to whichever project the request has open.
     *
     * For callers that already name the entity they mean and are not looking through a project's eyes —
     * the alert evaluator being the case this exists for. It runs from the scheduler over one tenant's
     * rules, and a campaign id already names exactly one project, so the active-project filter can only
     * do harm: evaluated while some other project happened to be open, every rule would quietly match
     * nothing and the run would report zero alerts raised rather than an error.
     *
     * The TENANT scope is untouched and non-negotiable. This lifts one bound, deliberately and by name.
     */
    public function acrossProjects(): self
    {
        $clone = clone $this;
        $clone->acrossProjects = true;

        return $clone;
    }

    private function base(Carbon $from, Carbon $to): Builder
    {
        // Reuse the model's project/tenant scope, then drop to the query builder for aggregation.
        $query = DailyMetric::query()
            ->when($this->acrossProjects, fn ($q) => $q->withoutGlobalScope(ProjectScope::class))
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()]);

        if ($this->campaignId !== null) {
            $query->where('unified_campaign_id', $this->campaignId);
        }

        if ($this->campaignIds !== null) {
            // Empty set → match nothing, never "all" — see forCampaigns(). The never-matching UUID keeps
            // the column type valid on Postgres, exactly as the project bound does.
            $query->whereIn(
                'daily_metrics.unified_campaign_id',
                $this->campaignIds ?: ['00000000-0000-0000-0000-000000000000'],
            );
        }

        if ($this->projectIds !== null) {
            // Empty set → match nothing (a client with no projects has no metrics), never "all".
            // A never-matching UUID keeps the column type valid on Postgres. Column is qualified because
            // byCampaign() left-joins unified_campaigns (which also has a project_id).
            $query->whereIn('daily_metrics.project_id', $this->projectIds ?: ['00000000-0000-0000-0000-000000000000']);
        }

        if ($this->providers !== null) {
            $query->whereIn('daily_metrics.provider', $this->providers);
        }

        if ($this->objectives !== null) {
            $query->whereIn('daily_metrics.unified_campaign_id', function ($sub) {
                $sub->select('id')->from('unified_campaigns')->whereIn('objective', $this->objectives);
            });
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
    /**
     * CAMPAIGN-020: side-by-side comparison of a chosen set of campaigns over one window.
     *
     * Every campaign is measured with the SAME sums and the SAME derived-KPI formulas as the rest of the
     * engine — there is no parallel comparison maths. Each row carries its own objective so the UI can
     * refuse to blend KPIs across different objectives, and the daily series / platform split / top
     * creatives are each fetched in ONE grouped query rather than per campaign.
     *
     * @param  list<string>  $campaignIds
     * @return list<array<string,mixed>>
     */
    public function compare(array $campaignIds, Carbon $from, Carbon $to): array
    {
        $ids = array_values(array_unique(array_filter($campaignIds)));
        if ($ids === []) {
            return [];
        }

        // Identity comes from unified_campaigns so a campaign with NO metrics in the window still appears
        // in the comparison (as zeros) instead of silently vanishing.
        $meta = DB::table('unified_campaigns')->whereIn('id', $ids)
            ->select('id', 'name', 'objective', 'status', 'client_display_name', 'total_budget', 'budget_currency')
            ->get()->keyBy('id');

        $totals = $this->base($from, $to)->whereIn('daily_metrics.unified_campaign_id', $ids)
            ->select('daily_metrics.unified_campaign_id as campaign_id')
            ->selectRaw(implode(', ', array_map(fn ($e, $a) => "{$e} AS {$a}", self::PIVOT, array_keys(self::PIVOT))))
            ->groupBy('daily_metrics.unified_campaign_id')
            ->get()->keyBy('campaign_id');

        $series = $this->base($from, $to)->whereIn('daily_metrics.unified_campaign_id', $ids)
            ->select('daily_metrics.unified_campaign_id as campaign_id', 'metric_date')
            ->selectRaw(implode(', ', array_map(fn ($e, $a) => "{$e} AS {$a}", self::PIVOT, array_keys(self::PIVOT))))
            ->groupBy('daily_metrics.unified_campaign_id', 'metric_date')
            ->orderBy('metric_date')
            ->get()->groupBy('campaign_id');

        $platforms = $this->base($from, $to)->whereIn('daily_metrics.unified_campaign_id', $ids)
            ->select('daily_metrics.unified_campaign_id as campaign_id', 'daily_metrics.provider')
            ->selectRaw("COALESCE(SUM(value) FILTER (WHERE metric_key = 'spend'), 0) AS spend")
            ->selectRaw("COALESCE(SUM(value) FILTER (WHERE metric_key = 'conversions'), 0) AS conversions")
            ->groupBy('daily_metrics.unified_campaign_id', 'daily_metrics.provider')
            ->get()->groupBy('campaign_id');

        $creatives = $this->topCreatives($ids, $from, $to);

        return array_values(array_map(function (string $id) use ($meta, $totals, $series, $platforms, $creatives): array {
            $m = $meta->get($id);

            return [
                'campaign_id' => $id,
                'name' => $m->name ?? '—',
                'objective' => $m->objective ?? null,
                'status' => $m->status ?? null,
                'client_display_name' => $m->client_display_name ?? null,
                'total_budget' => $m->total_budget ?? null,
                'budget_currency' => $m->budget_currency ?? null,
                'totals' => $this->withDerived((array) ($totals->get($id) ?? new \stdClass)),
                'series' => collect($series->get($id) ?? [])
                    ->map(fn ($r) => ['date' => Carbon::parse($r->metric_date)->toDateString()] + $this->withDerived((array) $r))
                    ->values()->all(),
                'platforms' => collect($platforms->get($id) ?? [])
                    ->map(fn ($r) => ['provider' => $r->provider, 'spend' => round((float) $r->spend, 2), 'conversions' => round((float) $r->conversions, 2)])
                    ->sortByDesc('spend')->values()->all(),
                'creatives' => $creatives[$id] ?? [],
            ];
        }, $ids));
    }

    /**
     * Top creatives per campaign by spend, from the creative tables (not daily_metrics). Thumbnails are
     * passed through as stored — never fabricated; the UI shows "preview unavailable" when null.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, list<array<string,mixed>>>
     */
    private function topCreatives(array $campaignIds, Carbon $from, Carbon $to, int $perCampaign = 3): array
    {
        $rows = DB::table('creative_daily_metrics as m')
            ->join('external_creatives as c', 'c.id', '=', 'm.creative_id')
            ->whereIn('m.campaign_id', $campaignIds)
            ->whereBetween('m.metric_date', [$from->toDateString(), $to->toDateString()])
            ->select('m.campaign_id', 'c.id as creative_id', 'c.name', 'c.format', 'c.thumbnail_url', 'c.provider')
            ->selectRaw('SUM(m.spend) AS spend, SUM(m.impressions) AS impressions, SUM(m.clicks) AS clicks, SUM(m.conversions) AS conversions')
            ->groupBy('m.campaign_id', 'c.id', 'c.name', 'c.format', 'c.thumbnail_url', 'c.provider')
            ->orderByDesc('spend')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $bucket = &$out[$r->campaign_id];
            if (count($bucket ?? []) >= $perCampaign) {
                continue;
            }
            $spend = (float) $r->spend;
            $conv = (float) $r->conversions;
            $impr = (float) $r->impressions;
            $bucket[] = [
                'creative_id' => $r->creative_id,
                'name' => $r->name,
                'format' => $r->format,
                'provider' => $r->provider,
                'thumbnail_url' => $r->thumbnail_url,
                'spend' => round($spend, 2),
                'impressions' => round($impr, 2),
                'clicks' => round((float) $r->clicks, 2),
                'conversions' => round($conv, 2),
                'cpa' => $conv > 0 ? round($spend / $conv, 2) : null,
                'cpm' => $impr > 0 ? round($spend / $impr * 1000, 2) : null,
            ];
            unset($bucket);
        }

        return $out;
    }

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
        $stages = self::FUNNEL_STAGES;
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
        $reach = (float) ($row['reach'] ?? 0);
        $videoViews = (float) ($row['video_views'] ?? 0);
        $videoCompletions = (float) ($row['video_completions'] ?? 0);
        $lpv = (float) ($row['landing_page_views'] ?? 0);
        $leads = (float) ($row['leads'] ?? 0);
        $qualifiedLeads = (float) ($row['qualified_leads'] ?? 0);
        $purchases = (float) ($row['purchases'] ?? 0);
        $installs = (float) ($row['installs'] ?? 0);
        $registrations = (float) ($row['registrations'] ?? 0);
        $inAppEvents = (float) ($row['in_app_events'] ?? 0);
        $engagements = (float) ($row['engagements'] ?? 0);

        return [
            // base sums
            'impressions' => round($impr, 2),
            'clicks' => round($clicks, 2),
            'conversions' => round($conv, 2),
            'spend' => round($spend, 2),
            'revenue' => round($revenue, 2),
            'reach' => round($reach, 2),
            'video_views' => round($videoViews, 2),
            'video_completions' => round($videoCompletions, 2),
            'landing_page_views' => round($lpv, 2),
            'leads' => round($leads, 2),
            'qualified_leads' => round($qualifiedLeads, 2),
            'purchases' => round($purchases, 2),
            'installs' => round($installs, 2),
            'registrations' => round($registrations, 2),
            'in_app_events' => round($inAppEvents, 2),
            'engagements' => round($engagements, 2),
            // derived KPIs (computed from the sums, never summed)
            'roas' => $spend > 0 ? round($revenue / $spend, 3) : null,
            'cpa' => $conv > 0 ? round($spend / $conv, 2) : null,
            'ctr' => $impr > 0 ? round($clicks / $impr, 5) : null,
            'cpc' => $clicks > 0 ? round($spend / $clicks, 3) : null,
            'cpm' => $impr > 0 ? round($spend / $impr * 1000, 2) : null,
            'frequency' => $reach > 0 ? round($impr / $reach, 2) : null,
            'cpl' => $leads > 0 ? round($spend / $leads, 2) : null,
            'cpi' => $installs > 0 ? round($spend / $installs, 2) : null,
            'cpe' => $engagements > 0 ? round($spend / $engagements, 3) : null,
            'aov' => $purchases > 0 ? round($revenue / $purchases, 2) : null,
            'conversion_rate' => $clicks > 0 ? round($conv / $clicks, 5) : null,
            'engagement_rate' => $impr > 0 ? round($engagements / $impr, 5) : null,
            'video_completion_rate' => $videoViews > 0 ? round($videoCompletions / $videoViews, 5) : null,
        ];
    }
}
