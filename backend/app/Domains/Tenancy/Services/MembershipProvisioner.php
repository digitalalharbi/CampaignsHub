<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;

/**
 * The one place a membership is granted (ADR 0002).
 *
 * A user created without a membership has no portal to land in and falls through to onboarding
 * forever — which is exactly what a `migrate:fresh --seed` produced before this existed, because the
 * seeders create users directly and the migration's backfill had no rows to work on yet.
 *
 * Idempotent by design: seeders re-run, invitations get re-sent, and demo data gets rebuilt, so
 * granting the same membership twice must be a no-op rather than a unique-constraint violation.
 */
final class MembershipProvisioner
{
    /**
     * Grant (or return) a membership. The first membership a user receives becomes their default,
     * because a user with memberships but no default would land nowhere.
     */
    public function ensure(
        User $user,
        Tenant $tenant,
        Portal $portal,
        string $role = 'member',
        ?Workspace $workspace = null,
    ): Membership {
        $existing = Membership::query()
            ->forUser($user->id)
            ->where('tenant_id', $tenant->id)
            ->where('portal', $portal->value)
            ->where('workspace_id', $workspace?->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $hasDefault = Membership::query()->forUser($user->id)->where('is_default', true)->exists();

        return Membership::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'portal' => $portal->value,
            'workspace_id' => $workspace?->id,
            'role' => $role,
            'status' => 'active',
            'is_default' => ! $hasDefault,
        ]);
    }

    /**
     * Confine a membership to specific entities. Passing an empty list REMOVES every scope, which
     * means unrestricted within the tenant — so this is also how an account manager is promoted to
     * seeing everything, and the two states are expressed by the same call rather than two.
     *
     * @param  list<string>  $ids
     */
    public function setScope(Membership $membership, string $scopeType, array $ids): void
    {
        $membership->scopes()->where('scope_type', $scopeType)->delete();

        foreach (array_unique($ids) as $id) {
            MembershipScope::create([
                'membership_id' => $membership->getKey(),
                'scope_type' => $scopeType,
                'scope_id' => $id,
            ]);
        }

        $membership->unsetRelation('scopes');
    }

    /** @param  list<string>  $clientIds */
    public function scopeToClients(Membership $membership, array $clientIds): void
    {
        $this->setScope($membership, MembershipScope::TYPE_CLIENT, $clientIds);
    }

    /** @param  list<string>  $projectIds */
    public function scopeToProjects(Membership $membership, array $projectIds): void
    {
        $this->setScope($membership, MembershipScope::TYPE_PROJECT, $projectIds);
    }

    /**
     * The membership implied by the user's own workspace — used by seeders and by any legacy path
     * that still creates a user straight from `users.tenant_id`.
     */
    public function ensureForOwnWorkspace(User $user, string $role = 'member'): ?Membership
    {
        if ($user->tenant_id === null) {
            return null;
        }

        $tenant = Tenant::find($user->tenant_id);
        if ($tenant === null) {
            return null;
        }

        return $this->ensure($user, $tenant, Portal::forAccountType($tenant->account_type), $role);
    }

    /**
     * Moves the default to another membership the user already holds. Done in a transaction-free
     * two-step because the database enforces one default per user, so the old one must be cleared
     * before the new one is set.
     */
    public function makeDefault(Membership $membership): void
    {
        Membership::query()
            ->forUser($membership->user_id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $membership->forceFill(['is_default' => true])->save();
    }
}
