<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

use App\Domains\Access\Support\AccessSections;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Access\ProjectReach;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * NOTIF-PUSH-001 — who a push may reach: only people who could already read this notification in the product.
 *
 * Deny by default, every condition re-checked at send time (a membership revoked a minute ago is not pushed):
 *
 *   1. they have at least one device subscribed;
 *   2. an ACTIVE internal membership (agency or app portal) in the notification's tenant — never a client-portal login;
 *   3. the project is inside their reach — the SAME resolver the product itself uses (`ProjectReach`: the membership's named client /
 *      project ceiling, `projects.view.all`, or a current project membership) with their own membership bound the way a request binds
 *      it — and, for a budget figure, they hold `money.view` there; a notification with no project reaches nobody but its addressee;
 *   4. the section is not switched off for them (the access centre's «الأقسام»);
 *   5. they did not switch this push type off.
 *
 * A notification addressed to ONE person goes to that person alone, through the same checks.
 */
final class PushAudience
{
    public function __construct(private readonly ProjectReach $reach, private readonly ProjectAbilities $abilities) {}

    /**
     * @return list<User>
     */
    public function for(AppNotification $n, string $pushType): array
    {
        $tenantId = (string) $n->tenant_id;
        $candidates = $n->user_id !== null ? [(int) $n->user_id] : $this->subscribersIn($tenantId);
        if ($candidates === []) {
            return [];
        }

        $off = $this->sectionsOff($tenantId, $candidates);
        $chosen = DB::table('notification_alert_settings')->whereIn('user_id', $candidates)->pluck('push_types', 'user_id');
        $section = PushTypes::section($n);

        return User::query()->whereIn('id', $candidates)->get()->filter(function (User $user) use ($n, $pushType, $tenantId, $off, $chosen, $section): bool {
            $id = (int) $user->getKey();
            if (! (PushTypes::chosen($this->decode($chosen[$id] ?? null))[$pushType] ?? false)) {
                return false;
            }
            if ($section !== null && in_array($section, $off[$id] ?? [], true)) {
                return false;
            }

            return $this->reaches($user, $n, $tenantId);
        })->values()->all();
    }

    /** The portal path prefix a person opens: `/agency` if they hold an agency membership here, else `/app`. */
    public function prefixFor(User $user, string $tenantId): string
    {
        $agency = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->where('status', 'active')
            ->where('portal', Portal::Agency->value)->exists();

        return $agency ? '/agency' : '/app';
    }

    /** @return list<int> people with a device, who hold an internal membership in the tenant */
    private function subscribersIn(string $tenantId): array
    {
        $withDevice = DB::table('push_subscriptions')->distinct()->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
        if ($withDevice === []) {
            return [];
        }

        return Membership::query()->where('tenant_id', $tenantId)->where('status', 'active')
            ->whereIn('portal', [Portal::App->value, Portal::Agency->value])->whereIn('user_id', $withDevice)
            ->pluck('user_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
    }

    private function reaches(User $user, AppNotification $n, string $tenantId): bool
    {
        // Internal membership, even for an addressed notification: a client-portal login is never pushed.
        $membership = Membership::query()->with('scopes')->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->where('status', 'active')
            ->whereIn('portal', [Portal::Agency->value, Portal::App->value])->orderByRaw("case portal when 'agency' then 0 else 1 end")->first();
        if ($membership === null || DB::table('push_subscriptions')->where('user_id', $user->getKey())->doesntExist()) {
            return false;
        }

        $projectId = $n->project_id === null ? null : (string) $n->project_id;
        if ($projectId === null) {
            return $n->user_id !== null && (int) $n->user_id === (int) $user->getKey();
        }

        return $this->asMember($membership, function () use ($user, $n, $projectId): bool {
            if (! $this->reach->canReach($user, $projectId)) {
                return false;
            }

            return $this->abilities->allows($user, $projectId, PushTypes::isMoney($n) ? ProjectCapability::MONEY_VIEW : ProjectCapability::DASHBOARD_VIEW);
        });
    }

    /**
     * Run a check with this person's membership bound, as a request binds it — a queue worker has none.
     *
     * The resolver reads the tenant and the membership's named ceiling from these two contexts; without them it would judge
     * a stranger. Whatever was bound before is put back, so a push sent inside a request cannot change that request's reader.
     *
     * @template T
     *
     * @param  callable(): T  $check
     * @return T
     */
    private function asMember(Membership $membership, callable $check): mixed
    {
        $members = app(MembershipContext::class);
        $tenants = app(TenantContext::class);
        $before = [$members->membership(), $tenants->tenantId()];

        $members->set($membership);
        $tenants->setTenantId((string) $membership->tenant_id);
        try {
            return $check();
        } finally {
            $before[0] === null ? $members->forget() : $members->set($before[0]);
            $tenants->setTenantId($before[1]);
        }
    }

    /**
     * The sections switched off per person in this tenant, read once for everyone.
     *
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    private function sectionsOff(string $tenantId, array $userIds): array
    {
        $rows = DB::table('member_access_overrides')->where('tenant_id', $tenantId)->where('effect', 'deny')->whereIn('user_id', $userIds)->get(['user_id', 'permission_key']);
        $out = [];
        foreach ($rows->groupBy('user_id') as $id => $group) {
            $out[(int) $id] = AccessSections::offIn($group->pluck('permission_key')->all());
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function decode(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
