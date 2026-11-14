<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Access\Models\Role;
use App\Domains\Access\Support\WorkspaceRolePresets;
use App\Domains\Audit\AuditLogger;
use App\Domains\Identity\Services\InvitationService;
use App\Domains\Notifications\Services\EnrolMemberNotifications;
use App\Domains\Notifications\Services\MemberNotices;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Organization team & role management. Roles/permissions are enforced server-side (not just hidden
 * buttons); the last Owner can never be disabled or removed. All mutations require settings.manage
 * and are audited. Users are tenant-scoped — cross-tenant users are never visible or mutable.
 */
final class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenantId = $this->tenantId();

        $roles = Role::where('tenant_id', $tenantId)->get(['id', 'name', 'slug', 'is_system']);
        $users = self::inTenant($tenantId)->with(['roles' => fn ($q) => $q->where('roles.tenant_id', $tenantId)->select('roles.id', 'roles.name', 'roles.slug', 'roles.is_system')])->get();

        return ApiResponse::success([
            'members' => $users->map(fn (User $u) => [
                'id' => $u->uuid,
                'name' => $u->name,
                'email' => $u->email,
                'roles' => $u->roles->map(fn ($r) => [
                    'slug' => (string) $r->slug,
                    'name' => (string) $r->name,
                    /*
                     * ROLE-LABEL-001 — whether this name is the product's or the customer's.
                     *
                     * A system role is seeded in English («Tenant Owner», «Team Member») and belongs
                     * to the product, so it should be read in the reader's language. A role a tenant
                     * created carries the name THEY typed, and translating that would rename their
                     * work. The frontend cannot tell them apart from the name alone.
                     */
                    'is_system' => (bool) $r->is_system,
                ])->values(),
                'is_owner' => $u->roles->contains('slug', 'tenant-owner'),
                'disabled' => $u->disabled_at !== null,
                'last_login_at' => optional($u->last_login_at)->toIso8601String(),
                'two_factor_enabled' => (bool) $u->two_factor_enabled,
            ])->values(),
            /*
             * TEAM-ROLE-PRESETS-001 — the ready-made roles are offered even before this workspace has
             * used one; the row is created when somebody is invited into it or moved into it.
             */
            'roles' => $roles->concat(collect(WorkspaceRolePresets::all())
                ->reject(fn ($p, $slug) => $roles->contains('slug', $slug))
                ->map(fn ($p, $slug) => new Role(['slug' => $slug, 'name' => $p['en'], 'is_system' => true]))
                ->values())->map(fn ($r) => [
                    'slug' => (string) $r->slug,
                    'name' => (string) $r->name,
                    /*
                     * ROLE-LABEL-001 — whether this name is the product's or the customer's.
                     *
                     * A system role is seeded in English («Tenant Owner», «Team Member») and belongs
                     * to the product, so it should be read in the reader's language. A role a tenant
                     * created carries the name THEY typed, and translating that would rename their
                     * work. The frontend cannot tell them apart from the name alone.
                     */
                    'is_system' => (bool) $r->is_system,
                ])->values(),
            /*
             * People who have been invited and are not here yet — TEAM-INVITE-001.
             *
             * This list is the cost of converging on the token path, and it is worth paying. Before,
             * inviting somebody created their account immediately, so the member list was the whole
             * truth and a typo'd address became a permanent ghost account nobody could sign into.
             * Now nothing exists until they accept — which means an invitation that is sitting
             * unaccepted has to be visible, or «I invited Sara last week» has no answer anywhere.
             */
            'invitations' => DB::table('workspace_invitations')
                ->where('tenant_id', $tenantId)
                ->whereNull('accepted_at')
                ->orderByDesc('created_at')
                ->get(['id', 'email', 'role_slug', 'delivery_status', 'expires_at', 'created_at'])
                ->map(static fn (object $i): array => [
                    'id' => (string) $i->id,
                    'email' => (string) $i->email,
                    'role_slug' => (string) $i->role_slug,
                    'delivery_status' => (string) $i->delivery_status,
                    'expires_at' => (string) $i->expires_at,
                    // An expired invitation is not a pending one, and a list that shows both the
                    // same way is a list somebody waits on forever.
                    'expired' => Carbon::parse($i->expires_at)->isPast(),
                ])->values(),
        ], 'Team retrieved.');
    }

    /**
     * Invite somebody — TEAM-INVITE-001.
     *
     * ## Why this stopped creating the account
     *
     * There were two invitation paths. This one provisioned a `User` immediately with a random
     * 24-character password; `/app/team/invitations` issued an expiring token and created nobody
     * until it was accepted. Two paths meant two answers to «is Sara a member?», and this one gave
     * the worse answer: a mistyped address became a real account, holding that email address
     * forever, that nobody could ever sign into and that showed in the team list as a colleague.
     *
     * Both now go through `InvitationService`, so an invitation grants nothing until the person
     * proves they own the address by opening the link.
     *
     * ## What the caller loses, and why that is right
     *
     * The `name` field. The invited person names themselves when they accept, which is the only
     * moment anybody has actually asked them. A name typed by a colleague was never verified and
     * was frequently wrong.
     */
    public function invite(Request $request, AuditLogger $audit, InvitationService $invitations): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenantId = $this->tenantId();

        $data = $request->validate([
            // Accepted and ignored for older clients: the person names themselves at acceptance.
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'role' => ['required', 'string', 'max:80'],
        ]);
        $this->assignableRole($request, $tenantId, $data['role']);

        $result = $invitations->invite(
            tenant: Tenant::query()->findOrFail($tenantId),
            email: $data['email'],
            roleSlug: $data['role'],
            projectIds: null,
            invitedBy: $request->user(),
        );

        $audit->log(
            action: 'settings.team.invited',
            entityType: 'workspace_invitation',
            entityId: $result['id'],
            after: ['email' => $data['email'], 'role' => $data['role']],
        );

        /*
         * The delivery state travels back with the id.
         *
         * The screen that invited somebody is the only place a person will look for «did they get
         * it?», and on an install with no mail provider the honest answer is `awaiting_credentials` —
         * which the interface can then say out loud instead of implying an email that never left.
         */
        return ApiResponse::success($result, 'Invitation sent.', status: 201);
    }

    /**
     * Withdraw an invitation that has not been accepted.
     *
     * Deleted rather than marked, because an unaccepted invitation is a live capability: the token
     * in somebody's inbox still works until this row is gone. There is no «revoked» state worth
     * keeping — the audit entry is the record that it happened.
     */
    public function revokeInvitation(Request $request, string $invitation, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);

        $row = DB::table('workspace_invitations')
            ->where('tenant_id', $this->tenantId())
            ->where('id', $invitation)
            ->first();

        abort_if($row === null, 404, 'No such invitation.');

        DB::table('workspace_invitations')->where('id', $invitation)->delete();

        $audit->log(
            action: 'settings.team.invitation_revoked',
            entityType: 'workspace_invitation',
            entityId: $invitation,
            before: ['email' => $row->email],
        );

        return ApiResponse::success(null, 'Invitation withdrawn.');
    }

    public function updateRole(Request $request, string $user, AuditLogger $audit, MemberNotices $notices): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $target = $this->member($user);
        $data = $request->validate(['role' => ['required', 'string', 'max:80']]);
        $this->assignableRole($request, $this->tenantId(), $data['role']);

        // Guard: never strip the last Owner of the Owner role.
        if ($target->rolesInCurrentTenant()->contains('slug', 'tenant-owner') && $data['role'] !== 'tenant-owner') {
            abort_if($this->ownerCount() <= 1, 422, 'Cannot change the role of the last Owner.');
        }
        $role = Role::where('tenant_id', $this->tenantId())->where('slug', $data['role'])->firstOrFail();
        $before = $target->rolesInCurrentTenant()->pluck('slug')->all();
        // Only THIS tenant's roles are replaced: a person who also belongs to another agency keeps that role.
        $target->roles()->detach(Role::where('tenant_id', $this->tenantId())->pluck('id')->all());
        $target->roles()->syncWithoutDetaching([$role->id]);

        $audit->log(action: 'settings.team.role_changed', entityType: 'user', entityId: $target->uuid, before: ['roles' => $before], after: ['roles' => [$role->slug]]);

        // EMAIL-TRANSACTIONAL-SET-001 — the person is told, in their language, unless they changed their own role.
        if ($target->id !== $request->user()->id) {
            $names = Role::where('tenant_id', $this->tenantId())->whereIn('slug', $before)->pluck('name')->implode('، ');
            $notices->roleChanged($target, Tenant::findOrFail($this->tenantId()), $names !== '' ? (string) $names : implode(', ', $before), (string) $role->name, $request->user());
        }

        return ApiResponse::success(null, 'Role updated.');
    }

    public function toggleStatus(Request $request, string $user, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $target = $this->member($user);
        abort_if($target->id === $request->user()->id, 422, 'You cannot disable your own account.');

        $disabling = $target->disabled_at === null;
        if ($disabling && $target->rolesInCurrentTenant()->contains('slug', 'tenant-owner')) {
            abort_if($this->activeOwnerCount() <= 1, 422, 'Cannot disable the last active Owner.');
        }
        if ($this->belongsElsewhere($target)) {
            // Shared with another agency: suspend the membership HERE, never the account everywhere.
            DB::table('memberships')->where('user_id', $target->id)->where('tenant_id', $this->tenantId())
                ->update(['status' => $disabling ? 'suspended' : 'active', 'updated_at' => now()]);
        } else {
            $target->forceFill(['disabled_at' => $disabling ? now() : null])->save();
        }

        $audit->log(action: $disabling ? 'settings.team.disabled' : 'settings.team.enabled', entityType: 'user', entityId: $target->uuid);

        // NOTIFICATION-ENROLMENT-001 — re-enabled and reachable; a row that already exists is left exactly as it is.
        if (! $disabling) {
            app(EnrolMemberNotifications::class)->enrolQuietly($target, $this->tenantId(), 'team_reenabled');
        }

        return ApiResponse::success(['disabled' => $disabling], $disabling ? 'Member disabled.' : 'Member enabled.');
    }

    public function destroy(Request $request, string $user, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $target = $this->member($user);
        abort_if($target->id === $request->user()->id, 422, 'You cannot remove your own account.');
        if ($target->rolesInCurrentTenant()->contains('slug', 'tenant-owner')) {
            abort_if($this->ownerCount() <= 1, 422, 'Cannot remove the last Owner.');
        }

        $audit->log(action: 'settings.team.removed', entityType: 'user', entityId: $target->uuid, before: ['email' => $target->email]);
        if ($this->belongsElsewhere($target)) {
            // Shared with another agency: leave THIS team, keep the account (and their access there).
            $target->roles()->detach(Role::where('tenant_id', $this->tenantId())->pluck('id')->all());
            DB::table('memberships')->where('user_id', $target->id)->where('tenant_id', $this->tenantId())->delete();
        } else {
            $target->delete();
        }

        return ApiResponse::success(null, 'Member removed.');
    }

    /**
     * The users who belong to a tenant, by MEMBERSHIP (ADR 0002).
     *
     * Was `users.tenant_id`, which described one workspace per person: a user with memberships in
     * two tenants appeared on one team list and vanished from the other. One helper so the four
     * call sites here cannot answer "who is on this team?" four different ways.
     *
     * @return Builder<User>
     */
    private static function inTenant(string $tenantId)
    {
        return User::query()->whereHas(
            'memberships',
            fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'active'),
        );
    }

    private function member(string $uuid): User
    {
        return self::inTenant($this->tenantId())->where('uuid', $uuid)->with('roles:id,name,slug')->firstOrFail();
    }

    /**
     * Whether the person holds a membership in any OTHER tenant — of ANY status.
     *
     * A membership that tenant B has suspended is still B's record of that person: deleting the account here
     * would take it with it. Only «none at all» makes the account this team's to delete.
     */
    private function belongsElsewhere(User $target): bool
    {
        return DB::table('memberships')->where('user_id', $target->id)->where('tenant_id', '!=', $this->tenantId())->exists();
    }

    private function ownerCount(): int
    {
        return $this->ownerQuery()->count();
    }

    private function activeOwnerCount(): int
    {
        return $this->ownerQuery()->whereNull('users.disabled_at')->count();
    }

    private function ownerQuery(): Builder
    {
        return DB::table('users')
            ->join('role_user', 'role_user.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('memberships', 'memberships.user_id', '=', 'users.id')
            ->where('memberships.tenant_id', $this->tenantId())
            ->where('memberships.status', 'active')
            ->where('roles.slug', 'tenant-owner')
            // The role of THIS tenant: a person who owns another agency is not an owner here.
            ->where('roles.tenant_id', $this->tenantId())
            // A user may hold several memberships in one tenant (different portals), which would
            // otherwise count one owner more than once and let the last owner be removed.
            ->distinct('users.id');
    }

    /**
     * TEAM-ROLE-PRESETS-001 — the role somebody is being invited into or moved into.
     *
     * A ready-made role is created in this workspace the first time it is used; any other slug must
     * already be one of THIS workspace's roles (another tenant's role of the same slug is a 422, as
     * it always was through `exists` plus the tenant lookup).
     *
     * And nobody hands out more than they hold: every key of the role must be among the actor's own.
     * Before, any holder of `settings.manage` could make a colleague — or a second account of their
     * own — the workspace manager. Owners hold every key, so nothing changes for them.
     */
    private function assignableRole(Request $request, string $tenantId, string $slug): Role
    {
        $role = Role::where('tenant_id', $tenantId)->where('slug', $slug)->first();

        if ($role === null && ! WorkspaceRolePresets::isPreset($slug)) {
            throw ValidationException::withMessages(['role' => 'Unknown role for this workspace.']);
        }

        // Checked BEFORE a ready-made role is created, so a refused request leaves no row behind.
        $keys = $role !== null
            ? $role->permissions()->pluck('key')
            : collect(WorkspaceRolePresets::defaults($slug));

        $actor = $request->user();
        if (! $actor->is_platform_admin) {
            abort_if($keys->diff(WorkspaceRolePresets::heldBy($actor))->isNotEmpty(), 403, 'You can only assign a role whose permissions you hold yourself.');
        }

        return $role ?? WorkspaceRolePresets::ensure($tenantId, $slug);
    }

    private function tenantId(): string
    {
        $id = app(TenantContext::class)->tenantId();
        abort_if($id === null, 403, 'No tenant context.');

        return $id;
    }
}
