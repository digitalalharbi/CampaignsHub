<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
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

        /*
         * CP-PARITY-001 — each figure's movement against the window before it, the pill the advertiser's cards carry.
         *
         * The comparison is the analytics service's own (`delta`: (now − before) / |before|, null where before was nothing
         * or either side is not a number) — never recomputed here. A key is offered only where its FIGURE is offered, under
         * the same whole-money rule: a withheld spend has no movement any more than it has a figure. The whole map is
         * absent when the service could not compare at all (a mixed-currency space sends `delta` null), so the client's
         * page draws no pill rather than one from figures it would have to invent.
         */
        $movement = self::movementFor($kpis, is_array($data['delta'] ?? null) ? $data['delta'] : null);

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
            'movement' => $movement,
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
            'attention' => $this->attention($workspace, $v, $onlyProjectIds),
            'trend' => $this->trend((array) ($data['timeseries'] ?? [])),
        ];
    }

    /**
     * One movement per figure OFFERED, from the service's own comparison; null where the figure is withheld, where the
     * service had no previous value, or (the whole map) where it could not compare at all.
     *
     * @param  array<string, mixed>  $kpis
     * @param  array<string, mixed>|null  $delta
     * @return array<string, float|null>|null
     */
    public static function movementFor(array $kpis, ?array $delta): ?array
    {
        if ($delta === null) {
            return null;
        }
        $movement = [];
        foreach (array_keys($kpis) as $key) {
            $movement[$key] = $kpis[$key] === null ? null : (is_numeric($delta[$key] ?? null) ? (float) $delta[$key] : null);
        }

        return $movement;
    }

    /**
     * CP-DASH-001 — the delivery curve: one point per day that HAS data, never a zero for a day that has none.
     *
     * Only the delivery metrics a client is always allowed (impressions, clicks, conversions). Money never
     * rides in the curve: whether a client may see spend is the agency's decision, and a daily series of it
     * would be a second place to get that decision wrong. A day absent from the series is a gap on the chart,
     * not a drop to zero, and a figure the platform did not report stays null rather than 0.
     *
     * @param  list<array<string,mixed>>  $series
     * @return list<array{date: string, impressions: ?float, clicks: ?float, conversions: ?float}>
     */
    private function trend(array $series): array
    {
        $num = static fn (mixed $v): ?float => is_numeric($v) ? (float) $v : null;

        return array_values(array_map(static fn (array $p): array => [
            'date' => (string) $p['date'],
            'impressions' => $num($p['impressions'] ?? null),
            'clicks' => $num($p['clicks'] ?? null),
            'conversions' => $num($p['conversions'] ?? null),
        ], array_filter($series, static fn ($p): bool => is_array($p) && isset($p['date']))));
    }

    /**
     * CP-DASH-001 — what is waiting on THIS client, counted from the records that say so.
     *
     * Each count exists only where the agency lets the client see that section, and an invoice count is
     * absent for a user confined to some projects (client-wide paperwork is not theirs). A missing key
     * means «not available to you», never zero.
     *
     * @param  array<string, bool>  $v
     * @param  list<string>|null  $onlyProjectIds
     * @return array<string, int>
     */
    private function attention(ClientWorkspace $workspace, array $v, ?array $onlyProjectIds): array
    {
        $out = [];

        if ($v['requests']) {
            $out['requests_waiting'] = ExternalRequest::query()
                ->where('client_id', $workspace->getKey())
                ->when($onlyProjectIds !== null, fn ($q) => $q->whereIn('project_id', $onlyProjectIds))
                ->whereHas('status', fn ($q) => $q->where('key', 'waiting_client'))
                ->count();
        }

        if ($v['invoices'] && $onlyProjectIds === null) {
            $states = Invoice::query()->where('client_workspace_id', $workspace->getKey())
                ->whereIn('status', ['issued', 'sent', 'partially_paid'])->get()
                ->map(fn (Invoice $i): string => $i->displayStatus())->countBy();

            $out['invoices_overdue'] = (int) ($states['overdue'] ?? 0);
            $out['invoices_due'] = (int) ($states['due'] ?? 0) + (int) ($states['partially_paid'] ?? 0);
        }

        return $out;
    }
}
