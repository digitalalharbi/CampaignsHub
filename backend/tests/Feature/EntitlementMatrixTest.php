<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The entitlement matrix, enforced at the API rather than hidden in the nav — and keyed on the
 * PORTAL, not on the workspace's account type (REG-001).
 *
 * This file used to assert the opposite, and that is why it is worth reading. Its claims were
 * "a `brand` workspace is denied the client portfolio" and "a `freelancer` keeps the full agency
 * menu": the fork ran on account type, `personal` was the permissive branch, and `personal` was
 * also the fallback for a workspace whose type was never set. So a freelancer, an in-house team,
 * and every self-registered account that skipped the question were all handed the agency console
 * inside the advertiser portal — with a passing test saying so.
 *
 * The claims below are portal-shaped instead, which is both stricter and simpler: `app` does not
 * offer `clients` or `requests` to anyone, and `agency` offers them to its members. The account
 * type is now proven IRRELEVANT to this question — the loop over every type is the point of
 * `test_no_account_type_opens_agency_resources_from_the_advertiser_portal`.
 */
final class EntitlementMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** An owner with the FULL permission catalogue, so every refusal below is the portal's doing. */
    private function ownerFor(?string $accountType, string $email, Portal $portal): User
    {
        $tenant = Tenant::create([
            'name' => ucfirst((string) $accountType ?: 'Unset'),
            'slug' => ($accountType ?? 'unset').'-'.uniqid(),
            'status' => 'active',
            'account_type' => $accountType,
            'enabled_modules' => ['paid_media'],
            'onboarding_step' => 'done',
            'onboarding_completed_at' => now(),
        ]);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'O', 'email' => $email, 'password' => Hash::make('secret1234')]);
        $this->grantMembership($user, $tenant, $portal);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($role);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_the_agency_portal_reaches_the_agency_resources(): void
    {
        $agency = $this->ownerFor('agency', 'agency@a.test', Portal::Agency);

        $this->actingAs($agency, 'sanctum')->getJson('/api/v1/app/clients')->assertOk();
        $this->actingAs($agency, 'sanctum')->getJson('/api/v1/app/requests')->assertOk();
    }

    public function test_the_advertiser_portal_is_refused_the_agency_resources(): void
    {
        $advertiser = $this->ownerFor('brand', 'brand@a.test', Portal::App);

        $this->actingAs($advertiser, 'sanctum')->getJson('/api/v1/app/clients')->assertForbidden();
        $this->actingAs($advertiser, 'sanctum')->getJson('/api/v1/app/requests')->assertForbidden();
    }

    /**
     * The regression, stated as a test.
     *
     * Every account type — including none at all, which is what self-registration produces when the
     * visitor never answers the question — must be refused the agency's resources from inside the
     * advertiser portal. `freelancer`, `in_house_team` and `null` are the three that used to pass
     * this boundary, so they are named explicitly rather than left to a generic case.
     */
    public function test_no_account_type_opens_agency_resources_from_the_advertiser_portal(): void
    {
        foreach ([null, 'freelancer', 'in_house_team', 'brand', 'self_serve_company', 'agency'] as $i => $type) {
            $user = $this->ownerFor($type, 'adv'.$i.'@a.test', Portal::App);

            $this->actingAs($user, 'sanctum')->getJson('/api/v1/app/clients')
                ->assertForbidden("account_type={$type} must not reach the client portfolio from /app");
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/app/requests')
                ->assertForbidden("account_type={$type} must not reach the requests inbox from /app");
        }
    }

    /** A workspace's own settings stay reachable from either portal — this is not a lockout. */
    public function test_both_portals_keep_their_own_workspace_settings(): void
    {
        $advertiser = $this->ownerFor('brand', 'settings-app@a.test', Portal::App);
        $this->actingAs($advertiser, 'sanctum')->getJson('/api/v1/settings/organization')->assertOk();

        $agency = $this->ownerFor('agency', 'settings-agency@a.test', Portal::Agency);
        $this->actingAs($agency, 'sanctum')->getJson('/api/v1/settings/organization')->assertOk();
    }

    /**
     * The nav the boot payload carries is the ACTIVE portal's, not the account type's.
     *
     * An `agency` workspace whose member holds an advertiser membership must be described by the
     * advertiser's sections. Deriving it from the tenant instead is precisely what put an agency
     * rail in front of people who were not in the agency portal.
     */
    public function test_the_boot_payload_describes_the_portal_not_the_account_type(): void
    {
        $user = $this->ownerFor('agency', 'nav@a.test', Portal::App);

        $nav = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')
            ->assertOk()->json('data.user.account.nav');

        $this->assertNotContains('clients', $nav, 'the advertiser portal must not offer a client roster');
        $this->assertNotContains('requests', $nav, 'the advertiser portal must not offer a requests inbox');
        $this->assertContains('campaigns', $nav);
        $this->assertContains('subscriptions', $nav, 'the advertiser pays for their own plan');
    }
}
