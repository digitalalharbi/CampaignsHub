<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;

/**
 * CP-DASH-001 — the client's portfolio, on the canonical numbers.
 *
 * ## No second pipeline
 *
 * Every figure comes from {@see ClientAnalyticsService}, which is `MetricsAggregator::forProjects` — the
 * engine the agency's own client page, the reports and the dashboard already read. This class only
 * decides what a CLIENT may be told, according to {@see PortalVisibility}, and shapes it for a portfolio
 * rather than a single campaign: summaries and counts, never a wall of campaign cards.
 *
 * ## Money is opt-in and whole
 *
 * Spend, revenue, ROAS and cost per result are absent unless the agency switched `spend` / `revenue` on,
 * and absent again when the underlying total has withheld rows — a client reading part of the spend as
 * the spend is worse than reading none.
 */
final class PortalOverview
{
    public function __construct(private readonly ClientAnalyticsService $analytics) {}

    /** @return array<string, mixed> */
    /**
     * @param  list<string>|null  $onlyProjectIds  a project-restricted user's ceiling; null = the whole space
     * @return array<string, mixed>
     */
    public function for(ClientWorkspace $workspace, Carbon $from, Carbon $to, ?array $onlyProjectIds = null): array
    {
        $v = PortalVisibility::visibilityFor($workspace);
        $data = $this->analytics->forClient($workspace, $from, $to, $onlyProjectIds);

        $totals = (array) ($data['totals'] ?? $data['counts'] ?? []);
        $spendWhole = $v['spend'] && ((int) ($totals['spend_withheld_rows'] ?? 0)) === 0 && ($data['money_blended'] ?? false);
        $revenueWhole = $v['revenue'] && ((int) ($totals['revenue_withheld_rows'] ?? 0)) === 0 && ($data['money_blended'] ?? false);

        $kpis = [
            'impressions' => $totals['impressions'] ?? null,
            'clicks' => $totals['clicks'] ?? null,
            'conversions' => $totals['conversions'] ?? null,
            'ctr' => $totals['ctr'] ?? null,
        ];
        if ($v['spend']) {
            $kpis['spend'] = $spendWhole ? ($totals['spend'] ?? null) : null;
            $kpis['cpa'] = $spendWhole ? ($totals['cpa'] ?? null) : null;
        }
        if ($v['revenue']) {
            $kpis['revenue'] = $revenueWhole ? ($totals['revenue'] ?? null) : null;
        }
        if ($v['spend'] && $v['revenue']) {
            // ROAS needs BOTH whole figures; one withheld side withholds the ratio too.
            $kpis['roas'] = ($spendWhole && $revenueWhole) ? ($totals['roas'] ?? null) : null;
        }

        $platforms = array_map(function (array $p) use ($spendWhole): array {
            $row = [
                'provider' => $p['provider'] ?? null,
                'impressions' => $p['impressions'] ?? null,
                'clicks' => $p['clicks'] ?? null,
                'conversions' => $p['conversions'] ?? null,
            ];
            if ($spendWhole && ((int) ($p['spend_withheld_rows'] ?? 0)) === 0) {
                $row['spend'] = $p['spend'] ?? null;
            }

            return $row;
        }, array_values((array) ($data['platforms'] ?? [])));

        $projects = Project::query()->where('client_workspace_id', $workspace->getKey())
            ->when($onlyProjectIds !== null, fn ($q) => $q->whereIn('id', $onlyProjectIds))
            ->orderBy('name')->get(['id', 'name', 'status']);
        $spendByProject = [];
        foreach ((array) ($data['projects'] ?? []) as $row) {
            $spendByProject[(string) $row['project_id']] = $row['spend'];
        }

        $campaigns = UnifiedCampaign::query()->where('client_workspace_id', $workspace->getKey())
            ->when($onlyProjectIds !== null, fn ($q) => $q->whereIn('project_id', $onlyProjectIds));
        $byStatus = (clone $campaigns)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();

        return [
            'space' => ['slug' => (string) $workspace->slug, 'name' => (string) $workspace->name, 'mode' => (string) $workspace->mode],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => (int) $from->diffInDays($to) + 1],
            'currency' => ($v['spend'] || $v['revenue']) ? ReportingCurrency::DEFAULT : null,
            'freshness' => ['state' => $data['freshness']['state'] ?? null, 'last_sync_at' => $data['freshness']['last_sync_at'] ?? null],
            'kpis' => $kpis,
            'platforms' => $platforms,
            'projects' => $v['projects'] ? $projects->map(fn (Project $p): array => array_filter([
                'id' => (string) $p->getKey(),
                'name' => $p->name,
                'status' => $p->status,
                'spend' => $spendWhole ? ($spendByProject[(string) $p->getKey()] ?? null) : null,
            ], static fn ($x, $k): bool => $k !== 'spend' || $x !== null, ARRAY_FILTER_USE_BOTH))->values()->all() : [],
            'campaigns' => $v['campaigns'] ? [
                'total' => array_sum($byStatus),
                'by_status' => $byStatus,
            ] : null,
        ];
    }
}
