<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — the Production contamination inventory, read-only.
 *
 * ## The question it answers
 *
 * The Owner observed «campaigns/data from accounts other than the exact selected account being mixed
 * into results». A project's selected accounts are its ACTIVE rows in `project_integration_bindings`;
 * every stored row at every grain can be attributed to an ad account — directly where the table carries
 * `external_account_id`, and through its campaign where it does not (ad sets, ads, creatives and the
 * creative metrics carry no account column of their own). This command lists, per project and per
 * grain, the distinct accounts actually present and how many rows each holds, and classifies each
 * account against the project's bindings:
 *
 *   bound            an ACTIVE binding to this project — the rows are legitimate
 *   once_bound       a binding to this project that is now inactive — history the operator deselected
 *   bound_elsewhere  an ACTIVE binding to a DIFFERENT project — the re-binding mechanism, where a
 *                    campaign keeps the project it was first filed under
 *   unbound          no binding anywhere — never selected by anybody
 *   none             the row cannot be attributed to any account (no campaign behind it)
 *
 * «Counts by grain, provider and month» is what a cleanup decision needs; «which mechanism» is what a
 * fix needs. Both are here, and nothing is guessed: a row is only ever classified by a join to the
 * tables the product itself writes.
 *
 * ## What it never does
 *
 * Writes nothing, queues nothing, calls no provider — `ScopeAuditCommandTest` holds a digest of EVERY
 * table across a run. Prints internal ids, provider keys and counts only: never an account name, a
 * provider's own id, a url, a token or a person. A workflow log is readable by anybody with repository
 * access, which is not the audience of an ad account.
 */
final class ScopeAuditCommand extends Command
{
    protected $signature = 'integrations:scope-audit
        {--project= : One project id. Default: every project holding a binding or a stored row}
        {--provider= : Limit to one provider key, e.g. snapchat}
        {--from= : Earliest month for the dated grains, YYYY-MM}
        {--to= : Latest month for the dated grains, YYYY-MM}
        {--lines=60 : Detail lines to print per grain before folding the rest into the totals}';

    protected $description = 'Read-only: per project and grain, which ad accounts the stored rows belong to, against the bound set.';

    private const CLASSES = ['bound', 'once_bound', 'bound_elsewhere', 'unbound', 'none'];

    /** @var array<string, list<string>> account id → project ids with an ACTIVE binding */
    private array $activeOn = [];

    /** @var array<string, list<string>> account id → project ids with an INACTIVE binding */
    private array $inactiveOn = [];

    /** @var array<string, string> account id → provider key */
    private array $providerOf = [];

    /** @var array<string, array<string, array<string, int>>> project → grain → class → rows */
    private array $summary = [];

    public function handle(): int
    {
        $this->loadBindings();

        $projects = $this->projects();

        $this->line('');
        $this->line(str_repeat('=', 78));
        $this->line('  ACCOUNT-SCOPE AUDIT — read-only. No provider was called, nothing was written.');
        $this->line('  ids and counts only: internal uuids, provider keys, months.');
        $this->line(str_repeat('=', 78));

        if ($projects->isEmpty()) {
            $this->warn('  No project holds a binding or a stored row that matches the filter.');

            return self::SUCCESS;
        }

        foreach ($projects as $projectId) {
            $this->reportProject((string) $projectId);
        }

        $this->reportEstate();
        $this->reportSummary();

        return self::SUCCESS;
    }

    // ── bindings ───────────────────────────────────────────────────────────────────────────────────

    private function loadBindings(): void
    {
        $rows = DB::table('project_integration_bindings as b')
            ->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->when($this->providerFilter(), fn ($q, $p) => $q->where('a.provider', $p))
            ->get(['b.project_id', 'b.external_account_id', 'b.is_active', 'a.provider']);

        foreach ($rows as $row) {
            $account = (string) $row->external_account_id;
            $this->providerOf[$account] = (string) $row->provider;

            if ($row->is_active) {
                $this->activeOn[$account][] = (string) $row->project_id;
            } else {
                $this->inactiveOn[$account][] = (string) $row->project_id;
            }
        }
    }

    private function classify(?string $accountId, string $projectId): string
    {
        if ($accountId === null || $accountId === '') {
            return 'none';
        }

        if (in_array($projectId, $this->activeOn[$accountId] ?? [], true)) {
            return 'bound';
        }

        if (($this->activeOn[$accountId] ?? []) !== []) {
            return 'bound_elsewhere';
        }

        if (in_array($projectId, $this->inactiveOn[$accountId] ?? [], true)) {
            return 'once_bound';
        }

        return 'unbound';
    }

    /** @return Collection<int, string> */
    private function projects(): Collection
    {
        $one = $this->stringOption('project');

        if ($one !== null) {
            return collect([$one]);
        }

        $ids = collect();

        $ids = $ids->merge(DB::table('project_integration_bindings')->distinct()->pluck('project_id'));

        foreach (['external_campaigns', 'daily_metrics', 'entity_daily_metrics', 'creative_daily_metrics', 'commerce_orders'] as $table) {
            $ids = $ids->merge(
                DB::table($table)
                    ->when($this->providerFilter(), fn ($q, $p) => $table === 'creative_daily_metrics'
                        ? $q
                        : $q->where('provider', $p))
                    ->distinct()
                    ->pluck('project_id'),
            );
        }

        return $ids->map(static fn ($id): string => (string) $id)->unique()->sort()->values();
    }

    // ── per project ────────────────────────────────────────────────────────────────────────────────

    private function reportProject(string $projectId): void
    {
        $project = DB::table('projects')->where('id', $projectId)->first(['id', 'tenant_id', 'client_workspace_id']);

        $this->line('');
        $this->line(str_repeat('-', 78));
        $this->line(sprintf('  PROJECT %s', $projectId));
        $this->line(sprintf('    tenant %s · client workspace %s',
            $project?->tenant_id ?? '(project row missing)',
            $project?->client_workspace_id ?? '—',
        ));

        $active = array_keys(array_filter($this->activeOn, fn (array $p): bool => in_array($projectId, $p, true)));
        $inactive = array_keys(array_filter($this->inactiveOn, fn (array $p): bool => in_array($projectId, $p, true)));

        $this->line(sprintf('    ACTIVE bindings   : %d', count($active)));
        foreach ($active as $account) {
            $this->line(sprintf('      · %s  [%s]', $account, $this->providerOf[$account] ?? '?'));
        }
        $this->line(sprintf('    inactive bindings : %d', count($inactive)));
        foreach ($inactive as $account) {
            $this->line(sprintf('      · %s  [%s]  (deselected — its rows are still readable)', $account, $this->providerOf[$account] ?? '?'));
        }

        // Structure — attributed directly, or through the campaign the row hangs off.
        $this->grain($projectId, 'external_campaigns', $this->campaignsQuery($projectId));
        $this->grain($projectId, 'external_ad_sets', $this->throughCampaign($projectId, 'external_ad_sets'));
        $this->grain($projectId, 'external_ads', $this->throughCampaign($projectId, 'external_ads'));
        $this->grain($projectId, 'external_creatives', $this->throughCampaign($projectId, 'external_creatives'));

        // Figures — dated, so a cleanup can be bounded by month.
        $this->grain($projectId, 'daily_metrics', $this->dailyMetricsQuery($projectId), dated: true);
        $this->grain($projectId, 'entity_daily_metrics', $this->entityMetricsQuery($projectId), dated: true);
        $this->grain($projectId, 'creative_daily_metrics', $this->creativeMetricsQuery($projectId), dated: true);

        // Runs and the merchant's ledger.
        $this->grain($projectId, 'metric_sync_runs', $this->syncRunsQuery($projectId));
        $this->grain($projectId, 'commerce_orders', $this->commerceQuery($projectId, 'commerce_orders', 'placed_at'), dated: true);
        $this->grain($projectId, 'commerce_products', $this->commerceQuery($projectId, 'commerce_products'));
        $this->grain($projectId, 'commerce_customers', $this->commerceQuery($projectId, 'commerce_customers'));
        $this->grain($projectId, 'commerce_abandoned_carts', $this->commerceQuery($projectId, 'commerce_abandoned_carts', 'abandoned_at'));

        $this->mechanisms($projectId);
    }

    /**
     * Print one grain: every (account, provider[, month]) present in this project, classified.
     */
    private function grain(string $projectId, string $label, Builder $query, bool $dated = false): void
    {
        $rows = $query->get();

        $totals = array_fill_keys(self::CLASSES, 0);
        $lines = [];

        foreach ($rows as $row) {
            $account = $row->account_id === null ? null : (string) $row->account_id;
            $class = $this->classify($account, $projectId);
            $count = (int) $row->rows;
            $totals[$class] += $count;

            $lines[] = sprintf(
                '      %-16s %-36s [%-8s] %s%6d',
                $class,
                $account ?? '(no account)',
                (string) ($row->provider ?? '?'),
                $dated ? sprintf('%-8s', (string) ($row->month ?? '?')) : '',
                $count,
            );
        }

        $this->summary[$projectId][$label] = $totals;

        $all = array_sum($totals);
        $outside = $all - $totals['bound'];

        $this->line('');
        $this->line(sprintf('    %s — %d row(s); %d outside the ACTIVE bound set', $label, $all, $outside));

        if ($all === 0) {
            return;
        }

        $this->line(sprintf(
            '      bound %d · once_bound %d · bound_elsewhere %d · unbound %d · none %d',
            $totals['bound'], $totals['once_bound'], $totals['bound_elsewhere'], $totals['unbound'], $totals['none'],
        ));

        $limit = max(1, (int) $this->option('lines'));
        foreach (array_slice($lines, 0, $limit) as $line) {
            $this->line($line);
        }
        if (count($lines) > $limit) {
            $this->line(sprintf('      … %d more line(s) folded into the totals above', count($lines) - $limit));
        }
    }

    // ── the queries, one per grain ─────────────────────────────────────────────────────────────────

    private function campaignsQuery(string $projectId): Builder
    {
        return DB::table('external_campaigns as c')
            ->where('c.project_id', $projectId)
            ->when($this->providerFilter(), fn ($q, $p) => $q->where('c.provider', $p))
            ->selectRaw('c.external_account_id as account_id, c.provider, COUNT(*) as rows')
            ->groupBy('c.external_account_id', 'c.provider')
            ->orderBy('c.provider')->orderBy('c.external_account_id');
    }

    /** Ad sets, ads and creatives carry no account column; the campaign they hang off does. */
    private function throughCampaign(string $projectId, string $table): Builder
    {
        return DB::table("{$table} as t")
            ->leftJoin('external_campaigns as c', 'c.id', '=', 't.external_campaign_id')
            ->where('t.project_id', $projectId)
            ->when($this->providerFilter(), fn ($q, $p) => $q->where('t.provider', $p))
            ->selectRaw('c.external_account_id as account_id, t.provider, COUNT(*) as rows')
            ->groupBy('c.external_account_id', 't.provider')
            ->orderBy('t.provider')->orderBy('c.external_account_id');
    }

    private function dailyMetricsQuery(string $projectId): Builder
    {
        return DB::table('daily_metrics as m')
            ->where('m.project_id', $projectId)
            ->when($this->providerFilter(), fn ($q, $p) => $q->where('m.provider', $p))
            ->tap(fn ($q) => $this->monthWindow($q, 'm.metric_date'))
            ->selectRaw("m.external_account_id as account_id, m.provider, to_char(m.metric_date, 'YYYY-MM') as month, COUNT(*) as rows")
            ->groupBy('m.external_account_id', 'm.provider', 'month')
            ->orderBy('m.provider')->orderBy('m.external_account_id')->orderBy('month');
    }

    /**
     * `external_account_id` is nullable here; a null is resolved through the campaign so the row is
     * attributed the way the product's own readers would reach it.
     */
    private function entityMetricsQuery(string $projectId): Builder
    {
        return DB::table('entity_daily_metrics as e')
            ->leftJoin('external_campaigns as c', 'c.id', '=', 'e.external_campaign_id')
            ->where('e.project_id', $projectId)
            ->when($this->providerFilter(), fn ($q, $p) => $q->where('e.provider', $p))
            ->tap(fn ($q) => $this->monthWindow($q, 'e.metric_date'))
            ->selectRaw("COALESCE(e.external_account_id, c.external_account_id) as account_id, e.provider, to_char(e.metric_date, 'YYYY-MM') as month, COUNT(*) as rows")
            ->groupBy('account_id', 'e.provider', 'month')
            ->orderBy('e.provider')->orderBy('account_id')->orderBy('month');
    }

    private function creativeMetricsQuery(string $projectId): Builder
    {
        return DB::table('creative_daily_metrics as cm')
            ->join('external_creatives as cr', 'cr.id', '=', 'cm.creative_id')
            ->leftJoin('external_campaigns as c', 'c.id', '=', 'cr.external_campaign_id')
            ->where('cm.project_id', $projectId)
            ->when($this->providerFilter(), fn ($q, $p) => $q->where('cr.provider', $p))
            ->tap(fn ($q) => $this->monthWindow($q, 'cm.metric_date'))
            ->selectRaw("c.external_account_id as account_id, cr.provider, to_char(cm.metric_date, 'YYYY-MM') as month, COUNT(*) as rows")
            ->groupBy('c.external_account_id', 'cr.provider', 'month')
            ->orderBy('cr.provider')->orderBy('c.external_account_id')->orderBy('month');
    }

    private function syncRunsQuery(string $projectId): Builder
    {
        return DB::table('metric_sync_runs as r')
            ->where('r.project_id', $projectId)
            ->when($this->providerFilter(), fn ($q, $p) => $q->where('r.provider', $p))
            ->selectRaw('r.external_account_id as account_id, r.provider, COUNT(*) as rows')
            ->groupBy('r.external_account_id', 'r.provider')
            ->orderBy('r.provider')->orderBy('r.external_account_id');
    }

    private function commerceQuery(string $projectId, string $table, ?string $dateColumn = null): Builder
    {
        $q = DB::table("{$table} as o")
            ->where('o.project_id', $projectId)
            ->when($this->providerFilter(), fn ($q, $p) => $q->where('o.provider', $p));

        if ($dateColumn !== null) {
            return $q->tap(fn ($q) => $this->monthWindow($q, "o.{$dateColumn}"))
                ->selectRaw("o.external_account_id as account_id, o.provider, to_char(o.{$dateColumn}, 'YYYY-MM') as month, COUNT(*) as rows")
                ->groupBy('o.external_account_id', 'o.provider', 'month')
                ->orderBy('o.provider')->orderBy('o.external_account_id')->orderBy('month');
        }

        return $q->selectRaw('o.external_account_id as account_id, o.provider, COUNT(*) as rows')
            ->groupBy('o.external_account_id', 'o.provider')
            ->orderBy('o.provider')->orderBy('o.external_account_id');
    }

    // ── the mechanisms, named ──────────────────────────────────────────────────────────────────────

    private function mechanisms(string $projectId): void
    {
        $this->line('');
        $this->line('    mechanisms');

        // Sandbox rows under a live account — SANDBOX-PROD-001, counted here so the inventory is whole.
        $sandbox = DB::table('external_campaigns')
            ->where('project_id', $projectId)
            ->where('provider', '!=', 'sandbox')
            ->whereRaw("(raw->>'sandbox') = 'true'")
            ->count();
        $this->line(sprintf('      sandbox campaigns filed under a live provider       : %d', $sandbox));

        // A creative referenced by ads of more than one account — the account-blind creative key.
        $shared = DB::table('external_ads as a')
            ->join('external_campaigns as c', 'c.id', '=', 'a.external_campaign_id')
            ->where('a.project_id', $projectId)
            ->whereNotNull('a.creative_id')
            ->groupBy('a.creative_id')
            ->havingRaw('COUNT(DISTINCT c.external_account_id) > 1')
            ->get(['a.creative_id'])
            ->count();
        $this->line(sprintf('      creatives carried by ads of two or more accounts     : %d', $shared));

        // A creative whose campaign is filed in another project — a link that crossed a project.
        $crossed = DB::table('external_creatives as cr')
            ->join('external_campaigns as c', 'c.id', '=', 'cr.external_campaign_id')
            ->where('cr.project_id', $projectId)
            ->whereColumn('c.project_id', '!=', 'cr.project_id')
            ->count();
        $this->line(sprintf('      creatives linked to a campaign of another project    : %d', $crossed));

        // Ad sets / ads whose campaign sits in another project.
        foreach (['external_ad_sets', 'external_ads'] as $table) {
            $n = DB::table("{$table} as t")
                ->join('external_campaigns as c', 'c.id', '=', 't.external_campaign_id')
                ->where('t.project_id', $projectId)
                ->whereColumn('c.project_id', '!=', 't.project_id')
                ->count();
            $this->line(sprintf('      %-28s under a campaign of another project : %d', $table, $n));
        }

        // Entity metrics whose stamped account disagrees with the campaign they name.
        $disagree = DB::table('entity_daily_metrics as e')
            ->join('external_campaigns as c', 'c.id', '=', 'e.external_campaign_id')
            ->where('e.project_id', $projectId)
            ->whereNotNull('e.external_account_id')
            ->whereColumn('e.external_account_id', '!=', 'c.external_account_id')
            ->count();
        $this->line(sprintf('      entity metric rows stamped with a different account  : %d', $disagree));

        // Daily metrics whose account disagrees with their campaign's account.
        $disagreeDaily = DB::table('daily_metrics as m')
            ->join('external_campaigns as c', 'c.id', '=', 'm.external_campaign_id')
            ->where('m.project_id', $projectId)
            ->whereColumn('m.external_account_id', '!=', 'c.external_account_id')
            ->count();
        $this->line(sprintf('      daily metric rows stamped with a different account   : %d', $disagreeDaily));
    }

    // ── the estate, across projects ────────────────────────────────────────────────────────────────

    private function reportEstate(): void
    {
        $this->line('');
        $this->line(str_repeat('-', 78));
        $this->line('  ESTATE — what no single project can show');

        // One account ACTIVE in two projects at once — projectIdFor() then picks one silently.
        $twice = 0;
        foreach ($this->activeOn as $projects) {
            if (count(array_unique($projects)) > 1) {
                $twice++;
            }
        }
        $this->line(sprintf('    accounts with an ACTIVE binding to more than one project : %d', $twice));

        // The same provider account discovered under more than one tenant.
        $crossTenant = DB::table('external_accounts')
            ->when($this->providerFilter(), fn ($q, $p) => $q->where('provider', $p))
            ->select('provider', 'external_id')
            ->groupBy('provider', 'external_id')
            ->havingRaw('COUNT(DISTINCT tenant_id) > 1')
            ->get()
            ->count();
        $this->line(sprintf('    provider accounts discovered under more than one tenant    : %d', $crossTenant));

        // A commerce connection carrying more than one store — the token is replaced per consent.
        $multiStore = DB::table('external_accounts')
            ->where('account_type', 'store')
            ->when($this->providerFilter(), fn ($q, $p) => $q->where('provider', $p))
            ->select('provider_connection_id')
            ->groupBy('provider_connection_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        $this->line(sprintf('    commerce connections carrying more than one store         : %d', $multiStore));

        // Retained provider bodies have no project column; attributed by whether the account is bound anywhere.
        $this->line('');
        $this->line('    integration_raw_payloads — by account, resource and month (no project column)');
        $rows = DB::table('integration_raw_payloads as p')
            ->when($this->providerFilter(), fn ($q, $p) => $q->where('p.provider', $p))
            ->tap(fn ($q) => $this->monthWindow($q, 'p.fetched_at'))
            ->selectRaw("p.external_account_id as account_id, p.provider, p.resource, to_char(p.fetched_at, 'YYYY-MM') as month, COUNT(*) as rows")
            ->groupBy('p.external_account_id', 'p.provider', 'p.resource', 'month')
            ->orderBy('p.provider')->orderBy('p.external_account_id')->orderBy('p.resource')->orderBy('month')
            ->get();

        $limit = max(1, (int) $this->option('lines'));
        $totals = ['bound_somewhere' => 0, 'once_bound' => 0, 'unbound' => 0, 'none' => 0];
        $printed = 0;

        foreach ($rows as $row) {
            $account = $row->account_id === null ? null : (string) $row->account_id;
            $class = match (true) {
                $account === null => 'none',
                ($this->activeOn[$account] ?? []) !== [] => 'bound_somewhere',
                ($this->inactiveOn[$account] ?? []) !== [] => 'once_bound',
                default => 'unbound',
            };
            $totals[$class] += (int) $row->rows;

            if ($printed < $limit) {
                $this->line(sprintf('      %-16s %-36s [%-8s] %-10s %-8s %6d',
                    $class, $account ?? '(no account)', (string) $row->provider, (string) $row->resource, (string) $row->month, (int) $row->rows));
                $printed++;
            }
        }
        if ($rows->count() > $limit) {
            $this->line(sprintf('      … %d more line(s) folded into the totals', $rows->count() - $limit));
        }
        $this->line(sprintf('      bound_somewhere %d · once_bound %d · unbound %d · none %d',
            $totals['bound_somewhere'], $totals['once_bound'], $totals['unbound'], $totals['none']));
    }

    private function reportSummary(): void
    {
        $this->line('');
        $this->line(str_repeat('=', 78));
        $this->line('  INVENTORY — rows OUTSIDE each project\'s ACTIVE bound set, by grain');
        $this->line(str_repeat('=', 78));
        $this->line(sprintf('  %-36s %-26s %8s %8s %8s %8s %8s %8s',
            'project', 'grain', 'total', 'outside', 'once', 'elsewh', 'unbound', 'none'));

        foreach ($this->summary as $projectId => $grains) {
            foreach ($grains as $grain => $t) {
                $all = array_sum($t);
                if ($all === 0) {
                    continue;
                }
                $this->line(sprintf('  %-36s %-26s %8d %8d %8d %8d %8d %8d',
                    $projectId, $grain, $all, $all - $t['bound'], $t['once_bound'], $t['bound_elsewhere'], $t['unbound'], $t['none']));
            }
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────────────

    private function monthWindow(Builder $q, string $column): void
    {
        $from = $this->stringOption('from');
        $to = $this->stringOption('to');

        if ($from !== null && preg_match('/^\d{4}-\d{2}$/', $from) === 1) {
            $q->whereRaw("{$column} >= ?", [$from.'-01']);
        }
        if ($to !== null && preg_match('/^\d{4}-\d{2}$/', $to) === 1) {
            $q->whereRaw("{$column} < (?::date + interval '1 month')", [$to.'-01']);
        }
    }

    private function providerFilter(): ?string
    {
        return $this->stringOption('provider');
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
