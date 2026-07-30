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
     * The membership for a workspace the caller NAMES.
     *
     * Took its tenant from `users.tenant_id` until that column was removed (ADR 0002). That was the
     * last app-code reader of it, and the reason it had to go: the column describes at most one
     * workspace while a user may hold memberships in several, so it silently answered "which
     * tenant?" with whichever one happened to be stamped at registration.
     *
     * The caller now says which tenant, because the caller is the only one that knows.
     */
    public function ensureForWorkspace(User $user, Tenant $tenant, string $role = 'member'): Membership
    {
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
