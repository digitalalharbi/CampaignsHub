<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Access\Models\Role;
use App\Domains\Audit\AuditLogger;
use App\Domains\Identity\Support\UserSessions;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SUPERADMIN-MEMBERS-001 — one person's place in one workspace, changed from the console.
 *
 * - `change_role`: the workspace's own roles only; this workspace's roles are replaced, any other workspace's are untouched.
 * - `suspend` / `reactivate`: the membership here, never the account — the person keeps every other workspace.
 * - `remove`: leaves this workspace (membership and this workspace's roles). The account is never deleted from here; a person
 *   left with no workspace shows on the console as «people with no workspace», and their sessions end.
 *
 * Every action needs a reason and lands in THAT workspace's audit trail. A workspace always keeps one active owner: the last one
 * cannot be demoted, suspended or removed. Another platform admin is not managed from here.
 */
final class PlatformMembershipController extends Controller
{
    private const OWNER = 'tenant-owner';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
        private readonly UserSessions $sessions,
    ) {}

    /** POST /api/v1/admin/tenants/{tenant}/members/{user} */
    public function act(Request $request, Tenant $tenant, User $user): JsonResponse
    {
        $tenantId = (string) $tenant->getKey();
        $data = $request->validate([
            'action' => ['required', 'string', 'in:change_role,suspend,reactivate,remove'],
            'role' => ['required_if:action,change_role', 'nullable', 'string',
                Rule::exists('roles', 'slug')->where('tenant_id', $tenantId)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        /** @var User $operator */
        $operator = $request->user();
        abort_if($user->is_platform_admin && $user->getKey() !== $operator->getKey(), 403, 'Another platform admin is not managed from here.');

        $this->tenants->enterPlatformScope();
        $memberships = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $user->getKey());
        abort_unless((clone $memberships)->exists(), 404, 'This person is not in this workspace.');

        $before = $this->state($tenantId, $user);
        $isOwner = in_array(self::OWNER, $before['roles'], true);

        match ((string) $data['action']) {
            'change_role' => $this->changeRole($tenantId, $user, (string) $data['role'], $isOwner),
            'suspend' => $this->suspend($tenantId, $user, $isOwner, $memberships),
            'reactivate' => (clone $memberships)->update(['status' => 'active', 'updated_at' => now()]),
            default => $this->remove($tenantId, $user, $isOwner, $memberships),
        };

        $after = $this->state($tenantId, $user);
        $this->audit->log(
            action: 'platform.member.'.$data['action'],
            entityType: User::class,
            entityId: (string) $user->getKey(),
            before: $before,
            after: $after,
            reason: $data['reason'],
            userId: (int) $operator->getKey(),
            tenantId: $tenantId,
        );

        return ApiResponse::success(['member' => $after], 'Done.');
    }

    private function changeRole(string $tenantId, User $user, string $slug, bool $isOwner): void
    {
        if ($isOwner && $slug !== self::OWNER) {
            $this->keepAnOwner($tenantId, $user, 'role');
        }
        $ids = Role::query()->where('tenant_id', $tenantId)->pluck('id')->all();
        $user->roles()->detach($ids);
        $user->roles()->syncWithoutDetaching([Role::query()->where('tenant_id', $tenantId)->where('slug', $slug)->value('id')]);
    }

    private function suspend(string $tenantId, User $user, bool $isOwner, $memberships): void
    {
        if ($isOwner) {
            $this->keepAnOwner($tenantId, $user, 'action');
        }
        (clone $memberships)->update(['status' => 'suspended', 'updated_at' => now()]);
        $this->endSessionsIfLockedOut($user);
    }

    private function remove(string $tenantId, User $user, bool $isOwner, $memberships): void
    {
        if ($isOwner) {
            $this->keepAnOwner($tenantId, $user, 'action');
        }
        $user->roles()->detach(Role::query()->where('tenant_id', $tenantId)->pluck('id')->all());
        (clone $memberships)->delete();
        $this->endSessionsIfLockedOut($user);
    }

    /** A person with no active workspace left signs in to nothing; their open sessions end rather than linger. */
    private function endSessionsIfLockedOut(User $user): void
    {
        if (! Membership::query()->where('user_id', $user->getKey())->where('status', 'active')->exists()) {
            $this->sessions->revokeAll($user);
        }
    }

    private function keepAnOwner(string $tenantId, User $user, string $field): void
    {
        $others = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('memberships', fn ($j) => $j->on('memberships.user_id', '=', 'role_user.user_id')->where('memberships.tenant_id', $tenantId))
            ->where('roles.tenant_id', $tenantId)->where('roles.slug', self::OWNER)
            ->where('memberships.status', 'active')
            ->where('role_user.user_id', '!=', $user->getKey())
            ->distinct()->count('role_user.user_id');

        if ($others === 0) {
            throw ValidationException::withMessages([$field => __('A workspace must keep at least one active owner.')]);
        }
    }

    /** @return array{roles: list<string>, status: string|null, member: bool} */
    private function state(string $tenantId, User $user): array
    {
        $status = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->value('status');

        return [
            'roles' => $user->roles()->where('roles.tenant_id', $tenantId)->pluck('slug')->sort()->values()->all(),
            'status' => $status,
            'member' => $status !== null,
        ];
    }
}
