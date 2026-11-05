<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Access\Models\Role;
use App\Domains\Audit\AuditLogger;
use App\Domains\Identity\Services\InvitationService;
use App\Domains\Identity\Support\UserSessions;
use App\Domains\Notifications\Services\MemberNotices;
use App\Domains\Platform\Support\WorkspaceOwners;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
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
 *
 * ADMIN-FULL-CONTROL-001 adds the way in: `add` puts an existing person in with a role, or invites someone with no account, and
 * sets no password either way; `reissue` is a fresh link for a pending invitation. Nothing here hands out a platform role, and
 * nobody becomes an owner by adding them, or by a role change without an explicit `confirm_owner`.
 */
final class PlatformMembershipController extends Controller
{
    private const OWNER = WorkspaceOwners::OWNER;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
        private readonly UserSessions $sessions,
        private readonly WorkspaceOwners $owners,
        private readonly InvitationService $invitations,
    ) {}

    /** POST /api/v1/admin/tenants/{tenant}/members */
    public function add(Request $request, Tenant $tenant, GrantMembership $grants, SubscriptionService $subscriptions, MemberNotices $notices): JsonResponse
    {
        $tenantId = (string) $tenant->getKey();
        $offered = collect(Portal::offeredMembershipPortals())
            ->reject(fn (Portal $portal) => $portal === Portal::ClientPortal)
            ->map(fn (Portal $portal) => $portal->value)->values()->all();

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'role' => ['required', 'string', Rule::exists('roles', 'slug')->where('tenant_id', $tenantId)],
            'portal' => ['sometimes', 'nullable', 'string', Rule::in($offered)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        if ($data['role'] === self::OWNER || in_array($data['role'], WorkspaceOwners::PLATFORM_ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'That role is not given by adding someone. An owner is made by a confirmed role change.']);
        }

        /** @var User $operator */
        $operator = $request->user();
        $email = mb_strtolower(trim((string) $data['email']));

        $this->tenants->enterPlatformScope();
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        abort_if($user?->is_platform_admin === true, 403, 'A platform admin is not placed in a workspace.');

        if ($user !== null && Membership::query()->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->exists()) {
            throw ValidationException::withMessages(['email' => 'This person is already in this workspace.']);
        }

        if (! $subscriptions->withinLimit($tenant, 'team_members')) {
            throw ValidationException::withMessages(['email' => 'This workspace has no team seat left. Raise its limit first.']);
        }

        $invitation = null;
        $this->tenants->setTenantId($tenantId);

        try {
            if ($user !== null) {
                /** @var Role $role */
                $role = Role::query()->where('tenant_id', $tenantId)->where('slug', $data['role'])->firstOrFail();

                DB::transaction(function () use ($user, $role, $tenant, $data, $grants, $operator): void {
                    $user->roles()->syncWithoutDetaching([$role->getKey()]);
                    $grants->execute(new MembershipGrant(
                        user: $user,
                        tenant: $tenant,
                        portal: Portal::tryFrom((string) ($data['portal'] ?? '')) ?? Portal::forAccountType($tenant->account_type),
                        role: $role->slug,
                        grantedBy: $operator,
                    ));
                });

                try {
                    $notices->welcome($user, $tenant, (string) $role->name);
                } catch (\Throwable $e) {
                    report($e);
                }
            } else {
                $invitation = $this->invitations->invite($tenant, $email, (string) $data['role'], null, $operator, withLink: true);
            }
        } finally {
            $this->tenants->enterPlatformScope();
        }

        $this->audit->log(
            action: 'platform.member.added',
            entityType: Tenant::class,
            entityId: $tenantId,
            // The address and the role. An accept link is a credential and is never written here.
            after: ['email' => $email, 'role' => $data['role'], 'added' => $user !== null, 'invited' => $invitation !== null],
            reason: $data['reason'],
            userId: (int) $operator->getKey(),
            tenantId: $tenantId,
        );

        return ApiResponse::success([
            'added' => $user !== null,
            'member' => $user === null ? null : $this->state($tenantId, $user),
            'invitation' => $this->invitation($invitation),
        ], $user !== null ? 'Added to this workspace.' : 'Invited to this workspace.');
    }

    /** POST /api/v1/admin/tenants/{tenant}/invitations/{invitation}/reissue — a fresh link; the old one stops working. */
    public function reissue(Request $request, Tenant $tenant, string $invitation): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $tenantId = (string) $tenant->getKey();

        /** @var User $operator */
        $operator = $request->user();

        // Found through THIS workspace or not at all: an id from another workspace does not exist here.
        $row = DB::table('workspace_invitations')->where('id', $invitation)->where('tenant_id', $tenantId)->whereNull('accepted_at')->first();
        abort_if($row === null, 404, 'This workspace has no such pending invitation.');

        $projectIds = $row->project_ids === null ? null : array_values((array) json_decode((string) $row->project_ids, true));

        $this->tenants->setTenantId($tenantId);

        try {
            $issued = DB::transaction(function () use ($row, $tenant, $projectIds, $operator): array {
                DB::table('workspace_invitations')->where('id', $row->id)->delete();

                return $this->invitations->invite($tenant, (string) $row->email, (string) $row->role_slug, $projectIds, $operator, allowExisting: true, withLink: true);
            });
        } finally {
            $this->tenants->enterPlatformScope();
        }

        $this->audit->log(
            action: 'platform.member.invitation_reissued',
            entityType: Tenant::class,
            entityId: $tenantId,
            after: ['email' => $row->email, 'role' => $row->role_slug, 'delivery' => $issued['delivery_status'] ?? null],
            reason: $data['reason'],
            userId: (int) $operator->getKey(),
            tenantId: $tenantId,
        );

        return ApiResponse::success(['invitation' => $this->invitation($issued)], 'A new invitation was issued.');
    }

    /**
     * @param  array<string, mixed>|null  $invitation
     * @return array<string, mixed>|null
     */
    private function invitation(?array $invitation): ?array
    {
        return $invitation === null ? null : [
            'delivery_status' => $invitation['delivery_status'] ?? null,
            'accept_link' => $invitation['accept_link'] ?? null,
            'expires_in_hours' => $invitation['expires_in_hours'] ?? null,
        ];
    }

    /** POST /api/v1/admin/tenants/{tenant}/members/{user} */
    public function act(Request $request, Tenant $tenant, User $user): JsonResponse
    {
        $tenantId = (string) $tenant->getKey();
        $data = $request->validate([
            'action' => ['required', 'string', 'in:change_role,suspend,reactivate,remove'],
            'role' => ['required_if:action,change_role', 'nullable', 'string',
                Rule::exists('roles', 'slug')->where('tenant_id', $tenantId)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'confirm_owner' => ['sometimes', 'boolean'],
        ]);

        /** @var User $operator */
        $operator = $request->user();
        abort_if($user->is_platform_admin && $user->getKey() !== $operator->getKey(), 403, 'Another platform admin is not managed from here.');

        $this->tenants->enterPlatformScope();
        $memberships = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $user->getKey());
        abort_unless((clone $memberships)->exists(), 404, 'This person is not in this workspace.');

        $before = $this->state($tenantId, $user);
        $isOwner = in_array(self::OWNER, $before['roles'], true);

        if ($data['action'] === 'change_role') {
            if (in_array($data['role'], WorkspaceOwners::PLATFORM_ROLES, true)) {
                throw ValidationException::withMessages(['role' => 'A platform role is never given from a workspace.']);
            }

            if ($data['role'] === self::OWNER && ! $isOwner && ($data['confirm_owner'] ?? false) !== true) {
                throw ValidationException::withMessages(['confirm_owner' => 'Making someone an owner of this workspace has to be confirmed explicitly.']);
            }
        }

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
        if (! $this->owners->othersAnswerFor($tenantId, $user->getKey())) {
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
