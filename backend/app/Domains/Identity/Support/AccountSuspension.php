<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;

/**
 * Is this person locked out? (ADR 0002)
 *
 * Suspension belongs to a WORKSPACE, not to a person. Reading it off `users.tenant_id` conflated the
 * two: suspending one agency locked its client out of an unrelated workspace they also belonged to,
 * because the column could only ever name one tenant and that one happened to be suspended.
 *
 * The rule now matches what a workspace suspension actually means: the person loses that workspace.
 * They are refused sign-in only when EVERY workspace they can reach is suspended — at which point
 * there is genuinely nowhere to send them.
 */
final class AccountSuspension
{
    private const BLOCKED = ['suspended', 'inactive'];

    public static function everyWorkspaceSuspendedFor(User $user): bool
    {
        $tenantIds = Membership::query()->forUser($user->id)->active()
            ->pluck('tenant_id')->unique()->values();

        // No membership is not a suspension — it is a user with nowhere to go, which the portal
        // gates already refuse. Treating it as suspended would block sign-in entirely and hide that.
        if ($tenantIds->isEmpty()) {
            return false;
        }

        $reachable = Tenant::query()->whereIn('id', $tenantIds)
            ->whereNotIn('status', self::BLOCKED)->count();

        return $reachable === 0;
    }

    /** Whether one specific workspace is suspended — used when a membership is already in hand. */
    public static function isWorkspaceSuspended(?string $tenantId): bool
    {
        if ($tenantId === null) {
            return false;
        }

        return in_array(Tenant::whereKey($tenantId)->value('status'), self::BLOCKED, true);
    }
}
