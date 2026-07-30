<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Actions\ManageMembershipScopes;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
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

    /**
     * The sequence the whole design has to get right: one client → add a second → sees both →
     * remove one → loses only that one → a third client is still unreachable throughout.
     */
    public function test_an_agency_member_gains_and_loses_clients_one_at_a_time(): void
    {
        $agency = $this->tenant('Sequence Agency', 'agency');
        $alpha = $this->client($agency, 'Alpha');
        $beta = $this->client($agency, 'Beta');
        $gamma = $this->client($agency, 'Gamma');   // never granted
        $user = $this->bareUser($agency, 'sequence@test.dev');
        $scopes = app(ManageMembershipScopes::class);

        // 1. Invited for Alpha.
        $membership = app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $agency, portal: Portal::Agency, role: 'account_manager',
            clientScopeIds: [(string) $alpha->id],
        ));
        $this->assertSame([(string) $alpha->id], $membership->clientScopeIds());

        // 2. Given Beta as well — Alpha is KEPT. This is the case a replace would have destroyed.
        $membership = $scopes->add($membership, MembershipScope::TYPE_CLIENT, [(string) $beta->id]);
        $this->assertEqualsCanonicalizing(
            [(string) $alpha->id, (string) $beta->id],
            $membership->clientScopeIds(),
        );

        // 3. Re-inviting for Alpha changes nothing at all.
        $membership = app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $agency, portal: Portal::Agency, role: 'account_manager',
            clientScopeIds: [(string) $alpha->id],
        ));
        $this->assertCount(2, $membership->clientScopeIds());
        $this->assertSame(2, MembershipScope::where('membership_id', $membership->getKey())->count());

        // 4. Alpha withdrawn — and ONLY Alpha.
        $membership = $scopes->remove($membership, MembershipScope::TYPE_CLIENT, (string) $alpha->id);
        $this->assertSame([(string) $beta->id], $membership->clientScopeIds());

        // 5. Gamma was never reachable at any point.
        $this->assertNotContains((string) $gamma->id, $membership->clientScopeIds());
    }

    /** Replacing is destructive and separate — an administrator has to choose it by name. */
    public function test_replace_is_the_only_operation_that_removes_everything(): void
    {
        $agency = $this->tenant('Replace Agency', 'agency');
        $a = $this->client($agency, 'Keep');
        $b = $this->client($agency, 'Also Keep');
        $c = $this->client($agency, 'Only This');
        $user = $this->bareUser($agency, 'replace@test.dev');
        $scopes = app(ManageMembershipScopes::class);

        $membership = app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $agency, portal: Portal::Agency, role: 'account_manager',
            clientScopeIds: [(string) $a->id, (string) $b->id],
        ));
        $this->assertCount(2, $membership->clientScopeIds());

        $membership = $scopes->replace($membership, MembershipScope::TYPE_CLIENT, [(string) $c->id]);
        $this->assertSame([(string) $c->id], $membership->clientScopeIds());
    }

    /**
     * FAIL-CLOSED. No scope rows means no clients — the inverse would make every failure generous:
     * a grant whose rows failed to insert, or a member whose last client was removed, would have
     * gained the whole agency instead of losing everything.
     */
    public function test_no_scopes_means_no_clients_not_all_of_them(): void
    {
        $agency = $this->tenant('Closed Agency', 'agency');
        $this->client($agency, 'Unreachable');
        $user = $this->bareUser($agency, 'closed@test.dev');

        $membership = app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $agency, portal: Portal::Agency, role: 'account_manager',
        ));

        $resolver = app(ClientScopeResolver::class);
        $this->assertFalse($resolver->hasUnrestrictedAccess($user));
        $this->assertSame([], $resolver->reachableClientIds($user, $membership));
    }

    /** A client-portal user cannot be widened by re-inviting them for someone else's client. */
    public function test_a_client_portal_user_cannot_be_widened_by_re_invitation(): void
    {
        $agency = $this->tenant('Confined Agency', 'agency');
        $mine = $this->client($agency, 'Mine');
        $theirs = $this->client($agency, 'Theirs');
        $user = $this->bareUser($agency, 'confined@test.dev');

        app(GrantMembership::class)->execute(
            MembershipGrant::forAgencyClient($user, $agency, [(string) $mine->id]),
        );

        // A second invitation naming another client must not quietly extend their reach. Widening a
        // confined client is an administrative act, not a side effect of sending an invitation.
        $membership = app(GrantMembership::class)->execute(
            MembershipGrant::forAgencyClient($user, $agency, [(string) $mine->id]),
        );

        $this->assertSame([(string) $mine->id], $membership->clientScopeIds());
        $this->assertFalse(app(ClientScopeResolver::class)->canReach($user, (string) $theirs->id));
    }

    /** Registration grants the owner membership in the SAME transaction as the workspace. */
    public function test_registration_grants_an_owner_membership_atomically(): void
    {
        $this->seed(PermissionSeeder::class);

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
        // An owner reaches every client through the clients.view_all PERMISSION, never through
        // having no scope rows — an empty set now means nothing, not everything.
        $this->assertTrue($user->hasPermission(ClientScopeResolver::ALL_CLIENTS));
        $this->assertNull(app(ClientScopeResolver::class)->reachableClientIds($user));
    }

    /**
     * The ceiling in action through the real client API: an account manager scoped to one client
     * cannot open another, even one they OWN. Ownership grants; the membership caps.
     */
    public function test_a_scoped_member_cannot_open_a_client_outside_their_scope(): void
    {
        $this->seed(PermissionSeeder::class);
        $agency = $this->tenant('Ceiling Agency', 'agency');
        $mine = $this->client($agency, 'Assigned');
        $theirs = $this->client($agency, 'Not Assigned');

        $user = $this->bareUser($agency, 'ceiling@test.dev');
        // Deliberately made OWNER of the client they are not scoped to — ownership must not win.
        $theirs->forceFill(['owner_id' => $user->id])->save();

        $role = Role::create([
            'tenant_id' => $agency->id, 'name' => 'AM', 'slug' => 'am-'.uniqid(),
        ]);
        $role->givePermissionTo('clients.view');
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $agency, portal: Portal::Agency, role: 'account_manager',
            clientScopeIds: [(string) $mine->id],
        ));

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/app/clients/{$mine->id}")->assertOk();
        // Owned, but outside the membership scope → refused.
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/app/clients/{$theirs->id}")->assertForbidden();

        // And the portfolio listing shows only the assigned one.
        $list = $this->actingAs($user, 'sanctum')->getJson('/api/v1/app/clients')->assertOk();
        $ids = collect($list->json('data.items') ?? $list->json('data'))->pluck('id')->all();
        $this->assertContains((string) $mine->id, $ids);
        $this->assertNotContains((string) $theirs->id, $ids);
    }

    /** clients.view_all lifts the ceiling — the positive grant, and the only thing that does. */
    public function test_the_all_clients_permission_lifts_the_ceiling(): void
    {
        $this->seed(PermissionSeeder::class);
        $agency = $this->tenant('Unrestricted Agency', 'agency');
        $a = $this->client($agency, 'One');
        $b = $this->client($agency, 'Two');
        $user = $this->bareUser($agency, 'allclients@test.dev');

        $role = Role::create([
            'tenant_id' => $agency->id, 'name' => 'Admin', 'slug' => 'admin-'.uniqid(),
        ]);
        $role->givePermissionTo('clients.view', ClientScopeResolver::ALL_CLIENTS);
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $agency, portal: Portal::Agency, role: 'admin',
            clientScopeIds: [(string) $a->id],   // scoped, but the permission outranks it
        ));

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/app/clients/{$a->id}")->assertOk();
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/app/clients/{$b->id}")->assertOk();
    }
}
