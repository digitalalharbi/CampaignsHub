<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\PlatformObjectiveMap;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — the cleanup, bounded, dry-run first, and never a guess.
 *
 * ## What it acts on
 *
 * ONE account's rows inside ONE project, and only when that account is NOT actively bound to that
 * project. Rows whose account is bound are legitimate whatever else is true of them, and this command
 * refuses to touch them — the refusal is the first thing it checks and the test holds it.
 *
 * ## Two actions, and what proves each
 *
 *   remove    the rows are deleted. Offered for an account with no active binding anywhere
 *             (`unbound`) or bound to this project only inactively (`once_bound`) — rows the
 *             inventory reported outside every selected set. Deselected history is legitimate and is
 *             normally KEPT (it reappears on re-selection); removing it is the operator's explicit
 *             choice, made per account, per project, per grain, per window, on the inventory's word.
 *
 *   reassign  the rows are moved to the project the account is ACTIVELY bound to — provenance is the
 *             binding itself, so this is offered only when exactly one active binding exists
 *             (`bound_elsewhere`). Campaigns are adopted afresh in the target project and every
 *             lower grain is re-linked, so the figures arrive on the target's own surfaces rather
 *             than as rows with no unified campaign.
 *
 * Anything else — an account active on two projects, a row with no resolvable account — is refused
 * and reported. Ambiguity is never resolved by this command.
 *
 * ## Dry run is the default
 *
 * Without `--apply` it prints the full plan — table, month, count — and writes nothing; the test
 * holds a digest of every table across a dry run. With `--apply` it does exactly what the plan said,
 * in one transaction, audited, and prints the same counts as what was done. Running it again finds
 * nothing to do.
 */
final class ScopeCleanupCommand extends Command
{
    protected $signature = 'integrations:scope-cleanup
        {--project= : The project whose stored rows are being cleaned (required)}
        {--account= : The account whose rows are outside the project\'s selected set (required)}
        {--action=remove : remove | reassign}
        {--grain=all : all | structure | figures | commerce | runs}
        {--from= : Earliest month for the dated grains, YYYY-MM}
        {--to= : Latest month for the dated grains, YYYY-MM}
        {--apply : Actually write. Without this the command only prints the plan.}
        {--revert= : Undo ONE earlier reassign, by its audit entry id: every moved row gets back the project and links it had.}';

    protected $description = 'Remove or reassign one non-selected account\'s rows inside one project — dry run unless --apply.';

    private const STRUCTURE = ['external_campaigns', 'external_ad_sets', 'external_ads', 'external_creatives'];

    private const FIGURES = ['daily_metrics', 'entity_daily_metrics', 'creative_daily_metrics'];

    private const COMMERCE = ['commerce_orders', 'commerce_products', 'commerce_customers', 'commerce_abandoned_carts'];

    private const RUNS = ['metric_sync_runs'];

    private const DATE_COLUMN = [
        'daily_metrics' => 'metric_date',
        'entity_daily_metrics' => 'metric_date',
        'creative_daily_metrics' => 'metric_date',
        'commerce_orders' => 'placed_at',
        'commerce_abandoned_carts' => 'abandoned_at',
    ];

    /**
     * The column each table links its campaign by — what a reassign rewrites besides `project_id`, and what a revert restores.
     */
    private const LINK_COLUMN = [
        'external_campaigns' => 'unified_campaign_id',
        'external_ad_sets' => 'unified_campaign_id',
        'external_ads' => 'unified_campaign_id',
        'external_creatives' => 'campaign_id',
        'daily_metrics' => 'unified_campaign_id',
        'creative_daily_metrics' => 'campaign_id',
    ];

    public function handle(AuditLogger $audit): int
    {
        if ($this->stringOption('revert') !== null) {
            return $this->revert((string) $this->stringOption('revert'), $audit);
        }

        $project = $this->required('project');
        $account = $this->required('account');
        $action = (string) $this->option('action');
        $apply = (bool) $this->option('apply');

        if ($project === null || $account === null) {
            $this->error('Both --project and --account are required: this command acts on one account inside one project, never wider.');

            return self::FAILURE;
        }

        if (! in_array($action, ['remove', 'reassign'], true)) {
            $this->error('--action must be remove or reassign.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line(str_repeat('=', 78));
        $this->line(sprintf('  ACCOUNT-SCOPE CLEANUP — %s', $apply ? 'APPLYING' : 'dry run, nothing will change'));
        $this->line(str_repeat('=', 78));
        $this->line(sprintf('  project %s', $project));
        $this->line(sprintf('  account %s', $account));
        $this->line(sprintf('  action  %s · grain %s · months %s → %s', $action, (string) $this->option('grain'), $this->stringOption('from') ?? '(any)', $this->stringOption('to') ?? '(any)'));

        // ── the refusals, before a single count ────────────────────────────────────────────────────

        $active = DB::table('project_integration_bindings')
            ->where('external_account_id', $account)
            ->where('is_active', true)
            ->pluck('project_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values();

        if ($active->contains($project)) {
            $this->error('  REFUSED: this account is ACTIVELY selected for this project. Its rows are legitimate and this command never touches them.');

            return self::FAILURE;
        }

        if ($action === 'reassign') {
            if ($active->count() !== 1) {
                $this->error(sprintf(
                    '  REFUSED: reassign needs exactly one active binding as provenance; this account has %d. Ambiguity is not resolved here.',
                    $active->count(),
                ));

                return self::FAILURE;
            }
            $target = (string) $active->first();
            $this->line(sprintf('  target  %s (the account\'s one active binding)', $target));
        } else {
            $target = null;
            if ($active->isNotEmpty()) {
                $this->error('  REFUSED: this account is actively selected for another project; its rows belong there. Use --action=reassign.');

                return self::FAILURE;
            }
        }

        // ── the plan ───────────────────────────────────────────────────────────────────────────────

        $plan = [];
        $total = 0;

        foreach ($this->tables() as $table) {
            $rows = $this->rowsOf($table, $project, $account)
                ->when(isset(self::DATE_COLUMN[$table]), fn ($q) => $this->monthWindow($q, 't.'.self::DATE_COLUMN[$table]))
                ->selectRaw($this->monthExpression($table).' as month, COUNT(*) as rows')
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            foreach ($rows as $row) {
                $plan[] = [$table, (string) ($row->month ?? '—'), (int) $row->rows];
                $total += (int) $row->rows;
            }
        }

        $this->line('');
        $this->line('  plan — rows outside the selected set, by table and month');
        foreach ($plan as [$table, $month, $count]) {
            $this->line(sprintf('    %-28s %-8s %8d', $table, $month, $count));
        }
        $this->line(sprintf('    %-28s %-8s %8d', 'TOTAL', '', $total));

        if ($total === 0) {
            $this->line('');
            $this->line('  Nothing to do for this account in this project (already clean, or already applied).');

            return self::SUCCESS;
        }

        $this->evidence($project, $account, $target);

        if (! $apply) {
            $this->line('');
            $this->line('  Nothing was changed. Re-run with --apply to carry out exactly this plan.');

            return self::SUCCESS;
        }

        // ── apply, in one transaction, exactly the plan ────────────────────────────────────────────

        $planned = [];
        foreach ($plan as [$table, , $count]) {
            $planned[$table] = ($planned[$table] ?? 0) + $count;
        }
        // Every row the plan names, with the project and link it has NOW — the audit keeps it, so the move can be undone exactly.
        $snapshot = $action === 'reassign' ? $this->snapshot($project, $account) : [];

        try {
            $done = DB::transaction(function () use ($action, $project, $account, $target, $planned): array {
                $done = $action === 'remove'
                    ? $this->remove($project, $account)
                    : $this->reassign($project, $account, (string) $target);

                // The plan is the promise: a table that moved a different number of rows than it showed rolls the whole move back.
                foreach ($planned as $table => $count) {
                    if (($done[$table] ?? 0) !== $count) {
                        throw new \RuntimeException(sprintf('%s: planned %d, would have changed %d — nothing was written.', $table, $count, $done[$table] ?? 0));
                    }
                }

                return $done;
            });
        } catch (\RuntimeException $e) {
            $this->error('  ROLLED BACK: '.$e->getMessage());

            return self::FAILURE;
        }

        $entry = $audit->log(
            action: 'integration.scope_cleanup.'.$action,
            entityType: 'external_account',
            entityId: $account,
            before: $snapshot === [] ? null : ['rows' => $snapshot],
            after: ['project' => $project, 'target' => $target, 'grain' => (string) $this->option('grain'), 'from' => $this->stringOption('from'), 'to' => $this->stringOption('to'), 'rows' => $done],
        );
        if ($action === 'reassign') {
            $this->line(sprintf('  audit %s — undo with --revert=%s', $entry->getKey(), $entry->getKey()));
        }

        $this->line('');
        $this->line('  done — rows '.$action.'d, by table');
        foreach ($done as $table => $count) {
            $this->line(sprintf('    %-28s %8d', $table, $count));
        }

        return self::SUCCESS;
    }

    // ── actions ────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string,int> */
    private function remove(string $project, string $account): array
    {
        $done = [];

        // Lowest grain first so no foreign key ever points at a row that is already gone.
        foreach (array_reverse($this->tables()) as $table) {
            $done[$table] = $this->rowsOf($table, $project, $account)
                ->when(isset(self::DATE_COLUMN[$table]), fn ($q) => $this->monthWindow($q, 't.'.self::DATE_COLUMN[$table]))
                ->delete();
        }

        return $done;
    }

    /**
     * Move the rows to the target project and re-link them to unified campaigns adopted THERE.
     *
     * @return array<string,int>
     */
    private function reassign(string $project, string $account, string $target): array
    {
        $done = array_fill_keys($this->tables(), 0);
        $targetWorkspace = DB::table('projects')->where('id', $target)->value('client_workspace_id');
        $tenant = DB::table('external_accounts')->where('id', $account)->value('tenant_id');

        $campaigns = $this->rowsOf('external_campaigns', $project, $account)
            ->get(['t.id', 't.name', 't.status', 't.objective', 't.lifetime_budget', 't.currency', 't.provider', 't.external_id']);

        foreach ($campaigns as $campaign) {
            $unified = null;

            if (in_array('external_campaigns', $this->tables(), true)) {
                $unified = (string) UnifiedCampaign::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant,
                    'project_id' => $target,
                    'client_workspace_id' => $targetWorkspace,
                    'name' => $this->availableName($target, (string) $campaign->name),
                    // The provider's word resolved the same way the importer resolves it; unknown stays `other`.
                    'objective' => (app(PlatformObjectiveMap::class)->resolve((string) $campaign->provider, $campaign->objective) ?? CampaignObjective::Other)->value,
                    'status' => (string) $campaign->status,
                    'total_budget' => $campaign->lifetime_budget,
                    'budget_currency' => $campaign->currency ?: 'USD',
                    'platforms' => [(string) $campaign->provider],
                    'meta' => ['adopted_from' => 'scope-cleanup', 'external_account_id' => $account, 'external_campaign_id' => (string) $campaign->external_id],
                ])->getKey();

                $done['external_campaigns'] += DB::table('external_campaigns')->where('id', $campaign->id)
                    ->update(['project_id' => $target, 'client_workspace_id' => $targetWorkspace, 'unified_campaign_id' => $unified, 'unlinked_at' => null]);
            }

            foreach (['external_ad_sets', 'external_ads'] as $table) {
                if (in_array($table, $this->tables(), true)) {
                    $done[$table] += DB::table($table)->where('external_campaign_id', $campaign->id)->where('project_id', $project)
                        ->update(['project_id' => $target] + ($unified !== null ? ['unified_campaign_id' => $unified] : []));
                }
            }

            if (in_array('external_creatives', $this->tables(), true)) {
                $creativeIds = DB::table('external_creatives')->where('external_campaign_id', $campaign->id)->where('project_id', $project)->pluck('id');
                $done['external_creatives'] += DB::table('external_creatives')->whereIn('id', $creativeIds)
                    ->update(['project_id' => $target] + ($unified !== null ? ['campaign_id' => $unified] : []));

                if (in_array('creative_daily_metrics', $this->tables(), true)) {
                    $done['creative_daily_metrics'] += DB::table('creative_daily_metrics as t')->whereIn('t.creative_id', $creativeIds)->where('t.project_id', $project)
                        ->tap(fn ($q) => $this->monthWindow($q, 't.metric_date'))
                        ->update(['project_id' => $target] + ($unified !== null ? ['campaign_id' => $unified] : []));
                }
            }

            foreach (['daily_metrics', 'entity_daily_metrics'] as $table) {
                if (in_array($table, $this->tables(), true)) {
                    $done[$table] += DB::table("{$table} as t")->where('t.external_campaign_id', $campaign->id)->where('t.project_id', $project)
                        ->tap(fn ($q) => $this->monthWindow($q, 't.metric_date'))
                        ->update(['project_id' => $target] + ($unified !== null && $table === 'daily_metrics' ? ['unified_campaign_id' => $unified] : []));
                }
            }
        }

        /*
         * Rows still filed under the project whose CAMPAIGN already lives in the target — the shape a re-bind leaves behind: the
         * campaigns were adopted by the account's new project, the figures synced before it stayed. The loop above only walks campaigns
         * still in the source project, so without this the plan counted these rows and the apply silently left them where they were.
         * Each is moved and re-linked to the unified campaign its own campaign has in the target.
         */
        $window = $this->windowSql('t.metric_date');
        $strays = [
            'creative_daily_metrics' => ['SET project_id = ?, campaign_id = ec.unified_campaign_id FROM external_creatives cr JOIN external_campaigns ec ON ec.id = cr.external_campaign_id WHERE cr.id = t.creative_id', true],
            'daily_metrics' => ['SET project_id = ?, unified_campaign_id = ec.unified_campaign_id FROM external_campaigns ec WHERE ec.id = t.external_campaign_id AND t.external_account_id = ec.external_account_id', true],
            'entity_daily_metrics' => ['SET project_id = ? FROM external_campaigns ec WHERE ec.id = t.external_campaign_id AND (t.external_account_id = ec.external_account_id OR t.external_account_id IS NULL)', true],
            'external_creatives' => ['SET project_id = ?, campaign_id = ec.unified_campaign_id FROM external_campaigns ec WHERE ec.id = t.external_campaign_id', false],
            'external_ad_sets' => ['SET project_id = ?, unified_campaign_id = ec.unified_campaign_id FROM external_campaigns ec WHERE ec.id = t.external_campaign_id', false],
            'external_ads' => ['SET project_id = ?, unified_campaign_id = ec.unified_campaign_id FROM external_campaigns ec WHERE ec.id = t.external_campaign_id', false],
        ];
        foreach ($strays as $table => [$set, $dated]) {
            if (! in_array($table, $this->tables(), true)) {
                continue;
            }
            [$windowSql, $windowBindings] = $dated ? $window : ['', []];
            $done[$table] += DB::update(
                "UPDATE {$table} AS t {$set} AND ec.external_account_id = ? AND ec.project_id = ? AND t.project_id = ?{$windowSql}",
                [$target, $account, $target, $project, ...$windowBindings],
            );
        }

        foreach ([...self::COMMERCE, ...self::RUNS] as $table) {
            if (in_array($table, $this->tables(), true)) {
                $done[$table] += $this->rowsOf($table, $project, $account)
                    ->when(isset(self::DATE_COLUMN[$table]), fn ($q) => $this->monthWindow($q, 't.'.self::DATE_COLUMN[$table]))
                    ->update(['project_id' => $target]);
            }
        }

        return $done;
    }

    // ── the rows, per table ────────────────────────────────────────────────────────────────────────

    /** Every row of `$table` filed under `$project` that belongs to `$account`. */
    private function rowsOf(string $table, string $project, string $account): Builder
    {
        $q = DB::table("{$table} as t")->where('t.project_id', $project);

        return match ($table) {
            'external_campaigns', 'daily_metrics', 'metric_sync_runs',
            'commerce_orders', 'commerce_products', 'commerce_customers', 'commerce_abandoned_carts' => $q->where('t.external_account_id', $account),
            'external_ad_sets', 'external_ads', 'external_creatives' => $q->whereIn('t.external_campaign_id', $this->campaignsOf($account)),
            'entity_daily_metrics' => $q->where(fn ($w) => $w->where('t.external_account_id', $account)
                ->orWhere(fn ($x) => $x->whereNull('t.external_account_id')->whereIn('t.external_campaign_id', $this->campaignsOf($account)))),
            'creative_daily_metrics' => $q->whereIn('t.creative_id', DB::table('external_creatives')->select('id')->whereIn('external_campaign_id', $this->campaignsOf($account))),
            default => $q->whereRaw('1 = 0'),
        };
    }

    private function campaignsOf(string $account): Builder
    {
        return DB::table('external_campaigns')->select('id')->where('external_account_id', $account);
    }

    /** @return list<string> */
    private function tables(): array
    {
        return match ((string) $this->option('grain')) {
            'structure' => self::STRUCTURE,
            'figures' => self::FIGURES,
            'commerce' => self::COMMERCE,
            'runs' => self::RUNS,
            default => [...self::STRUCTURE, ...self::FIGURES, ...self::COMMERCE, ...self::RUNS],
        };
    }

    private function monthExpression(string $table): string
    {
        $column = self::DATE_COLUMN[$table] ?? null;

        return $column === null ? "'—'" : "to_char(t.{$column}, 'YYYY-MM')";
    }

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

    /** The month window as raw SQL, for the joined UPDATEs that the query builder cannot express. @return array{0: string, 1: list<string>} */
    private function windowSql(string $column): array
    {
        $sql = '';
        $bindings = [];
        $from = $this->stringOption('from');
        $to = $this->stringOption('to');
        if ($from !== null && preg_match('/^\d{4}-\d{2}$/', $from) === 1) {
            $sql .= " AND {$column} >= ?::date";
            $bindings[] = $from.'-01';
        }
        if ($to !== null && preg_match('/^\d{4}-\d{2}$/', $to) === 1) {
            $sql .= " AND {$column} < (?::date + interval '1 month')";
            $bindings[] = $to.'-01';
        }

        return [$sql, $bindings];
    }

    /**
     * What a person needs before deciding: whose history this is, which days and campaigns, what the source project reads of it
     * today, and the figures themselves. Printed on every run, dry or not; it reads and never writes.
     */
    private function evidence(string $project, string $account, ?string $target): void
    {
        $name = static fn (?string $id): string => $id === null ? '—' : (string) (DB::table('projects')->where('id', $id)->value('name') ?? '(deleted)');

        $this->line('');
        $this->line('  evidence — the account\'s bindings, oldest first');
        foreach (DB::table('project_integration_bindings')->where('external_account_id', $account)->orderBy('created_at')->get(['project_id', 'purpose', 'is_active', 'created_at', 'updated_at']) as $b) {
            $this->line(sprintf('    %-36s %-24s %-11s %-8s bound %s · last change %s', $b->project_id, mb_substr($name((string) $b->project_id), 0, 24), (string) $b->purpose, $b->is_active ? 'ACTIVE' : 'inactive', (string) $b->created_at, (string) $b->updated_at));
        }
        $this->line(sprintf('    source %s «%s» → target %s «%s»', $project, $name($project), $target ?? '—', $name($target)));

        $this->line('');
        $this->line('  evidence — dated range per table');
        foreach (self::DATE_COLUMN as $table => $column) {
            if (! in_array($table, $this->tables(), true)) {
                continue;
            }
            $r = $this->rowsOf($table, $project, $account)->tap(fn ($q) => $this->monthWindow($q, 't.'.$column))
                ->selectRaw("MIN(t.{$column}) AS first, MAX(t.{$column}) AS last, COUNT(*) AS n")->first();
            if ((int) ($r->n ?? 0) > 0) {
                $this->line(sprintf('    %-28s %s → %s  (%d rows)', $table, (string) $r->first, (string) $r->last, (int) $r->n));
            }
        }

        if (in_array('daily_metrics', $this->tables(), true)) {
            $this->line('');
            $this->line('  evidence — daily_metrics by day (spend · impressions · clicks, as stored)');
            $days = $this->rowsOf('daily_metrics', $project, $account)->tap(fn ($q) => $this->monthWindow($q, 't.metric_date'))
                ->selectRaw("t.metric_date AS day, COUNT(*) AS n, SUM(t.value) FILTER (WHERE t.metric_key = 'spend') AS spend, SUM(t.value) FILTER (WHERE t.metric_key = 'impressions') AS impressions, SUM(t.value) FILTER (WHERE t.metric_key = 'clicks') AS clicks")
                ->groupBy('t.metric_date')->orderBy('t.metric_date')->get();
            foreach ($days as $d) {
                $this->line(sprintf('    %s  rows %4d  spend %10.2f  impressions %9d  clicks %7d', (string) $d->day, (int) $d->n, (float) $d->spend, (int) $d->impressions, (int) $d->clicks));
            }

            $this->line('');
            $this->line('  evidence — campaigns those rows belong to (provider id · name · where the campaign is filed now)');
            $campaigns = $this->rowsOf('daily_metrics', $project, $account)->tap(fn ($q) => $this->monthWindow($q, 't.metric_date'))
                ->join('external_campaigns as ec', 'ec.id', '=', 't.external_campaign_id')
                ->selectRaw("ec.external_id, ec.name, ec.project_id, COUNT(*) AS n, SUM(t.value) FILTER (WHERE t.metric_key = 'spend') AS spend")
                ->groupBy('ec.external_id', 'ec.name', 'ec.project_id')->orderByDesc('spend')->get();
            foreach ($campaigns as $c) {
                $this->line(sprintf('    %-36s %-30s filed in %s  rows %4d  spend %10.2f', (string) $c->external_id, mb_substr((string) $c->name, 0, 30), $c->project_id === $project ? 'SOURCE' : ($c->project_id === $target ? 'TARGET' : 'ELSEWHERE '.$c->project_id), (int) $c->n, (float) $c->spend));
            }
        }

        // What the source project's reports read of these rows today — the foreign impact of moving them.
        $readable = DB::table('daily_metrics')->where('project_id', $project)->where('external_account_id', $account)
            ->tap(fn ($q) => $this->monthWindow($q, 'daily_metrics.metric_date'))
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))->count();
        $this->line('');
        $this->line(sprintf('  evidence — source project reads %d of these daily_metrics rows today (0 = moving them changes none of its reports)', $readable));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(string $project, string $account): array
    {
        $out = [];
        foreach ($this->tables() as $table) {
            $columns = ['t.id', 't.project_id'];
            if (isset(self::LINK_COLUMN[$table])) {
                $columns[] = 't.'.self::LINK_COLUMN[$table].' as link';
            }
            if ($table === 'external_campaigns') {
                $columns[] = 't.client_workspace_id';
            }
            $rows = $this->rowsOf($table, $project, $account)
                ->when(isset(self::DATE_COLUMN[$table]), fn ($q) => $this->monthWindow($q, 't.'.self::DATE_COLUMN[$table]))
                ->get($columns)->map(static fn ($r): array => (array) $r)->all();
            if ($rows !== []) {
                $out[$table] = $rows;
            }
        }

        return $out;
    }

    /** Undo one reassign from its audit entry: every row it moved gets back exactly the project and link it had. */
    private function revert(string $auditId, AuditLogger $audit): int
    {
        $entry = DB::table('audit_logs')->where('id', $auditId)->where('action', 'integration.scope_cleanup.reassign')->first();
        $rows = $entry === null ? null : (json_decode((string) $entry->before, true)['rows'] ?? null);
        if (! is_array($rows)) {
            $this->error('  REFUSED: no reassign with a recorded snapshot under that audit id.');

            return self::FAILURE;
        }

        $done = DB::transaction(function () use ($rows): array {
            $done = [];
            foreach ($rows as $table => $list) {
                if (! in_array($table, [...self::STRUCTURE, ...self::FIGURES, ...self::COMMERCE, ...self::RUNS], true)) {
                    continue;
                }
                $done[$table] = 0;
                foreach ((array) $list as $row) {
                    $set = ['project_id' => $row['project_id']];
                    if (array_key_exists('link', $row) && isset(self::LINK_COLUMN[$table])) {
                        $set[self::LINK_COLUMN[$table]] = $row['link'];
                    }
                    if (array_key_exists('client_workspace_id', $row)) {
                        $set['client_workspace_id'] = $row['client_workspace_id'];
                    }
                    $done[$table] += DB::table($table)->where('id', $row['id'])->update($set);
                }
            }

            return $done;
        });

        $audit->log(action: 'integration.scope_cleanup.revert', entityType: 'external_account', entityId: (string) $entry->entity_id, after: ['reverted' => $auditId, 'rows' => $done]);
        $this->line('  reverted — rows restored, by table');
        foreach ($done as $table => $count) {
            $this->line(sprintf('    %-28s %8d', $table, $count));
        }

        return self::SUCCESS;
    }

    private function availableName(string $project, string $name): string
    {
        $candidate = $name;
        $n = 2;
        while (UnifiedCampaign::withoutGlobalScopes()->where('project_id', $project)->where('name', $candidate)->exists()) {
            $candidate = "{$name} ({$n})";
            $n++;
        }

        return $candidate;
    }

    private function required(string $name): ?string
    {
        return $this->stringOption($name);
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
