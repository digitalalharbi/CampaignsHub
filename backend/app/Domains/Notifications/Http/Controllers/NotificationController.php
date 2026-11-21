<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Notifications\Internal\EngineKinds;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Resources\NotificationResource;
use App\Domains\Notifications\Services\NotificationInbox;
use App\Domains\Notifications\Services\SoundPreferences;
use App\Domains\Notifications\Support\BellKind;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notification center. Users only ever see their OWN notifications within their tenant (double
 * scoping: tenant global scope + explicit user_id filter).
 */
final class NotificationController extends Controller
{
    public function __construct(private readonly NotificationInbox $inbox) {}

    /** The most rows either list returns; `meta.total` says how many there are. */
    private const MAX_ROWS = 100;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $this->inbox->visible($user)->latest('created_at');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($project = $request->string('project_id')->toString()) {
            $query->where('project_id', $project);
        }
        // NOTIF-V2-INTERNAL-001 — the centre's tabs and filters, all server-side so the count and the rows agree.
        if ($request->string('tab')->toString() === 'unread') {
            $this->inbox->unread($query, $user);
        }
        if ($type = $request->string('type')->toString()) {
            $query->whereIn('kind', EngineKinds::aliases($type));
        }
        if ($priority = $request->string('priority')->toString()) {
            $query->whereIn('severity', match ($priority) {
                'critical' => ['critical', 'error'],
                'important' => ['warning'],
                default => ['info', 'success'],
            });
        }
        // NOTIF-BELL-001 — the panel's filters (حرج / ميزانية / أداء / مزامنة), applied on the server so the count and the rows agree.
        if ($group = $request->string('group')->toString()) {
            $this->inbox->inGroup($query, $group);
        }

        /*
         * The bell's number is the REAL unread count for this reader — every notification they can see that they
         * have not read, not the rows of this page and not a capped figure. It does not follow the panel filter:
         * a filter narrows the list, it must not change how much is waiting.
         */
        $unread = $this->inbox->unreadCount($user);

        /*
         * OPS-LEDGER-001 — the bound is stated, because `unread` never stated it.
         *
         * An unread COUNT is not a statement of completeness. A reader with three hundred
         * notifications, ninety of them unread, met a hundred rows beside «unread: 90» and had
         * nothing on the page telling them two hundred more existed — the count answered a question
         * they had not asked while the one they had asked went unanswered.
         *
         * Counted on a clone, before the limit, under the same filters the rows were read with.
         */
        $total = (clone $query)->count();
        $rows = $query->limit(self::MAX_ROWS)->get();

        /*
         * DIGESTS-CADENCE-BUDGETS-001 — the bell draws an alert the way its email does (chip, the problem drawn, the steps).
         *
         * The figures live on the alert event, not on the notification row; one read for the whole page, keyed by the
         * notification that event raised, and only events of this tenant — the rows above were already bounded to the reader.
         */
        $events = AlertEvent::query()
            ->whereIn('notification_id', $rows->pluck('id')->all())
            ->get(['id', 'notification_id', 'type', 'severity', 'context'])
            ->keyBy('notification_id');
        $readByMe = DB::table('notification_reads')->where('user_id', $user->id)
            ->whereIn('notification_id', $rows->pluck('id')->all())->pluck('notification_id')->flip();
        $projectNames = DB::table('projects')->whereIn('id', $rows->pluck('project_id')->filter()->unique()->all())->pluck('name', 'id');
        $workspace = (string) (DB::table('tenants')->where('id', app(TenantContext::class)->tenantId())->value('name') ?? '');
        $rows->each(static function (AppNotification $n) use ($events, $readByMe, $projectNames, $workspace): void {
            $n->setRelation('alertEvent', $events->get($n->id));
            $n->setAttribute('read_by_me', $readByMe->has($n->id));
            $n->setAttribute('project_name', $n->project_id === null ? null : ($projectNames[$n->project_id] ?? null));
            $n->setAttribute('workspace_name', $workspace);
        });

        return ApiResponse::success(
            NotificationResource::collection($rows),
            'Notifications retrieved.',
            meta: [
                'unread' => $unread,
                'total' => $total,
                'withheld' => max(0, $total - $rows->count()),
                'facets' => $this->facets($user, ($user->locale ?? 'ar') !== 'en'),
            ],
        );
    }

    /**
     * What the filters can offer, from everything this reader can see (not from the page): their projects and the kinds present.
     *
     * @return array{projects: list<array{id: string, name: string}>, kinds: list<array{kind: string, label: string}>}
     */
    private function facets(User $user, bool $ar): array
    {
        $base = $this->inbox->visible($user);
        $ids = (clone $base)->whereNotNull('project_id')->distinct()->limit(60)->pluck('project_id')->all();
        $projects = DB::table('projects')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(static fn ($p): array => ['id' => (string) $p->id, 'name' => (string) $p->name])->all();
        $kinds = (clone $base)->whereNotNull('kind')->distinct()->limit(60)->pluck('kind')->map(static function ($k) use ($ar): ?array {
            $label = BellKind::label((string) $k, $ar);

            return $label === null ? null : ['kind' => (string) $k, 'label' => $label];
        })->filter()->values()->all();

        return ['projects' => $projects, 'kinds' => $kinds];
    }

    /**
     * The bell's live figure, cheap enough to ask every half minute: the real unread count, the ids of unread
     * notifications that warrant a sound (critical or warning), and whether the sound may play for this reader now. One small query set; no rows are serialised.
     */
    public function summary(Request $request, SoundPreferences $sound): JsonResponse
    {
        $user = $request->user();
        $unread = $this->inbox->unread($this->inbox->visible($user), $user);

        $attention = (clone $unread)->whereIn('severity', ['critical', 'error', 'warning'])
            ->latest('created_at')->limit(20)->get(['id', 'severity'])
            ->map(static fn (AppNotification $n): array => [
                'id' => (string) $n->id,
                'severity' => $n->severity === 'error' ? 'critical' : (string) $n->severity,
            ])->all();

        return ApiResponse::success([
            'unread' => $unread->count(),
            'attention' => $attention,
            // NOTIF-SOUND-001 — whether a ping may play for this reader right now (their setting and their quiet hours).
            'sound' => $sound->forReader($user),
        ], 'Notification summary retrieved.');
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        $user = $request->user();
        // Deny by default: only a notification the reader may SEE can be cleared by them (a team-wide row included).
        abort_unless($this->inbox->visible($user)->whereKey($notification->id)->exists(), 403);

        $this->inbox->markRead($user, $notification);
        $notification->setAttribute('read_by_me', true);

        return ApiResponse::success(new NotificationResource($notification->refresh()->setAttribute('read_by_me', true)), 'Notification marked read.');
    }

    /** Everything unread for this reader, server-side and in one call — not just the rows the panel loaded. */
    public function markAllRead(Request $request): JsonResponse
    {
        $cleared = $this->inbox->markAllRead($request->user());

        return ApiResponse::success(['cleared' => $cleared, 'unread' => $this->inbox->unreadCount($request->user())], 'All notifications marked read.');
    }

    /**
     * Per-channel delivery log for the current user's notifications — the transparency surface for
     * queued / awaiting_credentials / sent / failed / retrying / suppressed states.
     */
    public function deliveries(Request $request): JsonResponse
    {
        $rows = DB::table('notification_deliveries')
            ->join('app_notifications as n', 'n.id', '=', 'notification_deliveries.notification_id')
            ->where('n.user_id', $request->user()->id)
            ->orderByDesc('notification_deliveries.created_at')
            ->limit(100)
            ->get([
                'notification_deliveries.id', 'notification_deliveries.channel', 'notification_deliveries.status',
                'notification_deliveries.attempts', 'notification_deliveries.created_at', 'n.type', 'n.title',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'channel' => $r->channel,
                'status' => $r->status,
                'attempts' => (int) $r->attempts,
                'type' => $r->type,
                'title' => $r->title,
                'created_at' => optional($r->created_at)->toIso8601String(),
            ]);

        return ApiResponse::success($rows, 'Delivery log retrieved.');
    }
}
