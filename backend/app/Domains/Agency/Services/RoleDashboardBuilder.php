<?php

declare(strict_types=1);

namespace App\Domains\Agency\Services;

use App\Domains\Access\Models\Role;
use App\Domains\Access\Support\WorkspaceRolePresets;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Services\BillingReach;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Models\ContentApproval;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Funding\Services\FundingReach;
use App\Domains\Funding\Services\ProjectBudgetReader;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Services\ClientBudgetRollup;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Access\ProjectReach;
use App\Domains\Projects\Models\Project;
use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;

/**
 * ROLE-DASHBOARD-001 — one landing page per role, from facts the product already computes.
 *
 * Nothing here is a new metric. Budgets, spend, remaining and run-out are the APPROVED project budgets
 * (`ProjectBudgetReader`, ONE-BUDGET-001 — the same reading as /agency/budgets, over each budget's own period); the
 * campaign budgets set inside the platforms ride along as `money.platform_campaign_budgets`, informational only. Results and cost per result are the objective's own
 * (`byCampaign()`); invoices come through `BillingReach`, tasks and approvals through the clients the reader reaches.
 *
 * ## Reach is decided once, here, and nothing widens it
 *
 * The project set is the intersection of three ceilings — the client roster the reader may open (`ClientAccess`), the
 * project allow-list inside a client, and `ProjectReach` (membership and project membership). Every figure below is
 * computed over that set only, so a person scoped to three clients totals three clients. A section whose key the
 * reader lacks is not computed at all (`null`), never computed and hidden.
 *
 * ## Honest absences
 *
 *  - A figure the aggregator withholds (no FX rate, nothing measured) stays `null`; it is never turned into a zero.
 *  - Two currencies inside one roll-up refuse the total, exactly as the budgets list does.
 *  - The run-out date is `remaining ÷ the average daily spend of the last three COMPLETE days`; today is left out
 *    because a day in progress under-counts. No spend in those days means no date, not «never».
 *  - «Best» and «worst» campaigns are ranked only against campaigns of the SAME objective family: a cost per lead and
 *    a cost per visit are not on one scale.
 */
final class RoleDashboardBuilder
{
    /** The share of a budget at which a unit reads «near»; at or beyond 1 it reads «exceeded». */
    public const NEAR = 0.8;

    public const VIEWS = ['manager', 'pm', 'finance', 'marketer'];

    private const OWING = ['issued', 'sent', 'partially_paid', 'overdue'];

    public function __construct(
        private readonly ClientAccess $access,
        private readonly ClientScopeResolver $scopes,
        private readonly ProjectReach $reach,
        private readonly ClientBudgetRollup $rollup,
        private readonly BillingReach $billing,
        private readonly ProjectBudgetReader $budgets,
        private readonly FundingReach $fundingReach,
    ) {}

    /** @return array<string, mixed> */
    public function build(User $user, ?string $requested = null): array
    {
        $role = $user->rolesInCurrentTenant()->sortBy('id')->first();
        $slug = $role instanceof Role ? (string) $role->slug : null;
        $isOwner = (bool) $user->is_platform_admin || $user->hasRole(WorkspaceRolePresets::OWNER);

        $default = $this->defaultView($user, $slug, $isOwner);
        // Only the workspace manager may look at another role's page; for anyone else the request is ignored.
        $view = $isOwner && in_array($requested, self::VIEWS, true) ? (string) $requested : $default;

        $today = Carbon::today();
        $to = $today->copy();
        $from = $to->copy()->subDays(29);

        $scope = $this->scope($user);
        $projectIds = array_keys($scope['projects']);
        $sections = $user->accessSections();
        $money = $user->hasPermission('budget.view') || $user->hasPermission('billing.view');

        $out = [
            'view' => $view,
            'default_view' => $default,
            'available_views' => $isOwner ? self::VIEWS : [$default],
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => 30, 'today' => $today->toDateString()],
            'thresholds' => ['near' => self::NEAR],
            'scope' => ['clients' => count($scope['clients']), 'projects' => count($projectIds), 'restricted' => $scope['restricted']],
            'can_money' => $money,
            'sections' => $sections,
        ];

        $agg = $projectIds === [] ? null : app(MetricsAggregator::class)->forProjects($projectIds);

        $pacing = $money && $agg !== null ? $agg->budgetPacing($from, $to, $today) : [];
        // ONE-BUDGET-001 — the approved budgets, never the 30-day window above; a project whose membership withholds money is left out.
        $approved = $money ? $this->budgets->forProjects($this->fundingReach->moneyVisible($user, array_map('strval', $projectIds))) : [];
        $units = $money ? $this->units($scope, $approved, $today) : [];

        $out['money'] = $money ? $this->reading(array_values($approved)) + [
            'rows' => count(array_filter($approved, static fn (array $r): bool => $r['has_budget'])),
            'source' => 'approved_budget',
            'platform_campaign_budgets' => $this->rollup->of($pacing),
        ] : null;
        $out['units'] = $units;
        $out['daily_spend'] = $money && $agg !== null ? $this->dailySpend($agg, $today) : [];

        if ($view === 'manager') {
            $out['alerts'] = $this->alerts($user, $projectIds);
            $out['integrations'] = in_array('integrations', $sections, true) ? $this->integrations($scope) : null;
            $out['campaigns'] = $this->campaignCounts($projectIds, null);
        }

        if ($view === 'pm') {
            $out['campaigns'] = $this->campaignCounts($projectIds, null);
            $out['tasks'] = $user->hasPermission('tasks.view') ? $this->tasks($user, $scope, $today) : null;
            $out['approvals'] = $user->hasPermission('content.view') ? $this->approvals($scope) : null;
        }

        if ($view === 'finance') {
            $out['invoices'] = $user->hasPermission('billing.view') ? $this->invoices($user, $today) : null;
        }

        if ($view === 'marketer') {
            $out += $this->marketer($user, $agg, $projectIds, $from, $to, $today, $money);
        }

        return $out;
    }

    private function defaultView(User $user, ?string $slug, bool $isOwner): string
    {
        if ($isOwner) {
            return 'manager';
        }

        return match (true) {
            $slug === WorkspaceRolePresets::PROJECT_MANAGER => 'pm',
            $slug === WorkspaceRolePresets::FINANCE => 'finance',
            $slug === WorkspaceRolePresets::MEDIA_BUYER => 'marketer',
            // A custom or read-only role follows what its keys open: money without campaigns reads as finance.
            $user->hasPermission('campaigns.view') && ! $user->hasPermission('settings.manage') => 'marketer',
            $user->hasPermission('billing.view') || $user->hasPermission('budget.manage') => 'finance',
            default => 'marketer',
        };
    }

    /**
     * @return array{clients: array<int|string, string>, projects: array<int|string, array{name: string, client_id: string|null}>, restricted: bool}
     */
    private function scope(User $user): array
    {
        // PROJECT-LIFECYCLE-001 rule 4 — the client's own state never hides its live projects' figures (project state decides).
        $clientQuery = ClientWorkspace::query();
        $this->access->restrictQuery($clientQuery, $user);
        $clients = $clientQuery->get(['id', 'name'])->mapWithKeys(fn (ClientWorkspace $c): array => [(string) $c->id => (string) $c->name])->all();

        $restricted = $this->scopes->reachableClientIds($user) !== null;
        $ceilings = $this->access->projectCeilings($user);
        $own = $this->reach->dataIdsFor($user);

        $query = Project::query()->live()->where(function ($q) use ($clients, $restricted): void { // PROJECT-LIFECYCLE-001
            $q->whereIn('client_workspace_id', array_keys($clients));
            // A workspace with no client roster (the advertiser's own) has client-less projects; a confined agency member has none.
            if (! $restricted) {
                $q->orWhereNull('client_workspace_id');
            }
        });
        if ($own !== null) {
            $query->whereIn('id', $own);
        }

        $projects = [];
        foreach ($query->get(['id', 'name', 'client_workspace_id']) as $p) {
            $client = $p->client_workspace_id === null ? null : (string) $p->client_workspace_id;
            if ($client !== null && ! $this->access->projectAllowed($ceilings, $client, (string) $p->getKey())) {
                continue;
            }
            $projects[(string) $p->getKey()] = ['name' => (string) $p->name, 'client_id' => $client];
        }

        return ['clients' => $clients, 'projects' => $projects, 'restricted' => $restricted];
    }

    /**
     * The approved budgets added together, in the budget-reading shape the views draw. SAR only, so it always adds.
     *
     * @param  list<array<string, mixed>>  $readings
     * @return array<string, mixed>
     */
    private function reading(array $readings): array
    {
        $t = ProjectBudgetReader::total($readings);

        return [
            'budget' => $t['approved'], 'spent' => $t['spent'], 'remaining' => $t['remaining'], 'projected' => null, 'pace' => null,
            'currency' => $t['approved'] === null ? null : 'SAR', 'currencies' => $t['approved'] === null ? 0 : 1,
            'excluded' => $t['unbudgeted'], 'unmeasured' => 0, 'campaigns' => 0,
            'daily_rate_3d' => $t['pace_per_day'], 'cover_days' => $t['cover_days'],
            'budgeted' => $t['budgeted'], 'unbudgeted' => $t['unbudgeted'],
        ];
    }

    /**
     * One row per client (or per project where it has no client), from the approved budgets of its projects: spent of
     * approved, remaining, and the run-out date from the last three complete days (`ProjectBudgetReader`). A unit whose
     * projects have no budget is listed with no figure («بدون ميزانية»), never with a platform number.
     *
     * @param  array{clients: array<int|string, string>, projects: array<int|string, array{name: string, client_id: string|null}>, restricted: bool}  $scope
     * @param  array<string, array<string, mixed>>  $approved
     * @return list<array<string, mixed>>
     */
    private function units(array $scope, array $approved, Carbon $today): array
    {
        $byUnit = [];
        $names = [];
        $kinds = [];
        $projectsOf = [];
        foreach ($approved as $pid => $reading) {
            $project = $scope['projects'][$pid] ?? null;
            if ($project === null) {
                continue;
            }
            $unit = $project['client_id'] ?? 'p:'.$pid;
            $byUnit[$unit][] = $reading;
            $names[$unit] = $project['client_id'] !== null ? ($scope['clients'][$project['client_id']] ?? $project['name']) : $project['name'];
            $kinds[$unit] = $project['client_id'] !== null ? 'client' : 'project';
            $projectsOf[$unit][(string) $pid] = $project['name'];
        }

        $units = [];
        foreach ($byUnit as $unit => $readings) {
            $r = $this->reading($readings);
            $days = $r['cover_days'];
            $units[] = [
                'id' => str_starts_with((string) $unit, 'p:') ? substr((string) $unit, 2) : (string) $unit,
                'kind' => $kinds[$unit],
                'name' => $names[$unit],
                'projects' => array_map(static fn (string $id, string $name): array => ['id' => $id, 'name' => $name], array_keys($projectsOf[$unit]), array_values($projectsOf[$unit])),
                'ratio' => $r['budget'] !== null && $r['spent'] !== null && $r['budget'] > 0 ? round($r['spent'] / $r['budget'], 4) : null,
                'days_left' => $days,
                'run_out_on' => $days === null ? null : $today->copy()->addDays($days)->toDateString(),
            ] + $r;
        }

        // Closest to running out first; units with no budget last.
        usort($units, static fn (array $a, array $b): int => ($b['ratio'] ?? -1) <=> ($a['ratio'] ?? -1));

        return $units;
    }

    /** @return list<array{date: string, spend: float}> the last 14 complete days of the scope's spend */
    private function dailySpend(MetricsAggregator $agg, Carbon $today): array
    {
        $rows = $agg->timeseries($today->copy()->subDays(14), $today->copy()->subDay());

        return array_map(static fn (array $r): array => ['date' => (string) $r['date'], 'spend' => (float) ($r['spend'] ?? 0)], $rows);
    }

    /**
     * @param  list<string>  $projectIds
     * @return array<string, int>
     */
    private function campaignCounts(array $projectIds, ?User $mine): array
    {
        $base = UnifiedCampaign::query()->whereIn('project_id', $projectIds);
        if ($mine !== null) {
            $base->where(fn ($q) => $q->where('owner_id', $mine->getKey())->orWhere('created_by', $mine->getKey()));
        }
        $counts = $projectIds === [] ? [] : $base->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();

        $out = ['active' => 0, 'paused' => 0, 'draft' => 0, 'completed' => 0, 'other' => 0];
        foreach ($counts as $status => $c) {
            $key = array_key_exists((string) $status, $out) ? (string) $status : 'other';
            $out[$key] += (int) $c;
        }

        return $out + ['total' => array_sum($out)];
    }

    /**
     * @param  list<string>  $projectIds
     * @return array{open: int, critical: int, warning: int}|null
     */
    private function alerts(User $user, array $projectIds): ?array
    {
        if (! $user->hasPermission('alerts.view') || $projectIds === []) {
            return $user->hasPermission('alerts.view') ? ['open' => 0, 'critical' => 0, 'warning' => 0] : null;
        }
        $by = AlertEvent::query()->whereIn('project_id', $projectIds)->where('status', 'open')
            ->selectRaw('severity, count(*) as c')->groupBy('severity')->pluck('c', 'severity')->all();
        $critical = (int) ($by['critical'] ?? 0) + (int) ($by['high'] ?? 0);
        $total = (int) array_sum($by);

        return ['open' => $total, 'critical' => $critical, 'warning' => $total - $critical];
    }

    /**
     * Connections by platform: healthy, needing attention, or still being set up.
     *
     * @param  array{clients: array<int|string, string>, projects: array<int|string, mixed>, restricted: bool}  $scope
     * @return array{ok: int, attention: int, pending: int, platforms: list<array{platform: string, ok: int, attention: int}>}
     */
    private function integrations(array $scope): array
    {
        $rows = ProviderConnection::query()
            ->where(function ($q) use ($scope): void {
                $q->whereIn('client_workspace_id', array_keys($scope['clients']));
                if (! $scope['restricted']) {
                    $q->orWhereNull('client_workspace_id');
                }
            })
            ->whereNotIn('status', ['revoked', 'disconnected'])
            ->get(['provider', 'status', 'token_expires_at']);

        $ok = $attention = $pending = 0;
        $by = [];
        foreach ($rows as $c) {
            $platform = AdPlatforms::canonical((string) $c->provider);
            $by[$platform] ??= ['platform' => $platform, 'ok' => 0, 'attention' => 0];
            $expired = $c->token_expires_at !== null && $c->token_expires_at->isPast();
            $status = (string) $c->status;
            if ($status === 'pending') {
                $pending++;
            } elseif ($expired || in_array($status, ['error', 'failed'], true)) {
                $attention++;
                $by[$platform]['attention']++;
            } else {
                $ok++;
                $by[$platform]['ok']++;
            }
        }

        $ordered = [];
        foreach (AdPlatforms::sort(array_keys($by)) as $key) {
            $ordered[] = $by[$key];
        }

        return ['ok' => $ok, 'attention' => $attention, 'pending' => $pending, 'platforms' => $ordered];
    }

    /**
     * @param  array{clients: array<int|string, string>, projects: array<int|string, mixed>, restricted: bool}  $scope
     * @return array<string, int>
     */
    private function tasks(User $user, array $scope, Carbon $today): array
    {
        $open = ['backlog', 'todo', 'in_progress', 'waiting_client', 'blocked', 'review'];
        $base = Task::query()->whereIn('status', $open)->where(function ($q) use ($scope, $user): void {
            $q->whereIn('client_workspace_id', array_keys($scope['clients']))->orWhere('assignee_id', $user->getKey());
        });
        // A confined reader reads tasks of the projects they reach, or tasks with no project at all.
        if ($scope['restricted']) {
            $base->where(fn ($q) => $q->whereNull('project_id')->orWhereIn('project_id', array_keys($scope['projects'])));
        }

        return [
            'open' => (clone $base)->count(),
            'overdue' => (clone $base)->whereDate('due_date', '<', $today->toDateString())->count(),
            'blocked' => (clone $base)->where('status', 'blocked')->count(),
            'mine' => (clone $base)->where('assignee_id', $user->getKey())->count(),
        ];
    }

    /**
     * @param  array{clients: array<int|string, string>, projects: array<int|string, mixed>, restricted: bool}  $scope
     * @return array{pending: int, changes_requested: int}
     */
    private function approvals(array $scope): array
    {
        $by = ContentApproval::query()
            ->whereIn('client_workspace_id', array_keys($scope['clients']))
            ->whereIn('project_id', array_keys($scope['projects']))
            ->whereIn('status', ['pending', 'changes_requested'])
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();

        return ['pending' => (int) ($by['pending'] ?? 0), 'changes_requested' => (int) ($by['changes_requested'] ?? 0)];
    }

    /**
     * Receivables through the finance centre's own reach, in the currency most of the money is in.
     *
     * @return array<string, mixed>
     */
    private function invoices(User $user, Carbon $today): array
    {
        $rows = $this->billing->constrain(Invoice::query(), $user)
            ->whereIn('status', [...self::OWING, 'paid'])
            ->get(['status', 'total', 'amount_paid', 'due_date', 'currency']);

        $currencies = $rows->pluck('currency')->filter()->unique()->values();
        $dominant = $rows->groupBy('currency')->map->count()->sortDesc()->keys()->first();
        $use = $rows->where('currency', $dominant);

        $owed = 0.0;
        $overdue = 0.0;
        $overdueCount = 0;
        $dueSoon = 0;
        foreach ($use->whereIn('status', self::OWING) as $inv) {
            $due = max(0.0, (float) $inv->total - (float) $inv->amount_paid);
            if ($due <= 0) {
                continue;
            }
            $owed += $due;
            if ($inv->due_date !== null && $inv->due_date->lt($today)) {
                $overdue += $due;
                $overdueCount++;
            } elseif ($inv->due_date !== null && $inv->due_date->lte($today->copy()->addDays(7))) {
                $dueSoon++;
            }
        }

        return [
            'currency' => $dominant === null ? null : (string) $dominant,
            'mixed_currency' => $currencies->count() > 1,
            'invoiced' => round((float) $use->sum('total'), 2),
            'collected' => round((float) $use->sum('amount_paid'), 2),
            'owed' => round($owed, 2),
            'overdue' => round($overdue, 2),
            'overdue_count' => $overdueCount,
            'due_soon_count' => $dueSoon,
            'by_status' => $use->groupBy('status')->map->count()->all(),
        ];
    }

    /**
     * The marketer's page: his campaigns, what they bought, and how the platforms compare.
     *
     * @param  list<string>  $projectIds
     * @return array<string, mixed>
     */
    private function marketer(User $user, ?MetricsAggregator $agg, array $projectIds, Carbon $from, Carbon $to, Carbon $today, bool $money): array
    {
        $mineQuery = UnifiedCampaign::query()->whereIn('project_id', $projectIds)
            ->where(fn ($q) => $q->where('owner_id', $user->getKey())->orWhere('created_by', $user->getKey()));
        $mine = $projectIds === [] ? [] : $mineQuery->pluck('id')->map(fn ($id): string => (string) $id)->all();
        // Without any campaign of his own, the page reads the campaigns he can reach and says so.
        $own = $mine !== [];
        $ids = $own ? $mine : ($projectIds === [] ? [] : UnifiedCampaign::query()->whereIn('project_id', $projectIds)->pluck('id')->map(fn ($id): string => (string) $id)->all());

        $out = [
            'mine' => $own,
            'campaigns' => $this->campaignCounts($projectIds, $own ? $user : null),
            'campaign_rows' => [], 'families' => [], 'best' => [], 'worst' => [], 'platforms' => [],
        ];
        if ($agg === null || $ids === []) {
            return $out;
        }

        $scoped = $agg->forCampaigns($ids);
        $rows = $scoped->byCampaign($from, $to);
        // The detail page of a campaign is addressed by its project as well.
        $projectOf = UnifiedCampaign::query()->whereIn('id', $ids)->pluck('project_id', 'id')->map(fn ($v): string => (string) $v)->all();

        $out['campaign_rows'] = array_map(static fn (array $r): array => [
            'id' => (string) $r['campaign_id'],
            'project_id' => $projectOf[(string) $r['campaign_id']] ?? null,
            'name' => (string) $r['campaign_name'],
            'status' => $r['status'],
            'family' => (string) ($r['result_family'] ?? 'unknown'),
            'platform' => AdPlatforms::canonical((string) ($r['provider'] ?? '')),
            'spend' => $money && (int) ($r['spend_withheld_rows'] ?? 0) === 0 ? (float) $r['spend'] : null,
            'result' => $r['result'] ?? null,
            'result_label' => $r['result_label'] ?? null,
            'cost_per_result' => $money ? ($r['cost_per_result'] ?? null) : null,
            'cost_label' => $r['cost_per_result_label'] ?? null,
            'roas' => $money ? ($r['roas'] ?? null) : null,
        ], array_slice($rows, 0, 40));

        // Families by spend, each with the campaigns that can be ranked inside it.
        $families = [];
        foreach ($rows as $r) {
            $f = (string) ($r['result_family'] ?? 'unknown');
            if ($f === 'unknown') {
                continue;
            }
            $families[$f]['spend'] = ($families[$f]['spend'] ?? 0.0) + (float) ($r['spend'] ?? 0);
            $families[$f]['rows'][] = $r;
        }
        uasort($families, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        foreach ($families as $f => $info) {
            $out['families'][] = ['family' => $f, 'campaigns' => count($info['rows']), 'spend' => $money ? round($info['spend'], 2) : null];
        }

        $top = array_key_first($families);
        if ($top !== null && $money) {
            $ranked = array_values(array_filter($families[$top]['rows'], static fn (array $r): bool => ($r['result'] ?? null) !== null && (float) $r['result'] > 0 && ($r['cost_per_result'] ?? null) !== null));
            $sales = $top === 'sales';
            usort($ranked, static fn (array $a, array $b): int => $sales
                ? (float) ($b['roas'] ?? 0) <=> (float) ($a['roas'] ?? 0)
                : (float) $a['cost_per_result'] <=> (float) $b['cost_per_result']);
            $shape = static fn (array $r): array => [
                'id' => (string) $r['campaign_id'], 'project_id' => $projectOf[(string) $r['campaign_id']] ?? null, 'name' => (string) $r['campaign_name'], 'family' => $top,
                'cost_per_result' => $r['cost_per_result'], 'cost_label' => $r['cost_per_result_label'] ?? null,
                'result' => $r['result'], 'result_label' => $r['result_label'] ?? null, 'roas' => $sales ? ($r['roas'] ?? null) : null,
            ];
            if (count($ranked) >= 2) {
                $out['best'] = array_map($shape, array_slice($ranked, 0, 3));
                $out['worst'] = array_map($shape, array_slice(array_reverse($ranked), 0, 3));
            }
            $out['rank_family'] = $top;
            $out['platforms'] = $this->platformRows($scoped, $families[$top]['rows'], $top, $from, $to);
        }

        return $out;
    }

    /**
     * Platform comparison for the objective that carries the most spend: spend share, result and cost per result.
     *
     * @param  list<array<string, mixed>>  $familyRows
     * @return list<array<string, mixed>>
     */
    private function platformRows(MetricsAggregator $scoped, array $familyRows, string $family, Carbon $from, Carbon $to): array
    {
        $ids = array_map(static fn (array $r): string => (string) $r['campaign_id'], $familyRows);
        $objective = (string) ($familyRows[0]['objective'] ?? '');
        $inFamily = $scoped->forCampaigns($ids);
        $spendTotal = 0.0;
        $rows = [];
        foreach ($inFamily->byProvider($from, $to) as $p) {
            $key = AdPlatforms::canonical((string) $p['provider']);
            $totals = $inFamily->forProviders([(string) $p['provider']])->totals($from, $to);
            $res = $inFamily->forProviders([(string) $p['provider']])->resultOfTotals($objective === '' ? null : $objective, $totals, $from, $to);
            $withheld = (int) ($p['spend_withheld_rows'] ?? 0) > 0;
            $spend = $withheld ? null : (float) ($p['spend'] ?? 0);
            $spendTotal += $spend ?? 0.0;
            $rows[] = [
                'platform' => $key, 'spend' => $spend, 'result' => $res['result'] ?? null, 'result_label' => $res['result_label'] ?? null,
                'cost_per_result' => $res['cost_per_result'] ?? null, 'cost_label' => $res['cost_per_result_label'] ?? null,
                'roas' => $family === 'sales' ? ($p['roas'] ?? null) : null, 'ctr' => $p['ctr'] ?? null,
            ];
        }
        foreach ($rows as &$r) {
            $r['share'] = $r['spend'] !== null && $spendTotal > 0 ? round($r['spend'] / $spendTotal, 4) : null;
        }
        unset($r);
        usort($rows, static fn (array $a, array $b): int => AdPlatforms::rank($a['platform']) <=> AdPlatforms::rank($b['platform']));

        return $rows;
    }
}
