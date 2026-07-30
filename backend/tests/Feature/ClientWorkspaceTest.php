<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClientWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Scope is request-scoped since ADR 0002; these tests assert on persisted rows,
        // not on what one tenant can see, so they read across tenants deliberately.
        $this->assertingAcrossTenants();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);
    }

    public function test_can_create_workspace_in_each_mode_and_add_project(): void
    {
        app(TenantContext::class)->forget();

        foreach (['managed', 'collaborative', 'self_service'] as $mode) {
            $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/client-workspaces', [
                'name' => "Client {$mode}",
                'mode' => $mode,
            ])->assertCreated()->assertJsonPath('data.mode', $mode);
        }

        $workspace = ClientWorkspace::where('mode', 'managed')->firstOrFail();

        app(TenantContext::class)->forget();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/projects', [
            'client_workspace_id' => $workspace->id,
            'name' => 'Launch Q3',
        ])->assertCreated()->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('projects', ['name' => 'Launch Q3', 'client_workspace_id' => $workspace->id]);
    }

    public function test_workspace_mode_is_validated(): void
    {
        app(TenantContext::class)->forget();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/client-workspaces', [
            'name' => 'X', 'mode' => 'invalid',
        ])->assertStatus(422)->assertJsonValidationErrors(['mode']);
    }

    public function test_workspaces_and_projects_are_isolated_between_tenants(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        ClientWorkspace::create(['name' => 'Ours', 'slug' => 'ours', 'mode' => 'managed']);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        ClientWorkspace::create(['name' => 'Theirs', 'slug' => 'theirs', 'mode' => 'managed']);
        $otherUser = User::create(['tenant_id' => $other->id, 'name' => 'O2', 'email' => 'o2@other.test', 'password' => 'secret123']);
        $this->grantMembership($otherUser, $other);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo('workspaces.view');
        $otherUser->assignRole($role);

        app(TenantContext::class)->forget();
        $this->actingAs($otherUser, 'sanctum')->getJson('/api/v1/client-workspaces')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Theirs');
    }
}
