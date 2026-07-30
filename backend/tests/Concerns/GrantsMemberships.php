<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;

/**
 * The dedicated helper tests and factories use to grant access (ADR 0002).
 *
 * Production code grants through {@see GrantMembership} at each real path — registration, invitation,
 * client-user creation, import, admin. Tests need the same thing without repeating the DTO everywhere,
 * but they must still SAY they are granting: creating a user does not grant access here either, which
 * is what makes "a membership-less user is refused everything" testable at all.
 */
trait GrantsMemberships
{
    /** @param  list<string>  $clientIds */
    protected function grantMembership(
        User $user,
        Tenant $tenant,
        Portal $portal = Portal::App,
        string $role = 'member',
        array $clientIds = [],
    ): Membership {
        return app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user,
            tenant: $tenant,
            portal: $portal,
            role: $role,
            clientScopeIds: $clientIds,
        ));
    }

    /** A user plus the membership that lets them reach the advertiser portal — the common fixture. */
    protected function userWithMembership(
        Tenant $tenant,
        string $email,
        Portal $portal = Portal::App,
        string $role = 'member',
    ): User {
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => $email,
            'password' => 'secret123',
            'email_verified_at' => now(),
        ]);

        $this->grantMembership($user, $tenant, $portal, $role);

        return $user;
    }
}
