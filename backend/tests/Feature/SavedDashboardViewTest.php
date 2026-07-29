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
use Tests\TestCase;

/** DASH-010-E: saved dashboard views persist server-side, are user+tenant scoped, and keep one default. */
final class SavedDashboardViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function makeUser(Tenant $tenant, string $email): User
    {
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $u = User::create(['tenant_id' => $tenant->id, 'name' => 'U', 'email' => $email, 'password' => 'secret123']);
        $u->assignRole($role);
        app(TenantContext::class)->forget();

        return $u;
    }

    public function test_saved_view_crud_isolation_and_single_default(): void
    {
        $t1 = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $t2 = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);
        $u1 = $this->makeUser($t1, 'u1@a.test');
        $u2 = $this->makeUser($t1, 'u2@a.test');
        $u3 = $this->makeUser($t2, 'u3@b.test');

        // u1 creates a view with real filters.
        $created = $this->actingAs($u1, 'sanctum')->postJson('/api/v1/dashboard/saved-views', [
            'name' => 'My sales view',
            'filters' => ['provider' => ['meta'], 'objective' => ['sales']],
            'date_range' => ['days' => 30],
        ])->assertCreated()->json('data');
        $id = $created['id'];
        $this->assertSame('My sales view', $created['name']);

        // Apply/restore: the list returns the saved filters verbatim.
        $list = $this->actingAs($u1, 'sanctum')->getJson('/api/v1/dashboard/saved-views')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame(['meta'], $list[0]['filters']['provider']);

        // User isolation: another user in the same tenant sees nothing.
        $this->assertCount(0, $this->actingAs($u2, 'sanctum')->getJson('/api/v1/dashboard/saved-views')->assertOk()->json('data'));
        // Tenant isolation: a user in another tenant cannot read it → 404 (never mutated).
        $this->actingAs($u3, 'sanctum')->getJson("/api/v1/dashboard/saved-views/{$id}")->assertNotFound();
        $this->actingAs($u3, 'sanctum')->deleteJson("/api/v1/dashboard/saved-views/{$id}")->assertNotFound();

        // Rename.
        $this->actingAs($u1, 'sanctum')->patchJson("/api/v1/dashboard/saved-views/{$id}", ['name' => 'Renamed'])->assertOk();

        // Single default: create a 2nd view, set default on each in turn → only one default remains.
        $id2 = $this->actingAs($u1, 'sanctum')->postJson('/api/v1/dashboard/saved-views', ['name' => 'Second'])->assertCreated()->json('data.id');
        $this->actingAs($u1, 'sanctum')->postJson("/api/v1/dashboard/saved-views/{$id}/default")->assertOk();
        $this->actingAs($u1, 'sanctum')->postJson("/api/v1/dashboard/saved-views/{$id2}/default")->assertOk();
        $defaults = collect($this->actingAs($u1, 'sanctum')->getJson('/api/v1/dashboard/saved-views')->assertOk()->json('data'))->where('is_default', true);
        $this->assertCount(1, $defaults);
        $this->assertSame($id2, $defaults->first()['id']);

        // Delete.
        $this->actingAs($u1, 'sanctum')->deleteJson("/api/v1/dashboard/saved-views/{$id}")->assertOk();
        $this->assertCount(1, $this->actingAs($u1, 'sanctum')->getJson('/api/v1/dashboard/saved-views')->assertOk()->json('data'));
    }
}
