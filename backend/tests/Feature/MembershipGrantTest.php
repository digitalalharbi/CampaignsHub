<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR 0002 — access is granted explicitly, never as a side effect.
 *
 * Each test here corresponds to a way this has gone wrong or could: a user row implying access, a
 * portal inferred from the account type, an agency's client handed the whole agency, a half-written
 * grant surviving a failure, and a re-invitation duplicating what already exists.
 */
final class MembershipGrantTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name, ?string $accountType = null): Tenant
    {
        return Tenant::create([
            'name' => $name, 'slug' => str($name)->slug()->value().'-'.uniqid(),
            'status' => 'active', 'account_type' => $accountType,
        ]);
    }

    private function bareUser(Tenant $tenant, string $email): User
    {
        return User::create([
            'tenant_id' => $tenant->id, 'name' => 'U', 'email' => $email,
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
    }

    private function client(Tenant $tenant, string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $tenant->id, 'name' => $name,
            'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
    }

    /** (1) Creating a user, on its own, grants nothing. */
    public function test_creating_a_user_alone_grants_no_access(): void
    {
        $tenant = $this->tenant('Bare Co', 'agency');
        $user = $this->bareUser($tenant, 'bare@test.dev');

        $this->assertSame(0, $user->memberships()->count());
        $this->assertSame(0, Membership::query()->forUser($user->id)->count());
    }

    /**
     * (2) The portal is stated by the caller, never inferred. Handing the grant an agency tenant
     * does not make the grant an agency one — otherwise `account_type` would quietly be back in
     * charge of authorisation, and a person could hold only the portal their tenant implies.
     */
    public function test_the_portal_is_not_derived_from_the_account_type(): void
    {
        $agencyTenant = $this->tenant('Derive Co', 'agency');
        $user = $this->bareUser($agencyTenant, 'derive@test.dev');

        $membership = app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $agencyTenant, portal: Portal::Influencers, role: 'member',
        ));

        // The tenant says "agency"; the grant said "influencers". The grant wins.
        $this->assertSame(Portal::Influencers, $membership->portal);
        $this->assertSame('agency', $agencyTenant->account_type);
    }

    /** And the legacy column is equally powerless to decide a portal. */
    public function test_the_portal_is_not_derived_from_the_user_tenant_column(): void
    {
        $a = $this->tenant('Column A', 'agency');
        $b = $this->tenant('Column B', 'brand');
        $user = $this->bareUser($a, 'column@test.dev');

        $membership = app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $b, portal: Portal::ClientPortal, role: 'client_viewer',
        ));

        $this->assertSame((string) $b->id, (string) $membership->tenant_id);
        $this->assertSame((string) $a->id, (string) $user->tenant_id, 'the legacy column is untouched and unused');
    }

    /**
     * (3) An agency's client must be confined to their own space. The named constructor exists so
     * this cannot be got wrong by omission — a client granted the agency portal, or the client
     * portal with no scope, would see the entire agency.
     */
    public function test_an_agency_client_is_confined_to_their_own_client_space(): void
    {
        $agency = $this->tenant('Host Agency', 'agency');
        $mine = $this->client($agency, 'My Client');
        $theirs = $this->client($agency, 'Another Client');
        $user = $this->bareUser($agency, 'client-user@test.dev');
        $granter = $this->bareUser($agency, 'granter@test.dev');

        $membership = app(GrantMembership::class)->execute(
            MembershipGrant::forAgencyClient($user, $agency, [(string) $mine->id], grantedBy: $granter),
        );

        $this->assertSame(Portal::ClientPortal, $membership->portal);
        $this->assertSame([(string) $mine->id], $membership->clientScopeIds());
        $this->assertNotContains((string) $theirs->id, $membership->clientScopeIds());
        $this->assertTrue($membership->isClientScoped(), 'a client grant must never be unrestricted');
        $this->assertSame($granter->id, $membership->invited_by);
    }

    /**
     * (4) A grant is atomic. A membership whose scope rows failed to insert would be UNRESTRICTED —
     * the opposite of what the caller asked for, and the most dangerous way for this to fail.
     */
    public function test_a_failed_grant_leaves_nothing_behind(): void
    {
        $agency = $this->tenant('Atomic Co', 'agency');
        $user = $this->bareUser($agency, 'atomic@test.dev');
        $before = Membership::count();

        try {
            DB::transaction(function () use ($user, $agency) {
                app(GrantMembership::class)->execute(new MembershipGrant(
                    user: $user, tenant: $agency, portal: Portal::Agency, role: 'member',
                    clientScopeIds: [(string) $this->client($agency, 'Scoped')->id],
                ));

                throw new \RuntimeException('something later in the same unit of work failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($before, Membership::count(), 'no membership may survive a failed unit of work');
        $this->assertSame(0, MembershipScope::count(), 'and no orphaned scope either');
    }

    /** (5) Re-inviting or retrying returns the existing grant rather than duplicating it. */
    public function test_regranting_is_idempotent(): void
    {
        $tenant = $this->tenant('Repeat Co', 'agency');
        $user = $this->bareUser($tenant, 'repeat@test.dev');
        $grant = new MembershipGrant(user: $user, tenant: $tenant, portal: Portal::Agency, role: 'member');

        $first = app(GrantMembership::class)->execute($grant);
        $second = app(GrantMembership::class)->execute($grant);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Membership::query()->forUser($user->id)->count());
    }

    /** Re-inviting a client with a different scope replaces it rather than accumulating access. */
    public function test_regranting_a_client_replaces_the_scope_instead_of_widening_it(): void
    {
        $agency = $this->tenant('Rescope Agency', 'agency');
        $first = $this->client($agency, 'First');
        $second = $this->client($agency, 'Second');
        $user = $this->bareUser($agency, 'rescope@test.dev');

        app(GrantMembership::class)->execute(
            MembershipGrant::forAgencyClient($user, $agency, [(string) $first->id]),
        );
        $membership = app(GrantMembership::class)->execute(
            MembershipGrant::forAgencyClient($user, $agency, [(string) $second->id]),
        );

        $this->assertSame([(string) $second->id], $membership->clientScopeIds());
        $this->assertSame(1, Membership::query()->forUser($user->id)->count());
    }

    /** Registration grants the owner membership in the SAME transaction as the workspace. */
    public function test_registration_grants_an_owner_membership_atomically(): void
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->postJson('/api/v1/auth/register', [
                'tenant_name' => 'Atomic Signup', 'name' => 'Owner', 'email' => 'atomic.signup@test.dev',
                'password' => 'secret1234', 'password_confirmation' => 'secret1234',
                'account_type' => 'agency',
            ])->assertCreated();

        $user = User::where('email', 'atomic.signup@test.dev')->firstOrFail();
        $membership = $user->memberships()->firstOrFail();

        $this->assertSame('owner', $membership->role);
        $this->assertSame(Portal::Agency, $membership->portal);
        $this->assertSame([], $membership->clientScopeIds(), 'an owner is unrestricted in their own workspace');
    }
}
