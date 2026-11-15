<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Support\BellKind;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * NOTIF-BELL-001 — one reader's inbox: what they may see, what is unread FOR THEM, and how it is cleared.
 *
 * ## Why «read» is per person
 *
 * A team-wide notification (an alert raised with no recipient) is ONE row seen by every member who reaches its
 * project. `markAllRead` used to update only rows addressed to the reader, so those rows stayed `unread` forever and
 * the bell's count — which counted them — could only go up: 345 unread, «mark all read», still 345.
 *
 * A row addressed to somebody keeps its own `status`. A team-wide row is read by a person through
 * `notification_reads`. The unread count and the clearing both go through this class, so they cannot disagree.
 *
 * ## Visibility is unchanged (deny by default)
 *
 * It is the rule `NotificationController` always applied, moved here so the push sender asks the same question:
 * the reader's own rows, plus team-wide rows only for the agency / app portals and only inside the reader's reach.
 */
final class NotificationInbox
{
    /** Rows written to `notification_reads` per statement. */
    private const CHUNK = 500;

    /**
     * Every notification this reader may see, newest first is the caller's choice.
     *
     * @return Builder<AppNotification>
     */
    public function visible(User $user): Builder
    {
        return AppNotification::query()
            ->where(fn (Builder $q) => $this->visibleTo($q, $user))
            ->where('type', '!=', 'suppressed'); // hide delivery-ledger tombstones
    }

    /**
     * Narrow a visible query to what is unread for this reader.
     *
     * @param  Builder<AppNotification>  $query
     * @return Builder<AppNotification>
     */
    public function unread(Builder $query, User $user): Builder
    {
        return $query->where('status', 'unread')
            ->whereNotExists(static fn ($s) => $s->select(DB::raw(1))->from('notification_reads as r')
                ->whereColumn('r.notification_id', 'app_notifications.id')->where('r.user_id', $user->id));
    }

    /** The real number: no cap, no page. */
    public function unreadCount(User $user): int
    {
        return $this->unread($this->visible($user), $user)->count();
    }

    /**
     * Narrow to a panel filter: `critical` is by severity; the others by the finding's kind.
     *
     * @param  Builder<AppNotification>  $query
     * @return Builder<AppNotification>
     */
    public function inGroup(Builder $query, string $group): Builder
    {
        if ($group === 'critical') {
            return $query->whereIn('severity', ['critical', 'error']);
        }
        if (! in_array($group, BellKind::GROUPS, true)) {
            return $query;
        }

        return $query->whereIn('kind', BellKind::kindsOf($group));
    }

    public function markRead(User $user, AppNotification $notification): void
    {
        if ($notification->user_id !== null) {
            $notification->forceFill(['status' => 'read', 'read_at' => now()])->save();

            return;
        }

        $this->recordReads($user, [(string) $notification->id], (string) $notification->tenant_id);
    }

    /**
     * Every unread row this reader can see, in one call — not the page the panel happened to load.
     *
     * @return int how many rows were cleared
     */
    public function markAllRead(User $user): int
    {
        $own = AppNotification::query()->where('user_id', $user->id)->where('status', 'unread')
            ->update(['status' => 'read', 'read_at' => now()]);

        $team = 0;
        $this->unread($this->visible($user)->whereNull('user_id'), $user)
            ->select(['app_notifications.id', 'app_notifications.tenant_id'])
            ->chunkById(self::CHUNK, function ($rows) use ($user, &$team): void {
                foreach ($rows->groupBy('tenant_id') as $tenant => $group) {
                    $team += $this->recordReads($user, $group->pluck('id')->map(static fn ($id): string => (string) $id)->all(), (string) $tenant);
                }
            }, 'app_notifications.id', 'id');

        return $own + $team;
    }

    /**
     * Close a row because the thing it was about is over — the linked alert closed.
     *
     * Status becomes `resolved`, which leaves the unread count for everyone it was team-wide for.
     */
    public function resolve(string $notificationId): void
    {
        AppNotification::withoutGlobalScopes()->whereKey($notificationId)->where('status', '!=', 'resolved')
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    /**
     * The alert closed: its notification closes too, and so does the same finding raised on an earlier day.
     *
     * Matched on the alert's own notification id, and on source `alerts` + the same object + the same KIND — never on
     * the object alone, because one campaign can carry two different findings and only one of them has ended. A
     * recovery notice is raised AFTER the alert closes, so it is never swept up here.
     */
    public function closeForAlert(AlertEvent $event): void
    {
        $kind = BellKind::forAlert((string) $event->type, (array) ($event->context ?? []));

        // Two rules can raise the same finding about the same object and share its notification; it stays while either is open.
        $stillOpen = $kind !== null && $event->entity_id !== null && AlertEvent::withoutGlobalScopes()
            ->where('tenant_id', $event->tenant_id)->where('entity_type', $event->entity_type)->where('entity_id', (string) $event->entity_id)
            ->whereIn('status', ['open', 'snoozed'])->whereKeyNot($event->getKey())->get(['type', 'context'])
            ->contains(static fn ($e): bool => BellKind::forAlert((string) $e->type, (array) ($e->context ?? [])) === $kind);
        if ($stillOpen) {
            return;
        }

        AppNotification::withoutGlobalScopes()
            ->where('tenant_id', $event->tenant_id)
            ->where('status', '!=', 'resolved')
            ->where(function (Builder $q) use ($event, $kind): void {
                if ($event->notification_id !== null) {
                    $q->orWhere('id', $event->notification_id);
                }
                if ($kind !== null && $event->entity_id !== null && $kind !== 'recovered') {
                    $q->orWhere(fn (Builder $same) => $same->where('source', 'alerts')->where('kind', $kind)
                        ->where('entity_type', $event->entity_type)->where('entity_id', (string) $event->entity_id));
                }
            })
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    /**
     * @param  list<string>  $ids
     */
    private function recordReads(User $user, array $ids, string $tenantId): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table('notification_reads')->insertOrIgnore(array_map(
            static fn (string $id): array => ['notification_id' => $id, 'user_id' => $user->id, 'tenant_id' => $tenantId, 'read_at' => now()],
            $ids,
        ));
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
}
