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

final class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['tenant_id' => $tenant->id, 'name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $tenant);
        $this->user->assignRole($role);
    }

    public function test_index_lists_connectors_with_status(): void
    {
        app(TenantContext::class)->forget();

        $data = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/integrations')
            ->assertOk()
            ->json('data');

        $keys = array_column($data, 'key');
        $this->assertContains('meta', $keys);
        $this->assertContains('sandbox', $keys);

        $meta = collect($data)->firstWhere('key', 'meta');
        $this->assertSame('awaiting_credentials', $meta['status']);
    }

    public function test_real_connector_connect_is_awaiting_credentials(): void
    {
        app(TenantContext::class)->forget();

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/integrations/meta/connect')
            ->assertStatus(422)
            ->assertJsonPath('meta.status', 'awaiting_credentials');

        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_sandbox_connect_and_sync(): void
    {
        app(TenantContext::class)->forget();

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/integrations/sandbox/connect')
            ->assertCreated()
            ->assertJsonPath('data.status', 'connected');

        $this->assertDatabaseHas('integrations', ['connector_key' => 'sandbox', 'status' => 'connected']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'integration.connect']);

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/integrations/sandbox/sync')
            ->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.count', 2);
    }

    public function test_health_check_endpoint(): void
    {
        app(TenantContext::class)->forget();

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/integrations/sandbox/health')
            ->assertOk()
            ->assertJsonPath('data.healthy', true);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/integrations/meta/health')
            ->assertOk()
            ->assertJsonPath('data.healthy', false);
    }
}
