<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CLIENT-UNIT-001 — «عميل جديد» is one form: the client, its internal project and its portal.
 * No id, route or isolation rule changes; the project is an ordinary project flagged `internal`.
 */
final class ClientUnitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertingAcrossTenants();
        $this->seed(PermissionSeeder::class);
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant = $tenant, Portal::Agency);
        $this->user->assignRole($role);
    }

    private Tenant $tenant;

    public function test_one_form_makes_the_client_its_internal_project_and_its_portal(): void
    {
        app(TenantContext::class)->forget();

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/client-workspaces', [
            'name' => 'نادي الاتفاق',
            'mode' => 'managed',
            'default_currency' => 'sar',
            'account_manager_id' => $this->user->id,
            'create_project' => true,
        ])->assertCreated();

        $client = ClientWorkspace::where('name', 'نادي الاتفاق')->firstOrFail();
        $response->assertJsonPath('data.project_id', Project::where('client_workspace_id', $client->id)->value('id'))
            ->assertJsonPath('data.portal_path', '/portal/'.$client->slug);

        $this->assertSame('SAR', $client->default_currency);
        $project = Project::where('client_workspace_id', $client->id)->firstOrFail();
        $this->assertSame($this->user->id, $project->account_manager_id);
        $this->assertTrue($project->meta['internal']);
    }

    public function test_the_projects_list_says_which_project_is_internal(): void
    {
        app(TenantContext::class)->forget();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/client-workspaces', [
            'name' => 'Solo', 'mode' => 'managed', 'create_project' => true,
        ])->assertCreated();

        app(TenantContext::class)->forget();
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/projects')
            ->assertOk()->assertJsonPath('data.0.internal', true);
    }

    public function test_without_the_flag_nothing_but_the_client_is_made(): void
    {
        app(TenantContext::class)->forget();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/client-workspaces', [
            'name' => 'Bare', 'mode' => 'managed',
        ])->assertCreated()->assertJsonPath('data.project_id', null);

        $this->assertSame(0, Project::count());
    }

    public function test_a_bad_manager_makes_nothing(): void
    {
        app(TenantContext::class)->forget();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/client-workspaces', [
            'name' => 'Half', 'mode' => 'managed', 'create_project' => true, 'account_manager_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors(['account_manager_id']);

        $this->assertSame(0, ClientWorkspace::where('name', 'Half')->count());
        $this->assertSame(0, Project::count());
    }
}
