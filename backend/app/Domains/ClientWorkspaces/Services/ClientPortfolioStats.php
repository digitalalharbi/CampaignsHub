<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bulk, CLIENT-SCOPED portfolio metrics for a page of clients — a few grouped queries keyed by client id
 * (never N+1, never agency-wide totals). Every query is explicitly filtered by tenant AND by the client's
 * own projects/entities, so no number ever bleeds across clients.
 */
final class ClientPortfolioStats
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  list<string>  $clientIds
     * @return array<string, array<string,mixed>> clientId => stats
     */
    public function forClients(array $clientIds, int $windowDays = 30): array
    {
        if ($clientIds === []) {
            return [];
        }
        $tenantId = (string) $this->tenant->tenantId();
        $from = Carbon::now()->subDays($windowDays)->toDateString();

        // Map project_id => client_id for this tenant's clients (basis for every metric join).
        $projectMap = DB::table('projects')
            ->where('tenant_id', $tenantId)
            ->whereIn('client_workspace_id', $clientIds)
            ->whereNull('deleted_at')
            ->pluck('client_workspace_id', 'id'); // [project_id => client_id]
        $projectIds = $projectMap->keys()->all();

        $projectsPerClient = $projectMap->groupBy(fn ($clientId) => $clientId)->map->count();

        $activeCampaigns = DB::table('unified_campaigns')
            ->where('tenant_id', $tenantId)->whereIn('client_workspace_id', $clientIds)
            ->where('status', '!=', 'draft')->whereNull('deleted_at')
            ->groupBy('client_workspace_id')->select('client_workspace_id', DB::raw('count(*) as c'))
            ->pluck('c', 'client_workspace_id');

        $openRequests = DB::table('external_requests as r')
            ->join('request_statuses as s', 's.id', '=', 'r.status_id')
            ->where('r.tenant_id', $tenantId)->whereIn('r.client_id', $clientIds)
            ->where('s.is_terminal', false)
            ->groupBy('r.client_id')->select('r.client_id', DB::raw('count(*) as c'))
            ->pluck('c', 'r.client_id');

        $alerts = DB::table('app_notifications')
            ->where('tenant_id', $tenantId)->whereIn('client_workspace_id', $clientIds)
            ->where('status', 'unread')
            ->groupBy('client_workspace_id')->select('client_workspace_id', DB::raw('count(*) as c'))
            ->pluck('c', 'client_workspace_id');

        // Spend + freshness + data sources: group daily_metrics by project, fold up to client.
        $spendByProject = $projectIds === [] ? collect() : DB::table('daily_metrics')
            ->where('tenant_id', $tenantId)->whereIn('project_id', $projectIds)
            ->where('metric_key', 'spend')->where('metric_date', '>=', $from)
            ->groupBy('project_id')->select('project_id', DB::raw('sum(value) as v'))
            ->pluck('v', 'project_id');

        $freshByProject = $projectIds === [] ? collect() : DB::table('daily_metrics')
            ->where('tenant_id', $tenantId)->whereIn('project_id', $projectIds)
            ->groupBy('project_id')->select('project_id', DB::raw('max(data_freshness_at) as f'))
            ->pluck('f', 'project_id');

        $providersByClient = [];
        $currenciesByClient = [];
        if ($projectIds !== []) {
            foreach (DB::table('daily_metrics')
                ->where('tenant_id', $tenantId)->whereIn('project_id', $projectIds)
                ->select('project_id', 'provider', 'project_currency')->distinct()->get() as $r) {
                $cid = $projectMap[$r->project_id] ?? null;
                if ($cid === null) {
                    continue;
                }
                if ($r->provider) {
                    $providersByClient[$cid][$r->provider] = true;
                }
                if ($r->project_currency) {
                    $currenciesByClient[$cid][$r->project_currency] = true;
                }
            }
        }

        $lastReport = $projectIds === [] ? collect() : DB::table('reports')
            ->where('tenant_id', $tenantId)->whereIn('project_id', $projectIds)
            ->whereNotNull('generated_at')
            ->groupBy('project_id')->select('project_id', DB::raw('max(generated_at) as g'))
            ->get();
        $lastReportByClient = [];
        foreach ($lastReport as $r) {
            $cid = $projectMap[$r->project_id] ?? null;
            if ($cid !== null) {
                $lastReportByClient[$cid] = max($lastReportByClient[$cid] ?? '', (string) $r->g);
            }
        }

        // Fold spend + freshness from project → client.
        $spendByClient = [];
        $syncByClient = [];
        foreach ($projectMap as $pid => $cid) {
            if (isset($spendByProject[$pid])) {
                $spendByClient[$cid] = ($spendByClient[$cid] ?? 0) + (float) $spendByProject[$pid];
            }
            if (isset($freshByProject[$pid]) && $freshByProject[$pid]) {
                $syncByClient[$cid] = max($syncByClient[$cid] ?? '', (string) $freshByProject[$pid]);
            }
        }

        $out = [];
        foreach ($clientIds as $cid) {
            $currencies = array_keys($currenciesByClient[$cid] ?? []);
            $out[$cid] = [
                'projects' => (int) ($projectsPerClient[$cid] ?? 0),
                'active_campaigns' => (int) ($activeCampaigns[$cid] ?? 0),
                'open_requests' => (int) ($openRequests[$cid] ?? 0),
                'alerts' => (int) ($alerts[$cid] ?? 0),
                'spend' => isset($spendByClient[$cid]) ? round($spendByClient[$cid], 2) : null,
                'spend_currency_mode' => count($currencies) > 1 ? 'mixed' : (count($currencies) === 1 ? 'single' : 'none'),
                'currency' => count($currencies) === 1 ? $currencies[0] : null,
                'data_sources' => array_keys($providersByClient[$cid] ?? []),
                'last_report_at' => isset($lastReportByClient[$cid]) && $lastReportByClient[$cid] !== ''
                    ? Carbon::parse($lastReportByClient[$cid])->toIso8601String() : null,
                'last_sync_at' => isset($syncByClient[$cid]) && $syncByClient[$cid] !== ''
                    ? Carbon::parse($syncByClient[$cid])->toIso8601String() : null,
            ];
        }

        return $out;
    }
}
