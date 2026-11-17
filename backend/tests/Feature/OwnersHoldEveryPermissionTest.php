<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NAV-OWNER-001 — an owner of an older workspace, whose role predates some permissions, gets all of them back, so the
 * side menu shows «التحليلات» «الحملات» «الميزانيات» again. Other roles are left as their owner set them.
 */
final class OwnersHoldEveryPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_owner_role_holds_the_whole_catalogue_and_other_roles_are_untouched(): void
    {
        $this->seed(PermissionSeeder::class);
        $tenant = Tenant::create(['name' => 'Old', 'slug' => 'old', 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $tenant->id);
        $owner = Role::create(['tenant_id' => $tenant->id, 'name' => 'Tenant Owner', 'slug' => 'tenant-owner', 'is_system' => true]);
        $owner->givePermissionTo('clients.view');
        $viewer = Role::create(['tenant_id' => $tenant->id, 'name' => 'Viewer', 'slug' => 'viewer']);
        $viewer->givePermissionTo('clients.view');

        $migration = require database_path('migrations/2026_11_22_100000_owners_hold_every_permission.php');
        $migration->up();
        $migration->up(); // idempotent

        $all = Permission::query()->count();
        $this->assertSame($all, $owner->permissions()->count());
        foreach (['analytics.view', 'campaigns.view', 'budget.view'] as $key) {
            $this->assertTrue($owner->permissions()->where('key', $key)->exists(), $key);
        }
        $this->assertSame(1, $viewer->permissions()->count());
    }
}
