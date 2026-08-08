<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Access\Models\Role;
use App\Domains\Audit\AuditLogger;
use App\Domains\Identity\Services\PasswordResetService;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        $roles = Role::where('tenant_id', $tenantId)->get(['id', 'name', 'slug']);
        $users = self::inTenant($tenantId)->with('roles:id,name,slug')->get();

        return ApiResponse::success([
            'members' => $users->map(fn (User $u) => [
                'id' => $u->uuid,
                'name' => $u->name,
                'email' => $u->email,
                'roles' => $u->roles->map(fn ($r) => ['slug' => (string) $r->slug, 'name' => (string) $r->name])->values(),
                'is_owner' => $u->roles->contains('slug', 'tenant-owner'),
                'disabled' => $u->disabled_at !== null,
                'last_login_at' => optional($u->last_login_at)->toIso8601String(),
                'two_factor_enabled' => (bool) $u->two_factor_enabled,
            ])->values(),
            'roles' => $roles->map(fn ($r) => ['slug' => (string) $r->slug, 'name' => (string) $r->name])->values(),
        ], 'Team retrieved.');
    }

    public function invite(Request $request, AuditLogger $audit, GrantMembership $grants): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenantId = $this->tenantId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'role' => ['required', 'string', 'exists:roles,slug'],
        ]);
        abort_if(
            self::inTenant($tenantId)->where('email', $data['email'])->exists(),
            422, 'A user with this email already exists.',
        );

        $role = Role::where('tenant_id', $tenantId)->where('slug', $data['role'])->firstOrFail();

        /*
         * Provision the member with a random password they will never be told — MAIL-009.
         *
         * That part is unchanged and correct: nobody, including this workspace's owner, should be
         * able to sign in as a colleague. What was missing is the other half. The comment here used
         * to say the account was «usable via password reset meanwhile», and password reset was a
         * TODO — so every member added through this screen held an account with an unknown
         * 24-character password and no route to a known one. The setup link below IS that route.
         */
        $user = User::create([
            'name' => $data['name'], 'email' => $data['email'],
            'password' => Str::password(24),
        ]);
        $user->assignRole($role);

        /*
         * A role is not a workspace. Creating the user and assigning a role left them with no
         * membership, so `inTenant()` could not see them and they landed nowhere on first sign-in —
         * the same defect already fixed once in InvitationService::accept, in a second place.
         * Dropping `users.tenant_id` is what made it visible: the column had been quietly standing
         * in for the grant.
         */
        $grants->execute(new MembershipGrant(
            user: $user,
            tenant: Tenant::query()->findOrFail($tenantId),
            portal: Portal::forAccountType(Tenant::query()->findOrFail($tenantId)->account_type),
            role: $data['role'],
            grantedBy: $request->user(),
        ));

        $tenant = Tenant::query()->findOrFail($tenantId);
        $delivery = app(PasswordResetService::class)->inviteExistingMember($user, (string) $tenant->name);

        $audit->log(action: 'settings.team.invited', entityType: 'user', entityId: $user->uuid, after: ['email' => $user->email, 'role' => $role->slug]);

        /*
         * The delivery state travels back with the id.
         *
         * The screen that invited somebody is the only place a person will look for «did they get
         * it?», and on an install with no mail provider the honest answer is `awaiting_credentials` —
         * which the interface can then say out loud instead of implying an email that never left.
         */
        return ApiResponse::success(
            ['id' => $user->uuid, 'delivery_status' => $delivery],
            'Member invited.',
            status: 201,
        );
    }

    public function updateRole(Request $request, string $user, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $target = $this->member($user);
        $data = $request->validate(['role' => ['required', 'string', 'exists:roles,slug']]);

        // Guard: never strip the last Owner of the Owner role.
        if ($target->roles->contains('slug', 'tenant-owner') && $data['role'] !== 'tenant-owner') {
            abort_if($this->ownerCount() <= 1, 422, 'Cannot change the role of the last Owner.');
        }
        $role = Role::where('tenant_id', $this->tenantId())->where('slug', $data['role'])->firstOrFail();
        $before = $target->roles->pluck('slug')->all();
        $target->roles()->sync([$role->id]);

        $audit->log(action: 'settings.team.role_changed', entityType: 'user', entityId: $target->uuid, before: ['roles' => $before], after: ['roles' => [$role->slug]]);

        return ApiResponse::success(null, 'Role updated.');
    }

    public function toggleStatus(Request $request, string $user, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $target = $this->member($user);
        abort_if($target->id === $request->user()->id, 422, 'You cannot disable your own account.');

        $disabling = $target->disabled_at === null;
        if ($disabling && $target->roles->contains('slug', 'tenant-owner')) {
            abort_if($this->activeOwnerCount() <= 1, 422, 'Cannot disable the last active Owner.');
        }
        $target->forceFill(['disabled_at' => $disabling ? now() : null])->save();

        $audit->log(action: $disabling ? 'settings.team.disabled' : 'settings.team.enabled', entityType: 'user', entityId: $target->uuid);

        return ApiResponse::success(['disabled' => $disabling], $disabling ? 'Member disabled.' : 'Member enabled.');
    }

    public function destroy(Request $request, string $user, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $target = $this->member($user);
        abort_if($target->id === $request->user()->id, 422, 'You cannot remove your own account.');
        if ($target->roles->contains('slug', 'tenant-owner')) {
            abort_if($this->ownerCount() <= 1, 422, 'Cannot remove the last Owner.');
        }

        $audit->log(action: 'settings.team.removed', entityType: 'user', entityId: $target->uuid, before: ['email' => $target->email]);
        $target->delete();

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
            // A user may hold several memberships in one tenant (different portals), which would
            // otherwise count one owner more than once and let the last owner be removed.
            ->distinct('users.id');
    }

    private function tenantId(): string
    {
        $id = app(TenantContext::class)->tenantId();
        abort_if($id === null, 403, 'No tenant context.');

        return $id;
    }
}
