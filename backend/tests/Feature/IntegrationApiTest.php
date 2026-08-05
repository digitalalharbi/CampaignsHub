<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
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
        $this->user = User::create(['name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
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

    /**
     * PROVCFG-001 — the tenant's board says a platform is not ready and never says why.
     *
     * `missing: ['developer_token']` used to travel with every awaiting row. It is an instruction for
     * the console at `/admin` addressed to the wrong reader: a customer cannot obtain a developer
     * token for our OAuth app, and the shape of our provider registration is not theirs to be told.
     */
    public function test_the_tenant_board_never_names_a_system_credential(): void
    {
        app(TenantContext::class)->forget();

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/integrations')->assertOk();

        $this->assertStringNotContainsString('developer_token', $response->getContent());
        $this->assertStringNotContainsString('organization_id', $response->getContent());

        foreach ($response->json('data') as $row) {
            $this->assertArrayNotHasKey('missing', $row);
        }
    }

    /**
     * A provider the operator suspended reads as `unavailable`, not as one waiting for keys.
     *
     * The two are different facts — a setup that has not happened, and a decision that has — and the
     * order matters: a complete-but-suspended provider must not be offered a connect button the
     * OAuth start is going to refuse.
     */
    public function test_a_suspended_provider_reads_as_unavailable_rather_than_connectable(): void
    {
        app(TenantContext::class)->forget();

        config()->set('ad_platforms.platforms.meta.client_id', 'id');
        config()->set('ad_platforms.platforms.meta.client_secret', 'secret');
        app(ProviderConfigurationService::class)->setEnabled('meta', false);

        $data = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/integrations')->assertOk()->json('data');
        $meta = collect($data)->firstWhere('key', 'meta');

        $this->assertSame('unavailable', $meta['state']);
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
