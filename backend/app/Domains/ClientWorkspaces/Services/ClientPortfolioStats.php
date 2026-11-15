<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Fx\FxConversion;
use App\Domains\Metrics\Fx\FxDisplay;
use App\Domains\Metrics\Services\DataFreshnessService;
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
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DataFreshnessService $freshness,
    ) {}

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

        /*
         * A-8 — «المشاريع» counts the projects the Projects list shows. That list hides `archived` ones by default
         * (ProjectController::index) and the integrations hub only names projects holding an active link, so a client
         * with one live and one archived project read «2» here and one there. Spend and freshness below still fold in
         * archived projects: their history is real and is not deleted or hidden by this count.
         */
        $archivedIds = DB::table('projects')
            ->where('tenant_id', $tenantId)
            ->whereIn('client_workspace_id', $clientIds)
            ->whereNull('deleted_at')
            ->where('status', 'archived')
            ->pluck('id')
            ->map(strval(...))
            ->all();
        $projectsPerClient = $projectMap
            ->reject(fn ($clientId, $projectId) => in_array((string) $projectId, $archivedIds, true))
            ->groupBy(fn ($clientId) => $clientId)->map->count();

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
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->where('metric_key', 'spend')->where('metric_date', '>=', $from)
            ->groupBy('project_id')->select('project_id', DB::raw('sum(value) as v'))
            ->pluck('v', 'project_id');

        /*
         * FX-APPLY-001 — `sum(value)` skips a row with no rate for its date (value NULL), so a EUR account with an unconverted day
         * read as a SMALLER spend, not as a withheld one. The same window says, per project, how many rows were withheld and which
         * currencies the converted figure came from, so a total with a withheld part is «—» and a mixed one says «يشمل عملات محوّلة».
         */
        $fxByProject = $projectIds === [] ? collect() : DB::table('daily_metrics')
            ->where('tenant_id', $tenantId)->whereIn('project_id', $projectIds)
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->where('metric_key', 'spend')->where('metric_date', '>=', $from)->whereNotNull('original_amount')
            ->groupBy('project_id')
            ->select('project_id')
            ->selectRaw('COUNT(*) FILTER (WHERE value IS NULL) AS withheld')
            ->selectRaw("STRING_AGG(DISTINCT UPPER(COALESCE(original_currency, '?')), ',') FILTER (WHERE value IS NULL) AS withheld_currencies")
            ->selectRaw('STRING_AGG(DISTINCT UPPER(original_currency), \',\') FILTER (WHERE value IS NOT NULL) AS currencies')
            ->get()->keyBy('project_id');

        /*
         * «آخر مزامنة» comes from the one freshness service (UNIFIED-001), in ONE bulk call for the
         * whole page — not `max(data_freshness_at)` read here. The old query saw ad platforms only, so
         * this column and the client's own analytics header could name different moments for the same
         * client on the same afternoon, and a store's sweep never moved it at all.
         */
        $freshByProject = $projectIds === []
            ? []
            : $this->freshness->lastSyncByProject($tenantId, array_map('strval', $projectIds));

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
        $fxByClient = [];
        foreach ($projectMap as $pid => $cid) {
            if (isset($spendByProject[$pid])) {
                $spendByClient[$cid] = ($spendByClient[$cid] ?? 0) + (float) $spendByProject[$pid];
            }
            $fxRow = $fxByProject->get($pid);
            if ($fxRow !== null) {
                $fxByClient[$cid]['withheld'] = ($fxByClient[$cid]['withheld'] ?? 0) + (int) $fxRow->withheld;
                foreach (array_filter(explode(',', (string) $fxRow->withheld_currencies)) as $c) {
                    $fxByClient[$cid]['withheld_currencies'][$c] = true;
                }
                foreach (array_filter(explode(',', (string) $fxRow->currencies)) as $c) {
                    $fxByClient[$cid]['currencies'][$c] = true;
                }
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
                'spend_fx' => $this->spendFx($tenantId, $spendByClient[$cid] ?? null, $currencies, $fxByClient[$cid] ?? []),
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

    /**
     * FX-APPLY-001 — the client's 30-day spend as the reader's display currency, through FxEngine.
     *
     * `spend` (above, untouched) is the reporting-basis sum. A withheld part withholds the figure (`converted_amount: null`) —
     * never the smaller sum of what did convert, never zero.
     *
     * @param  list<string>  $basis  the reporting currencies the stored rows were normalised into
     * @param  array{withheld?: int, withheld_currencies?: array<string, true>, currencies?: array<string, true>}  $fx
     * @return array<string, mixed>|null
     */
    private function spendFx(string $tenantId, ?float $spend, array $basis, array $fx): ?array
    {
        if ($spend === null && ($fx['withheld'] ?? 0) === 0) {
            return null;
        }
        $display = FxDisplay::forRequest();
        $to = $display->currencyFor($tenantId);
        $withheld = array_keys($fx['withheld_currencies'] ?? []);
        // One reporting basis → one part converted to the display currency; two bases (legacy SAR beside USD rows) cannot be
        // added without knowing which row is which, so the part has no unit and the total is withheld.
        $parts = $spend === null || $basis === [] ? [] : [['amount' => $spend, 'currency' => count($basis) === 1 ? $basis[0] : null]];
        $total = $display->total($parts, $to);
        $originals = array_keys($fx['currencies'] ?? []);
        sort($originals);
        if (($fx['withheld'] ?? 0) > 0) {
            $named = array_values(array_filter($withheld, static fn (string $c): bool => $c !== '?'));
            $total = array_merge($total, [
                'converted_amount' => null, 'withheld' => true, 'withheld_parts' => (int) $fx['withheld'], 'withheld_currencies' => $named,
                'pending_label' => $named === [] ? 'بانتظار سعر صرف' : 'بانتظار سعر صرف ('.implode('، ', $named).')',
                'rates_page' => FxConversion::RATES_PAGE,
            ]);
        }

        return array_merge($total, [
            'currencies' => $originals,
            'includes_converted' => array_diff($originals, [$to]) !== [],
        ]);
    }
}
