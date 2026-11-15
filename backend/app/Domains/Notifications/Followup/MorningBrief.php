<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup;

use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Funding\Services\ProjectBudgetReader;
use App\Domains\Notifications\Followup\Models\FollowupTask;
use App\Domains\Notifications\Internal\ActiveProjects;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * NOTIF-V2-FOLLOWUP-001 — the content of one person's morning brief (Owner spec §6). The user's own projects only (the
 * ceiling the digests use), live projects only, and money only where they may see money.
 *
 * Reading only: it summarises and points; it never changes a budget, a campaign or a platform.
 */
final class MorningBrief
{
    /** «Nearing the end»: a budget covering this many days or fewer. */
    public const ENDING_WITHIN_DAYS = 7;

    public function __construct(
        private readonly DigestScope $scope,
        private readonly ProjectBudgetReader $budgets,
        private readonly ProjectAbilities $abilities,
        private readonly FollowupTasks $tasks,
    ) {}

    /**
     * @param  string  $timezone  the workspace's, so «yesterday» is the workspace's yesterday
     * @return array<string, mixed>
     */
    public function build(User $user, string $tenantId, Carbon $now, string $timezone): array
    {
        $reach = ActiveProjects::filter($tenantId, $this->scope->projectIdsFor($user, $tenantId));
        $names = $reach === [] ? collect() : DB::table('projects')->whereIn('id', $reach)->pluck('name', 'id');
        $money = array_values(array_filter($reach, fn (string $p): bool => $this->abilities->allows($user, $p, 'money.view')));

        $tasks = $this->tasks->visibleTo($user, $tenantId)->whereIn('status', ['open'])->orderBy('due_on')->orderBy('opened_at')->limit(50)->get();
        $ending = [];
        $noBudget = [];
        foreach ($this->budgets->forProjects($money, $now) as $projectId => $r) {
            if (($r['has_budget'] ?? false) !== true) {
                $noBudget[] = ['project_id' => (string) $projectId, 'project_name' => (string) ($names[$projectId] ?? '')];

                continue;
            }
            $days = $r['cover_days'] ?? null;
            if ($days !== null && (int) $days <= self::ENDING_WITHIN_DAYS && (float) ($r['remaining'] ?? 0) > 0) {
                $ending[] = ['project_id' => (string) $projectId, 'project_name' => (string) ($names[$projectId] ?? ''), 'days' => (int) $days, 'until' => $r['run_out_date'] ?? null];
            }
        }

        return [
            'tasks' => [
                'count' => $tasks->count(),
                'items' => $tasks->take(5)->map(static fn (FollowupTask $t): array => [
                    'id' => (string) $t->getKey(), 'kind' => $t->kind, 'params' => $t->params ?? [], 'project_id' => $t->project_id === null ? null : (string) $t->project_id,
                    'project_name' => $t->project_id === null ? null : (string) ($names[$t->project_id] ?? ''), 'due_on' => $t->due_on?->toDateString(),
                ])->values()->all(),
            ],
            'budgets_ending' => $ending,
            'no_budget' => $noBudget,
            'recommendations' => $reach === [] ? 0 : ActionProposal::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', ActionProposal::PENDING)->whereIn('project_id', $reach)->count(),
            'problems' => $this->problems($tenantId, $reach, $names),
            'external_yesterday' => $this->externalYesterday($tenantId, $reach, $now, $timezone),
        ];
    }

    /**
     * @param  array<string, mixed>  $sections
     */
    public function summary(array $sections, string $locale): string
    {
        $parts = [];
        $t = static fn (string $k, array $r = []): string => (string) __('notifications_v2.followup.brief.'.$k, $r, $locale);
        if (($n = (int) $sections['tasks']['count']) > 0) {
            $parts[] = $t('tasks', ['n' => $n]);
        }
        if (($n = count($sections['budgets_ending'])) > 0) {
            $parts[] = $t('budgets_ending', ['n' => $n]);
        }
        if (($n = count($sections['no_budget'])) > 0) {
            $parts[] = $t('no_budget', ['n' => $n]);
        }
        if (($n = (int) $sections['recommendations']) > 0) {
            $parts[] = $t('recommendations', ['n' => $n]);
        }
        if (($n = count($sections['problems'])) > 0) {
            $parts[] = $t('problems', ['n' => $n]);
        }
        $x = $sections['external_yesterday'];
        if ($x['sent'] + $x['held'] + $x['unsubscribed'] > 0) {
            $parts[] = $t('external', ['sent' => $x['sent'], 'held' => $x['held'], 'unsub' => $x['unsubscribed']]);
        }

        return $parts === [] ? $t('nothing') : implode(' · ', $parts);
    }

    /**
     * Projects whose latest sync failed, or that have an open «relink» task.
     *
     * @param  list<string>  $reach
     * @return list<array{project_id: string, project_name: string}>
     */
    private function problems(string $tenantId, array $reach, $names): array
    {
        if ($reach === []) {
            return [];
        }
        $failed = DB::table('integration_sync_runs as r')->where('r.tenant_id', $tenantId)->whereIn('r.project_id', $reach)
            ->whereRaw('r.created_at = (select max(x.created_at) from integration_sync_runs x where x.project_id = r.project_id and x.tenant_id = r.tenant_id)')
            ->where('r.status', 'failed')->pluck('r.project_id');
        $relink = FollowupTask::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('kind', 'relink_platform')->whereIn('status', ['open', 'snoozed'])
            ->whereIn('project_id', $reach)->pluck('project_id');

        return $failed->merge($relink)->unique()->map(static fn ($id): array => ['project_id' => (string) $id, 'project_name' => (string) ($names[$id] ?? '')])->values()->all();
    }

    /**
     * Yesterday's external messages for the user's projects: sent / held / unsubscribed.
     *
     * @param  list<string>  $reach
     * @return array{sent: int, held: int, unsubscribed: int}
     */
    private function externalYesterday(string $tenantId, array $reach, Carbon $now, string $timezone): array
    {
        $out = ['sent' => 0, 'held' => 0, 'unsubscribed' => 0];
        if ($reach === []) {
            return $out;
        }
        $from = $now->copy()->setTimezone($timezone)->subDay()->startOfDay()->setTimezone('UTC');
        $to = $from->copy()->addDay();
        $rows = DB::table('notification_delivery_log')->where('tenant_id', $tenantId)->where('scope', 'external')->whereIn('project_id', $reach)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $out['sent'] = (int) ($rows['sent'] ?? 0);
        $out['held'] = (int) ($rows['held_for_review'] ?? 0) + (int) ($rows['held_for_privacy'] ?? 0);
        $out['unsubscribed'] = DB::table('notification_subscription_log')->where('tenant_id', $tenantId)->where('action', 'unsubscribed')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)->whereRaw("(detail->>'project_id') in (".implode(',', array_fill(0, count($reach), '?')).')', $reach)->count();

        return $out;
    }
}
