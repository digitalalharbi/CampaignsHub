<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Entitlement enforcement matrix (Fail-Closed at the API, not just hidden nav). Agency-only top-level
 * resources — the client PORTFOLIO and the public REQUESTS inbox ("other clients / public requests") — are
 * denied to COMPANY workspaces and allowed to PERSONAL ones. Shared project infrastructure and the company's
 * own resources (settings) remain reachable so a company can still run its own campaigns.
 */
final class EntitlementMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function ownerFor(string $accountType, string $email): User
    {
        $tenant = Tenant::create([
            'name' => ucfirst($accountType), 'slug' => $accountType.'-'.uniqid(), 'status' => 'active',
            'account_type' => $accountType, 'enabled_modules' => ['paid_media'],
            'onboarding_step' => 'done', 'onboarding_completed_at' => now(),
        ]);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'O', 'email' => $email, 'password' => Hash::make('secret1234')]);
        $this->grantMembership($user, $tenant);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($role);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_company_is_denied_agency_only_resources_but_keeps_its_own(): void
    {
        $company = $this->ownerFor('brand', 'brand@a.test');

        // Agency-only (other clients + public requests inbox) → 403.
        $this->actingAs($company, 'sanctum')->getJson('/api/v1/app/clients')->assertForbidden();
        $this->actingAs($company, 'sanctum')->getJson('/api/v1/app/requests')->assertForbidden();

        // Shared / company-allowed → reachable (settings for the company's own workspace).
        $this->actingAs($company, 'sanctum')->getJson('/api/v1/settings/organization')->assertOk();
    }

    public function test_personal_workspace_keeps_the_full_agency_menu(): void
    {
        $personal = $this->ownerFor('agency', 'agency@a.test');

        $this->actingAs($personal, 'sanctum')->getJson('/api/v1/app/clients')->assertOk();
        $this->actingAs($personal, 'sanctum')->getJson('/api/v1/app/requests')->assertOk();
        $this->actingAs($personal, 'sanctum')->getJson('/api/v1/settings/organization')->assertOk();
    }

    public function test_freelancer_and_in_house_team_are_personal(): void
    {
        foreach (['freelancer', 'in_house_team'] as $type) {
            $user = $this->ownerFor($type, $type.'@a.test');
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/app/clients')->assertOk();
        }
    }

    public function test_self_serve_company_is_company(): void
    {
        $user = $this->ownerFor('self_serve_company', 'ssc@a.test');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/app/clients')->assertForbidden();
    }
}
