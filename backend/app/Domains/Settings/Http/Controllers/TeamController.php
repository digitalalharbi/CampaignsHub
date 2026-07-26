<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Access\Models\Role;
use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Context\TenantContext;
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
        $users = User::where('tenant_id', $tenantId)->with('roles:id,name,slug')->get();

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

    public function invite(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenantId = $this->tenantId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'role' => ['required', 'string', 'exists:roles,slug'],
        ]);
        abort_if(
            User::where('tenant_id', $tenantId)->where('email', $data['email'])->exists(),
            422, 'A user with this email already exists.',
        );

        $role = Role::where('tenant_id', $tenantId)->where('slug', $data['role'])->firstOrFail();

        // Provision a pending member with a random password (real invite email is delivered by the
        // Scheduled Reports & Email phase; the account is usable via password reset meanwhile).
        $user = User::create([
            'tenant_id' => $tenantId, 'name' => $data['name'], 'email' => $data['email'],
            'password' => Str::password(24),
        ]);
        $user->assignRole($role);

        $audit->log(action: 'settings.team.invited', entityType: 'user', entityId: $user->uuid, after: ['email' => $user->email, 'role' => $role->slug]);

        return ApiResponse::success(['id' => $user->uuid], 'Member invited.', status: 201);
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

    private function member(string $uuid): User
    {
        return User::where('tenant_id', $this->tenantId())->where('uuid', $uuid)->with('roles:id,name,slug')->firstOrFail();
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
            ->where('users.tenant_id', $this->tenantId())
            ->where('roles.slug', 'tenant-owner');
    }

    private function tenantId(): string
    {
        $id = app(TenantContext::class)->tenantId();
        abort_if($id === null, 403, 'No tenant context.');

        return $id;
    }
}
