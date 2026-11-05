<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support;

use App\Domains\Tenancy\Models\Membership;
use Illuminate\Support\Facades\DB;

/**
 * Who can still answer for a workspace (SUPERADMIN-MEMBERS-001, ADMIN-FULL-CONTROL-001).
 *
 * An owner counts only with an active membership AND an account that can sign in: a deactivated owner is nobody a customer can
 * reach. One answer for both the membership actions and deactivating an account, so neither can strand a workspace.
 */
final class WorkspaceOwners
{
    public const OWNER = 'tenant-owner';

    /** Roles the console never hands out, whatever a workspace may have named a role of its own. */
    public const PLATFORM_ROLES = ['platform-admin', 'super-admin'];

    public function othersAnswerFor(string $tenantId, int|string $userId): bool
    {
        return DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('users', 'users.id', '=', 'role_user.user_id')
            ->join('memberships', fn ($j) => $j->on('memberships.user_id', '=', 'role_user.user_id')->where('memberships.tenant_id', $tenantId))
            ->where('roles.tenant_id', $tenantId)->where('roles.slug', self::OWNER)
            ->where('memberships.status', 'active')
            ->whereNull('users.disabled_at')
            ->where('role_user.user_id', '!=', $userId)
            ->exists();
    }

    /**
     * The workspaces this person is the only one answering for.
     *
     * @return list<string>
     */
    public function soleOwnerOf(int|string $userId): array
    {
        $tenantIds = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $userId)->where('roles.slug', self::OWNER)
            ->pluck('roles.tenant_id')->filter()->map(fn ($id) => (string) $id)->all();

        $alone = [];
        foreach ($tenantIds as $tenantId) {
            $active = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'active')->exists();
            if ($active && ! $this->othersAnswerFor($tenantId, $userId)) {
                $alone[] = $tenantId;
            }
        }

        return $alone;
    }
}
