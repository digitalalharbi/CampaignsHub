<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Services\DataFreshnessService;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Client-only analytics built on the EXISTING metrics layer (MetricsAggregator::forProjects) — no new KPI
 * math. Guardrails enforced here, not in React:
 *   - never blend money across currencies without a documented conversion (mixed → money KPIs suppressed,
 *     per-project money shown instead);
 *   - never sum reach across platforms as unique reach (aggregator has no such sum);
 *   - ROAS is only the headline for sales/leads objectives;
 *   - partial / stale / sync-failed states are surfaced from real sync runs + metric freshness.
 * Every query is scoped to this client's own projects (tenant scope still fails closed on top).
 */
final class ClientAnalyticsService
{
    /** Objectives for which ROAS is a meaningful headline KPI. */
    private const REVENUE_OBJECTIVES = ['sales', 'leads', 'app_installs'];

    public function __construct(
        private readonly MetricsAggregator $metrics,
        private readonly DataFreshnessService $freshnessService,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array<string,mixed> */
    public function forClient(ClientWorkspace $client, Carbon $from, Carbon $to): array
    {
        $tenantId = (string) $this->tenant->tenantId();
        $projectIds = DB::table('projects')->where('tenant_id', $tenantId)
            ->where('client_workspace_id', $client->id)->whereNull('deleted_at')
            ->pluck('name', 'id'); // [id => name]
        $ids = $projectIds->keys()->map(fn ($v) => (string) $v)->all();

        $currencies = $ids === [] ? [] : DB::table('daily_metrics')
            ->where('tenant_id', $tenantId)->whereIn('project_id', $ids)
            ->whereNotNull('project_currency')->distinct()->pluck('project_currency')->all();
        $currencyMode = count($currencies) > 1 ? 'mixed' : (count($currencies) === 1 ? 'single' : 'none');
        $currency = count($currencies) === 1 ? $currencies[0] : null;

        $objectiveMix = $this->objectiveMix($tenantId, (string) $client->id);
        $roasPrimary = $this->roasIsPrimary($objectiveMix);

        $agg = $this->metrics->forProjects($ids);
        $len = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($len - 1);

        $base = [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'source_of_truth' => 'daily_metrics',
            'currency_mode' => $currencyMode,
            'currency' => $currency,
            'objective_mix' => $objectiveMix,
            'roas_is_primary' => $roasPrimary,
            'projects' => $this->projectSpend($tenantId, $ids, $projectIds, $from, $to),
            'freshness' => $this->freshness($tenantId, $ids, $from, $to),
            'attribution' => ['windows' => $this->attributionWindows($tenantId, $ids)],
        ];

        // Counts (impressions/clicks/conversions) are currency-agnostic and safe to aggregate either way.
        if ($currencyMode === 'mixed') {
            // Do NOT emit blended money KPIs — only per-project money (each in its own currency) + counts.
            $counts = $agg->totals($from, $to);

            return $base + [
                'money_blended' => false,
                'counts' => ['impressions' => $counts['impressions'] ?? 0, 'clicks' => $counts['clicks'] ?? 0, 'conversions' => $counts['conversions'] ?? 0],
                'totals' => null,
                'previous' => null,
                'delta' => null,
                'platforms' => [],
                'timeseries' => [],
                'best_campaign' => null,
                'worst_campaign' => null,
            ];
        }

        $totals = $agg->totals($from, $to);
        $previous = $agg->totals($prevFrom, $prevTo);
        $campaigns = $agg->byCampaign($from, $to);

        return $base + [
            'money_blended' => true,
            'totals' => $totals,
            'previous' => $previous,
            'delta' => $this->delta($totals, $previous),
            'platforms' => $agg->byProvider($from, $to),
            'timeseries' => $agg->timeseries($from, $to),
            'best_campaign' => $this->pickCampaign($campaigns, $roasPrimary, true),
            'worst_campaign' => $this->pickCampaign($campaigns, $roasPrimary, false),
        ];
    }

    /** @return list<array{objective:string,count:int}> */
    private function objectiveMix(string $tenantId, string $clientId): array
    {
        return DB::table('unified_campaigns')
            ->where('tenant_id', $tenantId)->where('client_workspace_id', $clientId)->whereNull('deleted_at')
            ->groupBy('objective')->select('objective', DB::raw('count(*) as count'))
            ->get()->map(fn ($r) => ['objective' => (string) $r->objective, 'count' => (int) $r->count])->all();
    }

    /** @param  list<array{objective:string,count:int}>  $mix */
    private function roasIsPrimary(array $mix): bool
    {
        $total = array_sum(array_column($mix, 'count'));
        if ($total === 0) {
            return false;
        }
        $revenue = 0;
        foreach ($mix as $m) {
            if (in_array($m['objective'], self::REVENUE_OBJECTIVES, true)) {
                $revenue += $m['count'];
            }
        }

        return $revenue / $total >= 0.5; // ROAS headline only when revenue objectives dominate
    }

    /**
     * @param  list<string>  $ids
     * @param  Collection<string,string>  $names
     * @return list<array<string,mixed>>
     */
    private function projectSpend(string $tenantId, array $ids, $names, Carbon $from, Carbon $to): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('daily_metrics')
            ->where('tenant_id', $tenantId)->whereIn('project_id', $ids)
            ->where('metric_key', 'spend')->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('project_id', 'project_currency')
            ->select('project_id', 'project_currency', DB::raw('sum(value) as spend'))
            ->get();

        return $rows->map(fn ($r) => [
            'project_id' => $r->project_id,
            'name' => $names[$r->project_id] ?? '—',
            'spend' => round((float) $r->spend, 2),
            'currency' => $r->project_currency,
        ])->all();
    }

    /**
     * Freshness comes from {@see DataFreshnessService} (UNIFIED-001), not from a query of its own.
     *
     * This method used to compute it here, over `daily_metrics` alone. That made a client whose store
     * had gone a week without a sweep read «محدَّث» on the strength of its ad platforms — while revenue,
     * AOV and ROAS on the very same header came off that unswept store. The service counts every source
     * feeding the client's projects, so the badge covers the figures beside it.
     *
     * @param  list<string>  $ids
     * @return array<string,mixed>
     */
    private function freshness(string $tenantId, array $ids, Carbon $from, Carbon $to): array
    {
        $state = $this->freshnessService->state($tenantId, $ids, $from, $to);

        return [
            'state' => $state['state'],
            'last_sync_at' => $state['last_sync_at'],
            'missing_days' => $state['missing_days'],
            'sync_failed' => $state['sync_failed'],
            'sources' => $state['sources'],
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function attributionWindows(string $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('daily_metrics')->where('tenant_id', $tenantId)->whereIn('project_id', $ids)
            ->distinct()->pluck('attribution_window')->filter()->values()->all();
    }

    /**
     * @param  array<string,float>  $totals
     * @param  array<string,float>  $previous
     * @return array<string,float|null>
     */
    private function delta(array $totals, array $previous): array
    {
        $out = [];
        foreach ($totals as $k => $v) {
            $p = $previous[$k] ?? null;
            $out[$k] = is_numeric($v) && is_numeric($p) && $p != 0 ? round(($v - $p) / abs($p), 4) : null;
        }

        return $out;
    }

    /**
     * Best/worst campaign by the objective-appropriate KPI (ROAS when revenue-primary, else CPA), among
     * campaigns that actually spent. Returns null when there is nothing to rank.
     *
     * @param  list<array<string,mixed>>  $campaigns
     * @return array<string,mixed>|null
     */
    private function pickCampaign(array $campaigns, bool $roasPrimary, bool $best): ?array
    {
        $spending = array_values(array_filter($campaigns, fn ($c) => ($c['spend'] ?? 0) > 0));
        if ($spending === []) {
            return null;
        }
        $key = $roasPrimary ? 'roas' : 'cpa';
        $ranked = array_filter($spending, fn ($c) => is_numeric($c[$key] ?? null));
        if ($ranked === []) {
            return null;
        }
        usort($ranked, fn ($a, $b) => $a[$key] <=> $b[$key]);
        // Higher ROAS = better; lower CPA = better.
        $bestIsLast = $roasPrimary;

        return $best ? ($bestIsLast ? end($ranked) : $ranked[0]) : ($bestIsLast ? $ranked[0] : end($ranked));
    }
}
