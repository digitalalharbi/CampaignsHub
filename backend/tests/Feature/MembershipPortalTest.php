<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Domains\Tenancy\Services\MembershipProvisioner;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * ADR 0002 — the membership layer.
 *
 * The point of this table is that a user is NOT permanently one kind of account. These tests hold that
 * line: several memberships across portals, a database-enforced single default, and a resolver that
 * refuses to honour a portal the user does not actually hold.
 */
final class MembershipPortalTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    private function tenant(string $name, ?string $accountType = null): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'status' => 'active',
            'account_type' => $accountType,
        ]);
    }

    /** Creating a user grants nothing; these tests grant explicitly, which is the point. */
    private function user(Tenant $tenant, string $email): User
    {
        return User::create([
            'name' => 'U', 'email' => $email, 'password' => 'secret123',
        ]);
    }

    private function clientWorkspace(Tenant $tenant, string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $tenant->id, 'name' => $name,
            'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
    }

    private function membership(User $user, Tenant $tenant, Portal $portal, bool $default = false): Membership
    {
        return Membership::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'portal' => $portal->value,
            'role' => 'member',
            'status' => 'active',
            'is_default' => $default,
        ]);
    }

    public function test_a_user_can_hold_memberships_in_several_portals_and_tenants(): void
    {
        $agency = $this->tenant('Agency Co', 'agency');
        $brand = $this->tenant('Brand Co', 'brand');
        $user = $this->user($agency, 'multi@test.dev');

        $this->membership($user, $agency, Portal::Agency, default: true);
        $this->membership($user, $brand, Portal::App);
        $this->membership($user, $agency, Portal::Influencers);

        $this->assertCount(3, $user->memberships()->get());
        $this->assertTrue(app(PortalResolver::class)->needsSwitcher($user));
    }

    public function test_the_same_portal_cannot_be_granted_twice_in_one_tenant(): void
    {
        $tenant = $this->tenant('Dup Co', 'agency');
        $user = $this->user($tenant, 'dup@test.dev');
        $this->membership($user, $tenant, Portal::Agency);

        $this->expectException(QueryException::class);
        $this->membership($user, $tenant, Portal::Agency);
    }

    public function test_a_user_can_only_have_one_default_membership(): void
    {
        $a = $this->tenant('A Co', 'agency');
        $b = $this->tenant('B Co', 'brand');
        $user = $this->user($a, 'default@test.dev');
        $this->membership($user, $a, Portal::Agency, default: true);

        $this->expectException(QueryException::class);
        $this->membership($user, $b, Portal::App, default: true);
    }

    public function test_the_resolver_lands_a_single_membership_straight_in_its_portal(): void
    {
        $tenant = $this->tenant('Solo Co', 'brand');
        $user = $this->user($tenant, 'solo@test.dev');
        $this->membership($user, $tenant, Portal::App, default: true);

        $resolver = app(PortalResolver::class);
        $this->assertFalse($resolver->needsSwitcher($user));
        $this->assertSame('/app/dashboard', $resolver->landingPathFor($user));
    }

    public function test_the_resolver_sends_a_multi_membership_user_to_the_switcher(): void
    {
        $a = $this->tenant('Multi A', 'agency');
        $b = $this->tenant('Multi B', 'brand');
        $user = $this->user($a, 'switch@test.dev');
        $this->membership($user, $a, Portal::Agency, default: true);
        $this->membership($user, $b, Portal::App);

        $this->assertSame('/switch', app(PortalResolver::class)->landingPathFor($user));
    }

    /** A portal asked for in the URL is honoured only when the user actually holds it. */
    public function test_a_requested_portal_the_user_holds_is_honoured(): void
    {
        $a = $this->tenant('Held A', 'agency');
        $b = $this->tenant('Held B', 'brand');
        $user = $this->user($a, 'held@test.dev');
        $this->membership($user, $a, Portal::Agency, default: true);
        $this->membership($user, $b, Portal::App);

        $this->assertSame('/app/dashboard', app(PortalResolver::class)->landingPathFor($user, Portal::App));
    }

    /** Asking for a portal you do NOT hold must never grant it — you land on your own default. */
    public function test_a_requested_portal_the_user_does_not_hold_is_refused(): void
    {
        $tenant = $this->tenant('Refuse Co', 'brand');
        $user = $this->user($tenant, 'refuse@test.dev');
        $this->membership($user, $tenant, Portal::App, default: true);

        $resolver = app(PortalResolver::class);
        // Asking for Agency does not conjure an Agency membership — the user's own portal is returned.
        $this->assertSame(Portal::App, $resolver->resolve($user, Portal::Agency)->portal);
        $this->assertSame('/app/dashboard', $resolver->landingPathFor($user, Portal::Agency));
    }

    /** A revoked membership is not a membership. */
    public function test_a_revoked_membership_is_ignored(): void
    {
        $a = $this->tenant('Revoked A', 'agency');
        $b = $this->tenant('Revoked B', 'brand');
        $user = $this->user($a, 'revoked@test.dev');
        $this->membership($user, $a, Portal::Agency, default: true);
        $revoked = $this->membership($user, $b, Portal::App);
        $revoked->forceFill(['status' => 'revoked'])->save();

        $resolver = app(PortalResolver::class);
        $this->assertFalse($resolver->needsSwitcher($user));
        $this->assertSame('/agency', $resolver->landingPathFor($user, Portal::App));
    }

    /** A user with no membership is sent to onboarding, never guessed into someone's workspace. */
    public function test_a_user_without_any_membership_goes_to_onboarding(): void
    {
        $tenant = $this->tenant('Empty Co');
        $user = $this->user($tenant, 'empty@test.dev');

        $this->assertSame('/onboarding', app(PortalResolver::class)->landingPathFor($user));
    }

    public function test_portal_maps_account_type_to_its_starting_portal(): void
    {
        $this->assertSame(Portal::Agency, Portal::forAccountType('agency'));
        $this->assertSame(Portal::App, Portal::forAccountType('brand'));
        $this->assertSame(Portal::App, Portal::forAccountType(null));
        $this->assertSame(['admin', 'app', 'agency', 'influencers', 'portal'], Portal::values());

        // The owner's console is a portal, but never a membership — the owner belongs to no tenant.
        $this->assertSame(
            ['app', 'agency', 'influencers', 'portal'],
            array_map(fn (Portal $p) => $p->value, Portal::membershipPortals()),
        );
        $this->assertFalse(Portal::Admin->isMembershipPortal());
    }

    /**
     * Registration must create the membership, not leave the user relying on `users.tenant_id`.
     * Without it a brand-new account has no portal to land in and falls through to onboarding forever.
     */
    public function test_registration_creates_the_matching_membership(): void
    {
        $this->seed(PermissionSeeder::class);

        ['user' => $user] = $this->applyAndVerify([
            'tenant_name' => 'Agency Signup', 'name' => 'Owner', 'email' => 'agency.signup@test.dev',
            'account_type' => 'agency', 'service' => 'paid_media',
        ]);

        $membership = $user->memberships()->firstOrFail();

        $this->assertSame(Portal::Agency, $membership->portal);
        $this->assertSame('owner', $membership->role);
        $this->assertTrue($membership->is_default);
        $this->assertSame('/agency', app(PortalResolver::class)->landingPathFor($user));
    }

    /** An advertiser signup lands in the campaigns portal, not the agency one. */
    public function test_an_advertiser_signup_gets_the_app_portal(): void
    {
        $this->seed(PermissionSeeder::class);

        ['user' => $user] = $this->applyAndVerify([
            'tenant_name' => 'Brand Signup', 'name' => 'Owner', 'email' => 'brand.signup@test.dev',
            'account_type' => 'brand',
        ]);

        $this->assertSame(Portal::App, $user->memberships()->firstOrFail()->portal);
    }

    /**
     * Nobody ends up in a workspace without a membership.
     *
     * This used to run `MembershipBackfillSeeder`, which read `users.tenant_id` to place users that
     * predated memberships. Both are gone: the column was dropped in
     * `2026_07_31_090000_grant_memberships_then_drop_users_tenant_id`, which performs that same
     * placement one last time and REFUSES to drop the column if anyone would be left stranded.
     *
     * What remains testable — and what actually matters going forward — is that creating a user is
     * not what puts them in a workspace, and that the explicit grant is.
     */
    public function test_a_user_is_only_in_a_workspace_once_granted_one(): void
    {
        $tenant = $this->tenant('agency');
        $user = $this->user($tenant, 'granted@test.dev');

        // A bare user row places them nowhere. Read as "they belong to their tenant", this would be
        // the old column's behaviour and the bug it caused.
        $this->assertCount(0, $user->memberships()->get());

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $tenant, portal: Portal::Agency, role: 'member',
        ));

        $membership = $user->refresh()->memberships()->firstOrFail();
        $this->assertSame(Portal::Agency, $membership->portal);
        // Their first grant is where they land.
        $this->assertTrue($membership->is_default);
    }

    /** Re-running the seeder (or re-inviting) must not violate the unique index. */
    public function test_granting_the_same_membership_twice_is_a_no_op(): void
    {
        $tenant = $this->tenant('Idempotent Co', 'brand');
        $user = $this->user($tenant, 'idem@test.dev');
        $provisioner = app(MembershipProvisioner::class);

        $first = $provisioner->ensure($user, $tenant, Portal::App);
        $second = $provisioner->ensure($user, $tenant, Portal::App);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $user->memberships()->count());
    }

    /** Moving the default clears the previous one, because the database allows only one. */
    public function test_the_default_can_be_moved_between_memberships(): void
    {
        $a = $this->tenant('Move A', 'agency');
        $b = $this->tenant('Move B', 'brand');
        $user = $this->user($a, 'move@test.dev');
        $provisioner = app(MembershipProvisioner::class);

        $first = $provisioner->ensure($user, $a, Portal::Agency);
        $second = $provisioner->ensure($user, $b, Portal::App);
        $this->assertTrue($first->is_default);
        $this->assertFalse($second->is_default);

        $provisioner->makeDefault($second);

        $this->assertFalse($first->refresh()->is_default);
        $this->assertTrue($second->refresh()->is_default);
    }
    // ---- the five scenarios the schema must actually support (ADR 0002) ----

    private function workspace(Tenant $tenant, string $name): Workspace
    {
        return Workspace::create([
            'tenant_id' => $tenant->id, 'name' => $name, 'slug' => str($name)->slug()->value(),
        ]);
    }

    /** (1) One person, two tenants. */
    public function test_scenario_user_in_several_tenants(): void
    {
        $a = $this->tenant('Tenant A', 'agency');
        $b = $this->tenant('Tenant B', 'brand');
        $user = $this->user($a, 'two-tenants@test.dev');
        $p = app(MembershipProvisioner::class);

        $p->ensure($user, $a, Portal::Agency);
        $p->ensure($user, $b, Portal::App);

        $this->assertSame(2, $user->memberships()->count());
    }

    /**
     * (2) One person, two workspaces of the SAME tenant. The first schema rejected this outright:
     * the unique key left `workspace_id` out, so the second workspace violated it.
     */
    public function test_scenario_user_in_several_workspaces_of_one_tenant(): void
    {
        $tenant = $this->tenant('Multi WS', 'agency');
        $user = $this->user($tenant, 'two-workspaces@test.dev');
        $p = app(MembershipProvisioner::class);

        $p->ensure($user, $tenant, Portal::Agency, 'member', $this->workspace($tenant, 'North'));
        $p->ensure($user, $tenant, Portal::Agency, 'member', $this->workspace($tenant, 'South'));

        $this->assertSame(2, $user->memberships()->count());
    }

    /** (3) One person, several portals. */
    public function test_scenario_user_holding_several_portals(): void
    {
        $tenant = $this->tenant('Many Portals', 'agency');
        $user = $this->user($tenant, 'many-portals@test.dev');
        $p = app(MembershipProvisioner::class);

        // membershipPortals(), NOT cases(): minting an `admin` membership would put this user inside
        // a tenant AS the platform owner, which is the one combination the model must never produce.
        foreach (Portal::membershipPortals() as $portal) {
            $p->ensure($user, $tenant, $portal);
        }

        $this->assertSame(4, $user->memberships()->count());
    }

    /**
     * (4) An agency operator responsible for SEVERAL named clients — one membership, one entry in
     * the switcher, three clients. As a single column this needed three membership rows.
     */
    public function test_scenario_agency_member_scoped_to_several_clients(): void
    {
        $tenant = $this->tenant('Scoped Agency', 'agency');
        $user = $this->user($tenant, 'account-manager@test.dev');
        $p = app(MembershipProvisioner::class);
        $membership = $p->ensure($user, $tenant, Portal::Agency, 'account_manager');

        $clients = collect(['Alpha', 'Beta', 'Gamma'])->map(fn ($n) => $this->clientWorkspace($tenant, $n));
        $p->scopeToClients($membership, $clients->pluck('id')->map(fn ($i) => (string) $i)->all());

        $membership->refresh()->load('scopes');
        $this->assertCount(3, $membership->clientScopeIds());
        $this->assertTrue($membership->isClientScoped());
        // Still ONE membership: the switcher shows one workspace, not three.
        $this->assertSame(1, $user->memberships()->count());
    }

    /** (5) A single client inside the agency, confined to their own space. */
    public function test_scenario_one_client_inside_the_agency(): void
    {
        $tenant = $this->tenant('Host Agency', 'agency');
        $client = $this->clientWorkspace($tenant, 'Only Client');
        $user = $this->user($tenant, 'client-user@test.dev');
        $p = app(MembershipProvisioner::class);

        $membership = $p->ensure($user, $tenant, Portal::ClientPortal, 'client_viewer');
        $p->scopeToClients($membership, [(string) $client->id]);

        $membership->refresh()->load('scopes');
        $this->assertSame([(string) $client->id], $membership->clientScopeIds());
    }

    /** No scope rows means unrestricted within the tenant — an agency owner, not a locked-out user. */
    public function test_a_membership_without_scopes_is_unrestricted(): void
    {
        $tenant = $this->tenant('Owner Agency', 'agency');
        $user = $this->user($tenant, 'owner@test.dev');
        $membership = app(MembershipProvisioner::class)
            ->ensure($user, $tenant, Portal::Agency, 'owner');

        $this->assertSame([], $membership->clientScopeIds());
        $this->assertFalse($membership->isClientScoped());
    }

    /** Re-scoping replaces the set rather than appending, and an empty list restores full access. */
    public function test_scopes_can_be_narrowed_and_widened(): void
    {
        $tenant = $this->tenant('Rescope Co', 'agency');
        $user = $this->user($tenant, 'rescope@test.dev');
        $p = app(MembershipProvisioner::class);
        $membership = $p->ensure($user, $tenant, Portal::Agency);
        $a = $this->clientWorkspace($tenant, 'One');
        $b = $this->clientWorkspace($tenant, 'Two');

        $p->scopeToClients($membership, [(string) $a->id, (string) $b->id]);
        $this->assertCount(2, $membership->refresh()->load('scopes')->clientScopeIds());

        $p->scopeToClients($membership, [(string) $b->id]);
        $this->assertSame([(string) $b->id], $membership->refresh()->load('scopes')->clientScopeIds());

        $p->scopeToClients($membership, []);
        $this->assertSame([], $membership->refresh()->load('scopes')->clientScopeIds());
    }
}
