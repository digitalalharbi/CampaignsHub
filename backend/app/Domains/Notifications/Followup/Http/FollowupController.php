<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup\Http;

use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use App\Domains\Notifications\Followup\FollowupTasks;
use App\Domains\Notifications\Followup\FollowupText;
use App\Domains\Notifications\Followup\Models\FollowupBrief;
use App\Domains\Notifications\Followup\Models\FollowupTask;
use App\Domains\Notifications\Followup\Models\FollowupTaskEvent;
use App\Domains\Notifications\Followup\Models\FollowupUserSettings;
use App\Domains\Notifications\Followup\ReportRerouter;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Notifications\Services\NotificationInbox;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * NOTIF-V2-FOLLOWUP-001 — the follow-up assistant's API (Owner spec §6). Workspace-level: one controller for an agency, a direct
 * business and any future type; the words of «project» come from the workspace-type profile, never from a type check here.
 *
 * Every route is behind `plan.feature:followup.tasks` (or `.morning_brief`) so a plan without the key gets 403 + `upgrade: true`
 * from the server, with the data kept. Nothing here edits a budget, a limit, a campaign or an integration.
 */
final class FollowupController extends Controller
{
    public function __construct(
        private readonly FollowupTasks $tasks,
        private readonly TenantContext $tenants,
        private readonly WorkspaceSettingsRepository $settings,
        private readonly WorkspaceTypes $types,
        private readonly DigestScope $scope,
        private readonly NotificationInbox $inbox,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->tenantId();
        $this->tasks->wake($tenantId);

        $status = $request->string('status', 'open')->toString();
        $query = $this->tasks->visibleTo($user, $tenantId)->with([])->orderByRaw("case status when 'open' then 0 when 'snoozed' then 1 else 2 end")->orderBy('due_on')->orderBy('opened_at');
        match ($status) {
            'all' => null,
            'done' => $query->where('status', 'done'),
            'snoozed' => $query->where('status', 'snoozed'),
            default => $query->where('status', 'open'),
        };
        if ($project = $request->string('project_id')->toString()) {
            $query->where('project_id', $project);
        }

        $counts = $this->tasks->visibleTo($user, $tenantId)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $rows = $query->limit(200)->get();

        return ApiResponse::success($this->present($rows, $user), 'Follow-up tasks retrieved.', meta: [
            'counts' => ['open' => (int) ($counts['open'] ?? 0), 'snoozed' => (int) ($counts['snoozed'] ?? 0), 'done' => (int) ($counts['done'] ?? 0)],
            'terms' => $this->terms($tenantId, $user),
            'workspace' => $this->settings->for($tenantId)->workspaceName,
        ]);
    }

    public function show(Request $request, string $task): JsonResponse
    {
        $row = $this->task($request, $task);
        $history = FollowupTaskEvent::withoutGlobalScopes()->where('tenant_id', $row->tenant_id)->where('task_id', $row->getKey())->orderBy('created_at')->orderBy('id')->get();
        $ar = $this->ar($request->user());

        return ApiResponse::success($this->present(collect([$row]), $request->user())[0] + ['history' => $history->map(static fn (FollowupTaskEvent $e): array => [
            'action' => $e->action, 'label' => FollowupText::action($e->action, $ar ? 'ar' : 'en'),
            'by' => $e->actor_user_id === null ? FollowupText::action('system', $ar ? 'ar' : 'en') : (string) $e->actor_label,
            'at' => $e->created_at?->toIso8601String(), 'detail' => $e->detail ?? [],
        ])->all()], 'Follow-up task retrieved.');
    }

    public function snooze(Request $request, string $task): JsonResponse
    {
        $data = $request->validate(['preset' => ['required', 'in:1h,tomorrow,next_week']]);
        $row = $this->task($request, $task);
        $tz = $this->settings->for($this->tenantId())->timezone;
        $until = match ($data['preset']) {
            '1h' => Carbon::now()->addHour(),
            'tomorrow' => Carbon::now($tz)->addDay()->setTime(8, 0)->setTimezone('UTC'),
            default => Carbon::now($tz)->addWeek()->setTime(8, 0)->setTimezone('UTC'),
        };
        $this->tasks->snooze($row, $request->user(), $until);

        return ApiResponse::success($this->present(collect([$row->refresh()]), $request->user())[0], 'Task snoozed.');
    }

    public function assign(Request $request, string $task): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $row = $this->task($request, $task);
        try {
            $this->tasks->assign($row, $request->user(), (int) $data['user_id']);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error('Assignee not eligible.', ['user_id' => [$e->getMessage()]], status: 422);
        }

        return ApiResponse::success($this->present(collect([$row->refresh()]), $request->user())[0], 'Task assigned.');
    }

    /** A person closes a task themselves («this is handled elsewhere»); the history says it was by them, not by the system. */
    public function close(Request $request, string $task): JsonResponse
    {
        $row = $this->task($request, $task);
        $this->tasks->close($row, $request->user(), 'manual', mb_substr((string) $request->input('why', ''), 0, 200) ?: null);

        return ApiResponse::success($this->present(collect([$row->refresh()]), $request->user())[0], 'Task closed.');
    }

    /** Colleagues the task may be handed to: internal members of this workspace who reach its project. */
    public function assignees(Request $request, string $task): JsonResponse
    {
        $row = $this->task($request, $task);
        $tenantId = $this->tenantId();
        $ids = DB::table('memberships')->where('tenant_id', $tenantId)->where('status', 'active')->whereIn('portal', ['agency', 'app'])->pluck('user_id')->unique();
        $out = [];
        foreach (User::query()->whereIn('id', $ids)->orderBy('name')->get() as $u) {
            if (! $this->tasks->mayUse($u, $tenantId)) {
                continue;
            }
            if ($row->project_id !== null && ! in_array((string) $row->project_id, $this->scope->ceilingIdsFor($u, $tenantId), true)) {
                continue;
            }
            $out[] = ['id' => (int) $u->getKey(), 'name' => (string) $u->name];
        }

        return ApiResponse::success($out, 'Assignees retrieved.');
    }

    public function brief(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $date = $request->string('date')->toString();
        $q = FollowupBrief::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $request->user()->getKey());
        $brief = $date !== '' ? $q->whereDate('brief_date', $date)->first() : $q->orderByDesc('brief_date')->first();

        return ApiResponse::success($brief instanceof FollowupBrief ? [
            'date' => $brief->brief_date->toDateString(), 'summary' => $brief->summary, 'sections' => $brief->sections,
        ] : null, 'Morning brief retrieved.', meta: ['terms' => $this->terms($tenantId, $request->user())]);
    }

    public function settings(Request $request): JsonResponse
    {
        return ApiResponse::success($this->settingsOf($request->user()), 'Follow-up settings retrieved.');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brief_enabled' => ['sometimes', 'boolean'],
            'brief_time' => ['sometimes', 'date_format:H:i'],
            'brief_channels' => ['sometimes', 'array', 'min:1'],
            'brief_channels.*' => ['in:in_app,email'],
        ]);
        if (isset($data['brief_channels'])) {
            $data['brief_channels'] = array_values(array_unique($data['brief_channels']));
        }
        FollowupUserSettings::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $this->tenantId(), 'user_id' => $request->user()->getKey()], $data);

        return ApiResponse::success($this->settingsOf($request->user()), 'Follow-up settings saved.');
    }

    /** Recipients an item can be rerouted to: confirmed, and subscribed to the item's project. */
    public function rerouteRecipients(Request $request, ReportRerouter $rerouter): JsonResponse
    {
        $row = $this->notification($request, (string) $request->query('notification_id'));
        $tenantId = $this->tenantId();
        if ($row->project_id === null || ! $rerouter->mayReroute((string) $row->kind)) {
            return ApiResponse::success(['allowed' => false, 'recipients' => []], 'Not reroutable.');
        }
        $rows = DB::table('notification_external_recipients as r')
            ->join('notification_subscriptions as s', 's.recipient_id', '=', 'r.id')->join('notification_subscription_projects as p', 'p.subscription_id', '=', 's.id')
            ->where('r.tenant_id', $tenantId)->where('s.tenant_id', $tenantId)->where('p.tenant_id', $tenantId)
            ->where('r.status', 'active')->where('s.status', 'active')->where('p.project_id', $row->project_id)
            ->distinct()->orderBy('r.email')->get(['r.id', 'r.name', 'r.email', 'r.role_key']);

        return ApiResponse::success(['allowed' => true, 'recipients' => $rows->map(static fn ($r): array => ['id' => (string) $r->id, 'name' => $r->name, 'email' => (string) $r->email, 'role' => (string) $r->role_key])->all()], 'Recipients retrieved.');
    }

    public function reroute(Request $request, ReportRerouter $rerouter): JsonResponse
    {
        $data = $request->validate(['notification_id' => ['required', 'uuid'], 'recipient_id' => ['required', 'uuid']]);
        $row = $this->notification($request, $data['notification_id']);
        $out = $rerouter->reroute($request->user(), $row, $data['recipient_id'], $this->tenantId());

        return ApiResponse::success($out + ['message' => __('notifications_v2.followup.reroute.queued')], 'Report queued for the recipient.', status: 202);
    }

    private function tenantId(): string
    {
        return (string) $this->tenants->tenantId();
    }

    private function ar(User $user): bool
    {
        return ($user->locale ?? 'ar') !== 'en';
    }

    private function task(Request $request, string $id): FollowupTask
    {
        $row = $this->tasks->find($request->user(), $this->tenantId(), $id);
        abort_unless($row instanceof FollowupTask, 404);

        return $row;
    }

    private function notification(Request $request, string $id): AppNotification
    {
        $row = $this->inbox->visible($request->user())->whereKey($id)->first();
        abort_unless($row instanceof AppNotification, 404);

        return $row;
    }

    /** @return array{project: string, client: string} the workspace type's words, in the reader's language */
    private function terms(string $tenantId, User $user): array
    {
        $profile = $this->types->profileFor($tenantId, (int) $user->getKey());
        $locale = $this->ar($user) ? 'ar' : 'en';

        return [
            'project' => (string) __($profile->terminology['project'] ?? 'notifications_v2.terms.agency.project', [], $locale),
            'client' => (string) __($profile->terminology['client'] ?? 'notifications_v2.terms.agency.client', [], $locale),
        ];
    }

    /** @return array<string, mixed> */
    private function settingsOf(User $user): array
    {
        $row = FollowupUserSettings::withoutGlobalScopes()->where('tenant_id', $this->tenantId())->where('user_id', $user->getKey())->first();

        return [
            'brief_enabled' => $row->brief_enabled ?? true,
            'brief_time' => $row->brief_time ?? '08:00',
            'brief_channels' => $row->brief_channels ?? ['in_app'],
            'timezone' => $this->settings->for($this->tenantId())->timezone,
        ];
    }

    /**
     * @param  Collection<int, FollowupTask>  $rows
     * @return list<array<string, mixed>>
     */
    private function present($rows, User $user): array
    {
        $locale = $this->ar($user) ? 'ar' : 'en';
        $names = DB::table('projects')->whereIn('id', $rows->pluck('project_id')->filter()->unique()->all())->pluck('name', 'id');
        $people = DB::table('users')->whereIn('id', $rows->pluck('assigned_user_id')->filter()->unique()->all())->pluck('name', 'id');
        $workspace = $this->settings->for($this->tenantId())->workspaceName;

        return $rows->map(static fn (FollowupTask $t): array => [
            'id' => (string) $t->getKey(), 'kind' => $t->kind, 'title' => FollowupText::kind($t->kind, $locale),
            'reason' => FollowupText::reason($t->kind, $t->params ?? [], $locale),
            'params' => $t->params ?? [], 'status' => $t->status,
            'project_id' => $t->project_id === null ? null : (string) $t->project_id,
            'project_name' => $t->project_id === null ? null : (string) ($names[$t->project_id] ?? ''), 'workspace_name' => $workspace,
            'due_on' => $t->due_on?->toDateString(), 'snoozed_until' => $t->snoozed_until?->toIso8601String(),
            'assigned_user_id' => $t->assigned_user_id, 'assigned_name' => $t->assigned_user_id === null ? null : (string) ($people[$t->assigned_user_id] ?? ''),
            'action_url' => $t->action_url, 'opened_at' => $t->opened_at?->toIso8601String(),
            'closed_at' => $t->closed_at?->toIso8601String(), 'closed_how' => $t->closed_how,
        ])->values()->all();
    }
}
