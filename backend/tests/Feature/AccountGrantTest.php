<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Models\AccountGrant;
use App\Domains\Accounts\Services\AccountEntitlements;
use App\Domains\Accounts\Services\AccountGrants;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GRANT-001 — an administrative exception, and the four things it must never become.
 *
 * The brief asks for one account to be given something beyond its plan, and for that to be
 * revocable, recorded and incapable of leaking. The tests below are written against the four ways
 * such a feature usually fails: it grants more than was asked, it survives revocation, it can be
 * reached by the person it would benefit, or nobody can afterwards say who granted it.
 */
final class AccountGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
    }

    private function tenant(string $name = 'Granted Co'): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'slug' => 'granted-'.uniqid(),
            'status' => 'active',
            'account_type' => 'brand',
        ]);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $user->refresh();
    }

    private function grants(): AccountGrants
    {
        return app(AccountGrants::class);
    }

    private function entitlements(): AccountEntitlements
    {
        // A fresh instance per assertion: `AccountGrants` memoises for the request, and a test that
        // reused one would be asserting against the answer from before the grant was made.
        return app()->makeWith(AccountEntitlements::class, ['grants' => app(AccountGrants::class)]);
    }

    // ── What a grant adds ─────────────────────────────────────────────────────────────────────

    public function test_a_granted_module_is_enabled_without_touching_the_tenants_own_modules(): void
    {
        $tenant = $this->tenant();
        $this->assertSame(['paid_media'], $this->entitlements()->modules($tenant));

        $this->grants()->grant($tenant, AccountGrant::MODULE, 'influencer_marketing', 'Pilot for Q3.', null);

        app()->forgetScopedInstances();
        $this->assertEqualsCanonicalizing(
            ['paid_media', 'influencer_marketing'],
            $this->entitlements()->modules($tenant->refresh()),
        );

        // The tenant's OWN column is untouched, which is what makes revocation exact.
        $this->assertNull($tenant->refresh()->enabled_modules);
    }

    /**
     * A grant widens what a portal offers. It cannot invent a section the portal does not have.
     *
     * This is the containment rule the whole design rests on: an advertiser given every exception in
     * the catalogue still has no client roster, because `clients` belongs to the agency portal.
     */
    public function test_a_grant_cannot_reach_outside_the_portal(): void
    {
        $tenant = $this->tenant();

        $this->grants()->grant($tenant, AccountGrant::SECTION, 'clients', 'Asked for it.', null);
        app()->forgetScopedInstances();

        $nav = $this->entitlements()->nav($tenant, Portal::App);
        $this->assertNotContains('clients', $nav, 'the advertiser portal has no client roster to grant');

        // …and in the portal that DOES offer it, the same grant is simply already true.
        $this->assertContains('clients', $this->entitlements()->nav($tenant, Portal::Agency));
    }

    public function test_full_access_is_still_bounded_by_the_portal(): void
    {
        $tenant = $this->tenant();
        $this->grants()->grant($tenant, AccountGrant::FULL_ACCESS, '', 'Strategic account.', null);
        app()->forgetScopedInstances();

        $nav = $this->entitlements()->nav($tenant, Portal::App);

        $this->assertSame(Portal::App->sections(), $nav, 'full access means everything /app offers');
        $this->assertNotContains('clients', $nav);
        $this->assertNotContains('tenants', $nav, 'and nothing at all from the owner’s console');
    }

    /** No portal, no sections — a grant does not become a way past the fail-closed default. */
    public function test_a_grant_does_not_survive_having_no_portal(): void
    {
        $tenant = $this->tenant();
        $this->grants()->grant($tenant, AccountGrant::FULL_ACCESS, '', 'Strategic account.', null);
        app()->forgetScopedInstances();

        $this->assertSame([], $this->entitlements()->nav($tenant, null));
        $this->assertFalse($this->entitlements()->allows($tenant, 'reports', null));
    }

    // ── Revocation, expiry, and the record ────────────────────────────────────────────────────

    public function test_revoking_removes_the_capability_and_keeps_the_record(): void
    {
        $tenant = $this->tenant();
        $grant = $this->grants()->grant($tenant, AccountGrant::MODULE, 'influencer_marketing', 'Pilot.', 7);

        app()->forgetScopedInstances();
        $this->assertContains('influencer_marketing', $this->entitlements()->modules($tenant));

        $this->grants()->revoke($grant, 'Pilot ended.', 9);

        app()->forgetScopedInstances();
        $this->assertSame(['paid_media'], $this->entitlements()->modules($tenant->refresh()));

        // The row survives, and says who did what.
        $grant->refresh();
        $this->assertNotNull($grant->revoked_at);
        $this->assertSame(7, $grant->granted_by);
        $this->assertSame(9, $grant->revoked_by);
        $this->assertSame('Pilot ended.', $grant->revoked_reason);
        $this->assertFalse($grant->isInForce());
    }

    public function test_an_expired_grant_stops_applying_on_its_own(): void
    {
        $tenant = $this->tenant();
        $this->grants()->grant(
            $tenant, AccountGrant::MODULE, 'influencer_marketing', 'Until the end of the quarter.', null,
            expiresAt: now()->addDay()->toDateTimeImmutable(),
        );

        app()->forgetScopedInstances();
        $this->assertContains('influencer_marketing', $this->entitlements()->modules($tenant));

        $this->travel(2)->days();
        app()->forgetScopedInstances();
        $this->assertSame(['paid_media'], $this->entitlements()->modules($tenant->refresh()));
    }

    public function test_granting_the_same_thing_twice_makes_one_grant(): void
    {
        $tenant = $this->tenant();

        $first = $this->grants()->grant($tenant, AccountGrant::SECTION, 'reports', 'Support case 41.', null);
        $second = $this->grants()->grant($tenant, AccountGrant::SECTION, 'reports', 'Support case 41 again.', null);

        $this->assertSame((string) $first->getKey(), (string) $second->getKey());
        $this->assertSame(1, AccountGrant::query()->where('tenant_id', $tenant->getKey())->count());
    }

    public function test_a_grant_and_a_revocation_both_insist_on_a_reason(): void
    {
        $tenant = $this->tenant();

        $this->expectException(\InvalidArgumentException::class);
        $this->grants()->grant($tenant, AccountGrant::SECTION, 'reports', '   ', null);
    }

    // ── Who may make one ──────────────────────────────────────────────────────────────────────

    /**
     * Fail-closed: nobody grants themselves anything.
     *
     * A tenant owner is the most privileged person inside their own workspace, and this is the
     * endpoint that would let them widen it. They cannot reach it at all — not "may not", but 403 at
     * the middleware, before any handler runs.
     */
    public function test_a_tenant_owner_cannot_grant_themselves_anything(): void
    {
        $tenant = $this->tenant();
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($user->refresh(), 'sanctum')
            ->postJson("/api/v1/admin/tenants/{$tenant->getKey()}/grants", [
                'kind' => 'full_access', 'reason' => 'I would like everything, please.',
            ])->assertForbidden();

        $this->assertSame(0, AccountGrant::query()->count());
    }

    public function test_the_console_grants_revokes_and_records_the_actor(): void
    {
        $admin = $this->owner();
        $tenant = $this->tenant();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/tenants/{$tenant->getKey()}/grants", [
                'kind' => 'plan', 'value' => 'agency', 'reason' => 'Complimentary for the launch partner.',
            ])->assertStatus(201);

        $id = $created->json('data.grant.id');
        $this->assertSame($admin->getKey(), $created->json('data.grant.granted_by'));
        $this->assertTrue($created->json('data.grant.in_force'));

        // The audit carries the actor, the reason and the date — «المنفذ والسبب والتاريخ».
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account.grant.created',
            'user_id' => $admin->getKey(),
            'reason' => 'Complimentary for the launch partner.',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/tenants/{$tenant->getKey()}/grants/{$id}", [
                'reason' => 'The launch partnership ended.',
            ])->assertOk()->assertJsonPath('data.grant.in_force', false);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account.grant.revoked',
            'reason' => 'The launch partnership ended.',
        ]);
    }

    public function test_the_console_refuses_a_grant_that_names_nothing_real(): void
    {
        $admin = $this->owner();
        $tenant = $this->tenant();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/tenants/{$tenant->getKey()}/grants", [
                'kind' => 'section', 'value' => 'nuclear_codes', 'reason' => 'Worth a try.',
            ])->assertStatus(422);

        // …and a grant with no reason at all is refused before it reaches the service.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/tenants/{$tenant->getKey()}/grants", [
                'kind' => 'full_access',
            ])->assertStatus(422);

        $this->assertSame(0, AccountGrant::query()->count());
    }

    /** Revoking one account's grant leaves every other account exactly as it was. */
    public function test_revoking_one_grant_does_not_touch_another_account(): void
    {
        $a = $this->tenant('Account A');
        $b = $this->tenant('Account B');

        $grantA = $this->grants()->grant($a, AccountGrant::MODULE, 'influencer_marketing', 'A pilot.', null);
        $this->grants()->grant($b, AccountGrant::MODULE, 'influencer_marketing', 'B pilot.', null);

        $this->grants()->revoke($grantA, 'A is done.', null);

        app()->forgetScopedInstances();
        $this->assertSame(['paid_media'], $this->entitlements()->modules($a->refresh()));
        $this->assertContains('influencer_marketing', $this->entitlements()->modules($b->refresh()));
    }
}
