<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Resources\NotificationResource;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notification center. Users only ever see their OWN notifications within their tenant (double
 * scoping: tenant global scope + explicit user_id filter).
 */
final class NotificationController extends Controller
{
    /** The most rows either list returns; `meta.total` says how many there are. */
    private const MAX_ROWS = 100;

    public function index(Request $request): JsonResponse
    {
        $query = AppNotification::query()
            // The user's own notifications PLUS the team-wide operational alerts (budget risk, sync failure,
            // token expiry, …) raised with no specific recipient — but only those whose project / client the
            // reader may reach. SEC-1: `user_id IS NULL` used to be served to EVERY member of the tenant,
            // including a client-portal user of another client.
            ->where(fn ($q) => $this->visibleTo($q, $request->user()))
            ->where('type', '!=', 'suppressed') // hide delivery-ledger tombstones
            ->latest('created_at');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($project = $request->string('project_id')->toString()) {
            $query->where('project_id', $project);
        }

        $unread = (clone $query)->where('status', 'unread')->count();

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

        return ApiResponse::success(
            NotificationResource::collection($rows),
            'Notifications retrieved.',
            meta: [
                'unread' => $unread,
                'total' => $total,
                'withheld' => max(0, $total - $rows->count()),
            ],
        );
    }

    /**
     * The rows this reader may see: their own, plus the team-wide (no recipient) rows inside their reach.
     *
     * Deny by default. Team-wide rows carry campaign names, spend and client messages for ONE project or
     * client, so they are served only to the agency / app portals (never the client or influencer
     * portal), and only when the row's project is one the reader reaches. A row naming a client but no
     * project follows the client ceiling; a row naming neither is for readers with no ceiling at all.
     */
    private function visibleTo(Builder $q, User $user): void
    {
        $q->where('user_id', $user->id);

        $portal = app(MembershipContext::class)->portal();
        if ($portal !== Portal::Agency && $portal !== Portal::App) {
            return;
        }

        $projects = $this->reachableProjectIds($user);
        $clients = app(ClientScopeResolver::class)->reachableClientIds($user);

        $q->orWhere(function (Builder $team) use ($projects, $clients): void {
            $team->whereNull('user_id')->where(function (Builder $row) use ($projects, $clients): void {
                $row->where(function (Builder $p) use ($projects): void {
                    $p->whereNotNull('project_id');
                    if ($projects !== null) {
                        $p->whereIn('project_id', $projects);
                    }
                })->orWhere(function (Builder $c) use ($clients): void {
                    $c->whereNull('project_id')->whereNotNull('client_workspace_id');
                    if ($clients !== null) {
                        $c->whereIn('client_workspace_id', $clients);
                    }
                });

                if ($projects === null && $clients === null) {
                    $row->orWhere(fn (Builder $n) => $n->whereNull('project_id')->whereNull('client_workspace_id'));
                }
            });
        });
    }

    /**
     * Projects the reader may reach; NULL = no narrowing. The membership's named client / project
     * ceiling always applies; inside it `projects.view.all` reaches everything, anyone else only the
     * projects of a CURRENT membership (active, `expires_at` unset or in the future).
     *
     * @return list<string>|null
     */
    private function reachableProjectIds(User $user): ?array
    {
        $context = app(MembershipContext::class);
        $ceiling = null;
        if ($context->projectScopeIds() !== [] || $context->clientScopeIds() !== []) {
            $query = Project::withoutGlobalScopes()->where('tenant_id', $context->tenantId());
            if ($context->clientScopeIds() !== []) {
                $query->whereIn('client_workspace_id', $context->clientScopeIds());
            }
            if ($context->projectScopeIds() !== []) {
                $query->whereIn('id', $context->projectScopeIds());
            }
            $ceiling = $query->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        }

        if ($user->hasPermission('projects.view.all')) {
            return $ceiling;
        }

        $own = ProjectMembership::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId())
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('project_id')->map(static fn (mixed $id): string => (string) $id)->all();

        return $ceiling === null ? $own : array_values(array_intersect($own, $ceiling));
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->update(['status' => 'read', 'read_at' => now()]);

        return ApiResponse::success(new NotificationResource($notification), 'Notification marked read.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'unread')
            ->update(['status' => 'read', 'read_at' => now()]);

        return ApiResponse::success(null, 'All notifications marked read.');
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
