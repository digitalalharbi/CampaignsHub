<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\Metrics\Fx\FxConversion;
use App\Domains\Metrics\Fx\FxDisplay;
use App\Domains\Metrics\Services\DataFreshnessService;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Metrics\Spend\SpendLedger;
use App\Domains\Projects\Lifecycle\LiveProjects;
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

        /*
         * PROJECT-LIFECYCLE-001 rule 5 — every metric below folds in LIVE projects only (active + paused, one scope):
         * an archived or deleted project's spend no longer reaches the clients list. Client state is never read (rule 4).
         */
        $projectMap = LiveProjects::constrain(DB::table('projects')
            ->where('tenant_id', $tenantId)
            ->whereIn('client_workspace_id', $clientIds))
            ->pluck('client_workspace_id', 'id'); // [project_id => client_id]
        $projectIds = $projectMap->keys()->all();

        // A-8 — «المشاريع» counts what the Projects list shows (LISTED: draft + active + paused).
        $projectsPerClient = LiveProjects::constrainListed(DB::table('projects')
            ->where('tenant_id', $tenantId)
            ->whereIn('client_workspace_id', $clientIds))
            ->pluck('client_workspace_id', 'id')
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

        /*
         * PROJECT-LIFECYCLE-001 §7, rule 9 — the 30-day spend is THE one aggregation (`SpendLedger`): every account-day converted
         * from its original currency by FxEngine, live projects only. `sum(value)` read the figure converted once at ingest, so a
         * EUR day stored before its rate existed was silently missing here while the budgets page counted it. A part with no
         * rate is excluded and NAMED (currency, account, amount) — it never makes the client's spend smaller in silence, and
         * never zero.
         */
        $reading = $projectIds === [] ? null : app(SpendLedger::class)->read(
            array_map('strval', $projectIds), Carbon::parse($from), null, ReportingCurrency::DEFAULT,
        );

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

        // Fold freshness from project → client; spend is the ledger's, sliced to each client's projects.
        $syncByClient = [];
        $projectsOfClient = [];
        foreach ($projectMap as $pid => $cid) {
            $projectsOfClient[$cid][] = (string) $pid;
            if (isset($freshByProject[$pid]) && $freshByProject[$pid]) {
                $syncByClient[$cid] = max($syncByClient[$cid] ?? '', (string) $freshByProject[$pid]);
            }
        }

        $out = [];
        foreach ($clientIds as $cid) {
            $currencies = array_keys($currenciesByClient[$cid] ?? []);
            $mine = $reading === null || ! isset($projectsOfClient[$cid]) ? null : SpendLedger::slice($reading, $projectsOfClient[$cid]);
            $hasSpend = $mine !== null && ($mine['by_project'] !== [] || $mine['excluded'] !== []);
            $out[$cid] = [
                'projects' => (int) ($projectsPerClient[$cid] ?? 0),
                'active_campaigns' => (int) ($activeCampaigns[$cid] ?? 0),
                'open_requests' => (int) ($openRequests[$cid] ?? 0),
                'alerts' => (int) ($alerts[$cid] ?? 0),
                'spend' => $hasSpend ? $mine['amount'] : null,
                'spend_fx' => $hasSpend ? $this->spendFx($tenantId, $mine) : null,
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
     * The client's 30-day spend in the reader's display currency, from the ledger reading (USD basis) through FxEngine.
     *
     * PROJECT-LIFECYCLE-001 §7 supersedes FX-APPLY-001's «a withheld part withholds the whole figure»: the converted part IS the
     * total and the excluded part is named beside it («سعر صرف غير متوفر» + currency + account + amount, «مستبعد من الإجمالي»).
     * `converted_amount` is null only when nothing at all could be converted.
     *
     * @param  array<string, mixed>  $reading  {@see SpendLedger::read()} narrowed to the client
     * @return array<string, mixed>
     */
    private function spendFx(string $tenantId, array $reading): array
    {
        $display = FxDisplay::forRequest();
        $to = $display->currencyFor($tenantId);
        $total = $display->total($reading['amount'] === null ? [] : [['amount' => $reading['amount'], 'currency' => (string) $reading['currency']]], $to);
        $excluded = (array) $reading['excluded'];
        $named = array_values(array_unique(array_filter(array_map(static fn (array $e): ?string => $e['currency'] ?? null, $excluded))));
        $originals = (array) $reading['currencies'];

        return array_merge($total, [
            'converted_amount' => $reading['amount'] === null ? null : $total['converted_amount'],
            // Withheld only when NOTHING converted; a partial total is shown, with its exclusions stated.
            'withheld' => $reading['amount'] === null,
            'partial' => (bool) $reading['partial'],
            'withheld_parts' => count($excluded),
            'withheld_currencies' => $named,
            'excluded' => $excluded,
            'excluded_label' => $reading['excluded_label'],
            'statement' => $reading['statement'],
            'statement_en' => $reading['statement_en'],
            'stale' => $total['stale'] || $reading['stale'] !== [],
            'stale_parts' => $reading['stale'],
            'stale_label' => $reading['stale_label'],
            'pending_label' => $excluded === [] ? null : SpendLedger::EXCLUDED_AR.($named === [] ? '' : ' ('.implode('، ', $named).')'),
            'rates_page' => $excluded === [] ? null : FxConversion::RATES_PAGE,
            'currencies' => $originals,
            'includes_converted' => array_diff($originals, [$to]) !== [],
        ]);
    }
}
