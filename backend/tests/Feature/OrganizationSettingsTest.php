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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Organization (General) settings: read for members, validated + audited writes gated by settings.manage. */
final class OrganizationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
    }

    public function test_show_returns_defaults_merged(): void
    {
        Sanctum::actingAs($this->owner);
        $res = $this->getJson('/api/v1/settings/organization');
        $res->assertOk()
            ->assertJsonPath('data.name', 'Acme')
            ->assertJsonPath('data.general.default_currency', 'SAR')
            ->assertJsonPath('data.general.account_type', 'agency');
    }

    public function test_update_persists_and_validates(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/settings/organization', [
            'name' => 'Acme Media',
            'general' => [
                'account_type' => 'freelancer', 'country' => 'AE', 'default_locale' => 'en',
                'default_currency' => 'AED', 'timezone' => 'Asia/Dubai', 'date_format' => 'YYYY-MM-DD',
                'number_format' => 'latin', 'fiscal_year_start_month' => 4,
            ],
        ])->assertOk()->assertJsonPath('data.general.default_currency', 'AED');

        $this->assertSame('Acme Media', $this->tenant->fresh()->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.organization.updated']);

        // Invalid timezone rejected.
        $this->putJson('/api/v1/settings/organization', [
            'name' => 'X', 'general' => ['account_type' => 'agency', 'country' => 'SA', 'default_locale' => 'ar',
                'default_currency' => 'SAR', 'timezone' => 'Not/Real', 'date_format' => 'YYYY-MM-DD',
                'number_format' => 'latin', 'fiscal_year_start_month' => 1],
        ])->assertStatus(422);
    }

    public function test_update_forbidden_without_permission(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v']);
        $viewer = User::create(['name' => 'V', 'email' => 'v@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $viewer->assignRole($role);
        Sanctum::actingAs($viewer);

        $this->putJson('/api/v1/settings/organization', ['name' => 'X', 'general' => []])->assertForbidden();
    }
}
