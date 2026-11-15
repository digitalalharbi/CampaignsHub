<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Followup\Models\FollowupTask;
use App\Domains\Notifications\Followup\Models\FollowupTaskEvent;
use App\Domains\Notifications\Services\DigestScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * NOTIF-V2-FOLLOWUP-001 — the follow-up task list (Owner spec §6).
 *
 * Tasks are opened automatically from catalog events and from the daily scan, each carrying the project, the reason, a
 * date and the button to the exact page. A person may snooze one or hand it to a colleague. It CLOSES BY ITSELF when the
 * thing it asked for happened (see `AutoCloser`). Every step is written to an append-only history: opened, closed, by whom.
 *
 * ## What this class never does
 * It never edits a budget, a spend limit, a campaign or an integration. It opens, assigns, snoozes and closes a TASK and
 * writes history — nothing else. `FollowupNeverEditsTest` holds that line.
 *
 * ## Who sees a task
 * Only internal members of the workspace, never a client-portal profile (these are operational). A task with a project is
 * visible to people who reach that project (`DigestScope`, the same ceiling the digests and alerts use), assigned or not — handing
 * a task over does not hide it from the person who handed it over — and always to its assignee; a task without a project only to
 * its assignee.
 */
final class FollowupTasks
{
    public const KINDS = ['renew_budget', 'set_budget', 'relink_platform', 'review_held_message', 'decide_recommendation'];

    /** catalog event => task kind */
    private const BY_EVENT = [
        'sync.failed' => 'relink_platform',
        'connection.expiring' => 'relink_platform',
        'connection.expired' => 'relink_platform',
        'external.held_for_review' => 'review_held_message',
        'recommendation.awaiting_approval' => 'decide_recommendation',
        'project.no_budget_for_period' => 'set_budget',
        'budget.threshold_100' => 'renew_budget',
        'budget.overrun' => 'renew_budget',
    ];

    public function __construct(
        private readonly EntitlementGate $gate,
        private readonly DigestScope $scope,
        private readonly WorkspaceTypes $types,
    ) {}

    public static function kindForEvent(string $eventKey): ?string
    {
        return self::BY_EVENT[$eventKey] ?? null;
    }

    public function enabled(string $tenantId): bool
    {
        return $this->gate->allows($tenantId, EntitlementKey::Tasks);
    }

    /** @param array<string, mixed> $payload */
    public function openForEvent(string $eventKey, string $tenantId, ?string $projectId, array $payload, ?string $objectType, ?string $objectId): ?FollowupTask
    {
        $kind = self::kindForEvent($eventKey);
        if ($kind === null) {
            return null;
        }
        // One standing task per project for these kinds, however the fact reached us (an event, the bell, the scan).
        if (in_array($kind, ['renew_budget', 'set_budget', 'relink_platform'], true) && $projectId !== null) {
            $objectType = 'project';
            $objectId = $projectId;
        }
        $params = array_filter($payload, static fn ($v): bool => is_scalar($v) && ! in_array($v, ['', null], true));
        if (in_array($eventKey, ['budget.threshold_100', 'budget.overrun'], true)) {
            $params['over'] = true;
        }

        return $this->open($tenantId, $kind, $projectId, $params, $objectType, $objectId, $payload['url'] ?? null, $eventKey);
    }

    /**
     * Open a task, or return the one already open for the same fact. Null when the plan has no follow-up list (data kept,
     * nothing new opened), or the project is not live.
     *
     * @param  array<string, mixed>  $params
     */
    public function open(
        string $tenantId,
        string $kind,
        ?string $projectId,
        array $params = [],
        ?string $objectType = null,
        ?string $objectId = null,
        ?string $actionUrl = null,
        ?string $eventKey = null,
        ?Carbon $dueOn = null,
    ): ?FollowupTask {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown follow-up kind {$kind}");
        }
        if (! $this->enabled($tenantId)) {
            return null;
        }
        if ($projectId !== null && ! ActiveProjectsGate::allows($tenantId, $projectId)) {
            return null;
        }

        $dedup = implode('|', [$kind, $projectId ?? '-', $objectId ?? '-']);
        $existing = FollowupTask::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('dedup_key', $dedup)->whereIn('status', ['open', 'snoozed'])->first();
        if ($existing instanceof FollowupTask) {
            return $existing;
        }

        try {
            $task = FollowupTask::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'project_id' => $projectId, 'kind' => $kind,
                'reason' => FollowupText::reason($kind, $params, 'ar'), 'params' => $params,
                'event_key' => $eventKey, 'object_type' => $objectType, 'object_id' => $objectId, 'dedup_key' => $dedup,
                'due_on' => ($dueOn ?? Carbon::today())->toDateString(), 'status' => 'open',
                'action_url' => $actionUrl ?? FollowupText::url($kind, $projectId), 'opened_at' => Carbon::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return FollowupTask::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('dedup_key', $dedup)->whereIn('status', ['open', 'snoozed'])->first();
        }
        $this->history($task, 'opened', null, ['event' => $eventKey]);

        return $task;
    }

    /**
     * The tasks this person may see (open, snoozed, done — the caller filters).
     *
     * @return Builder<FollowupTask>
     */
    public function visibleTo(User $user, string $tenantId): Builder
    {
        $query = FollowupTask::withoutGlobalScopes()->where('followup_tasks.tenant_id', $tenantId);
        if (! $this->mayUse($user, $tenantId)) {
            return $query->whereRaw('1 = 0');
        }

        $reach = $this->scope->projectIdsFor($user, $tenantId);

        return $query->where(function (Builder $q) use ($user, $reach): void {
            $q->where('assigned_user_id', $user->getKey());
            if ($reach !== []) {
                $q->orWhere(fn (Builder $open) => $open->whereNotNull('project_id')->whereIn('project_id', $reach));
            }
        });
    }

    /** May this person use the list at all: an internal member whose profile sees operational events. */
    public function mayUse(User $user, string $tenantId): bool
    {
        $member = DB::table('memberships')->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->where('status', 'active')
            ->whereIn('portal', ['agency', 'app'])->exists();

        return $member && $this->types->profileFor($tenantId, (int) $user->getKey())->seesSensitiveEvents;
    }

    public function find(User $user, string $tenantId, string $id): ?FollowupTask
    {
        $task = $this->visibleTo($user, $tenantId)->whereKey($id)->first();

        return $task instanceof FollowupTask ? $task : null;
    }

    public function snooze(FollowupTask $task, User $by, Carbon $until): FollowupTask
    {
        if (! $task->isLive()) {
            return $task;
        }
        $task->forceFill(['status' => 'snoozed', 'snoozed_until' => $until])->save();
        $this->history($task, 'snoozed', $by, ['until' => $until->toIso8601String()]);

        return $task;
    }

    /** Hand a task to a colleague who is an internal member of the workspace and reaches the task's project. */
    public function assign(FollowupTask $task, User $by, int $toUserId): FollowupTask
    {
        $tenantId = (string) $task->tenant_id;
        $to = User::query()->find($toUserId);
        if (! $to instanceof User || ! $this->mayUse($to, $tenantId)) {
            throw new InvalidArgumentException('assignee_not_eligible');
        }
        if ($task->project_id !== null && ! in_array((string) $task->project_id, $this->scope->ceilingIdsFor($to, $tenantId), true)) {
            throw new InvalidArgumentException('assignee_cannot_reach_project');
        }
        $task->forceFill(['assigned_user_id' => $toUserId])->save();
        $this->history($task, 'assigned', $by, ['to_user' => $toUserId, 'to_name' => (string) $to->name]);

        return $task;
    }

    /** Close a task. `$by` null = the system closed it because the thing happened. */
    public function close(FollowupTask $task, ?User $by, string $how = 'manual', ?string $why = null): FollowupTask
    {
        if (! $task->isLive()) {
            return $task;
        }
        $task->forceFill(['status' => 'done', 'closed_at' => Carbon::now(), 'closed_by' => $by?->getKey(), 'closed_how' => $how, 'snoozed_until' => null])->save();
        $this->history($task, 'closed', $by, ['how' => $how, 'why' => $why]);

        return $task;
    }

    /** Snoozed tasks whose time has come are open again. */
    public function wake(string $tenantId, ?Carbon $now = null): int
    {
        $ids = FollowupTask::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'snoozed')->where('snoozed_until', '<=', $now ?? Carbon::now())->pluck('id');
        foreach ($ids as $id) {
            $task = FollowupTask::withoutGlobalScopes()->find($id);
            if ($task instanceof FollowupTask) {
                $task->forceFill(['status' => 'open', 'snoozed_until' => null])->save();
                $this->history($task, 'reopened', null, ['why' => 'snooze_ended']);
            }
        }

        return $ids->count();
    }

    /** @param array<string, mixed> $detail */
    private function history(FollowupTask $task, string $action, ?User $by, array $detail = []): void
    {
        // Time-ordered id: two steps in the same second keep the order they happened in.
        (new FollowupTaskEvent)->forceFill([
            'id' => (string) Str::orderedUuid(),
            'tenant_id' => $task->tenant_id, 'task_id' => $task->getKey(), 'action' => $action,
            'actor_user_id' => $by?->getKey(), 'actor_label' => $by === null ? 'system' : mb_substr((string) $by->name, 0, 120),
            'detail' => array_filter($detail, static fn ($v): bool => $v !== null),
        ])->save();
    }
}
