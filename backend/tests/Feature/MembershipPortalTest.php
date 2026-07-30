<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function user(Tenant $tenant, string $email): User
    {
        return User::create([
            'tenant_id' => $tenant->id, 'name' => 'U', 'email' => $email, 'password' => 'secret123',
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
        $this->assertSame(['app', 'agency', 'influencers', 'portal'], Portal::values());
    }
    /**
     * Registration must create the membership, not leave the user relying on `users.tenant_id`.
     * Without it a brand-new account has no portal to land in and falls through to onboarding forever.
     */
    public function test_registration_creates_the_matching_membership(): void
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->postJson('/api/v1/auth/register', [
                'tenant_name' => 'Agency Signup', 'name' => 'Owner', 'email' => 'agency.signup@test.dev',
                'password' => 'secret1234', 'password_confirmation' => 'secret1234',
                'account_type' => 'agency', 'service' => 'paid_media',
            ])->assertCreated();

        $user = User::where('email', 'agency.signup@test.dev')->firstOrFail();
        $membership = $user->memberships()->firstOrFail();

        $this->assertSame(Portal::Agency, $membership->portal);
        $this->assertSame('owner', $membership->role);
        $this->assertTrue($membership->is_default);
        $this->assertSame('/agency', app(PortalResolver::class)->landingPathFor($user));
    }

    /** An advertiser signup lands in the campaigns portal, not the agency one. */
    public function test_an_advertiser_signup_gets_the_app_portal(): void
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->postJson('/api/v1/auth/register', [
                'tenant_name' => 'Brand Signup', 'name' => 'Owner', 'email' => 'brand.signup@test.dev',
                'password' => 'secret1234', 'password_confirmation' => 'secret1234',
                'account_type' => 'brand',
            ])->assertCreated();

        $user = User::where('email', 'brand.signup@test.dev')->firstOrFail();
        $this->assertSame(Portal::App, $user->memberships()->firstOrFail()->portal);
    }
    /**
     * A clean install must not strand anyone. The migration's backfill runs while `users` is still
     * empty, so without the seeder every seeded account would have no portal and fall through to
     * onboarding — which is exactly what `migrate:fresh --seed` produced before this was added.
     */
    public function test_the_backfill_seeder_leaves_no_tenant_user_without_a_membership(): void
    {
        $tenant = $this->tenant('Seeded Co', 'agency');
        $stranded = $this->user($tenant, 'stranded@test.dev');
        $platform = User::create([
            'tenant_id' => null, 'name' => 'Platform', 'email' => 'platform@test.dev',
            'password' => 'secret123', 'is_platform_admin' => true,
        ]);

        $this->assertCount(0, $stranded->memberships()->get());

        $this->seed(\Database\Seeders\MembershipBackfillSeeder::class);

        $membership = $stranded->refresh()->memberships()->firstOrFail();
        $this->assertSame(Portal::Agency, $membership->portal);
        $this->assertTrue($membership->is_default);
        // A platform user belongs to no tenant, so it belongs to no portal either.
        $this->assertCount(0, $platform->refresh()->memberships()->get());
    }

    /** Re-running the seeder (or re-inviting) must not violate the unique index. */
    public function test_granting_the_same_membership_twice_is_a_no_op(): void
    {
        $tenant = $this->tenant('Idempotent Co', 'brand');
        $user = $this->user($tenant, 'idem@test.dev');
        $provisioner = app(\App\Domains\Tenancy\Services\MembershipProvisioner::class);

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
        $provisioner = app(\App\Domains\Tenancy\Services\MembershipProvisioner::class);

        $first = $provisioner->ensure($user, $a, Portal::Agency);
        $second = $provisioner->ensure($user, $b, Portal::App);
        $this->assertTrue($first->is_default);
        $this->assertFalse($second->is_default);

        $provisioner->makeDefault($second);

        $this->assertFalse($first->refresh()->is_default);
        $this->assertTrue($second->refresh()->is_default);
    }
}
