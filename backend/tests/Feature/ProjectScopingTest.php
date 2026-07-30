<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-project authorization scoping. A user without `projects.view.all` is confined to the projects
 * they are an active member of — for both the project list and every project-scoped route — while an
 * agency-wide viewer (`projects.view.all`) sees the whole tenant. Proves authorized access AND
 * cross-project denial, not merely a whole-system block.
 */
final class ProjectScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $agencyOwner;

    private User $clientViewer;

    private Project $authorized;

    private Project $forbidden;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $ownerRole = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all()); // includes projects.view.all
        $this->agencyOwner = User::create(['tenant_id' => $tenant->id, 'name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->agencyOwner, $tenant);
        $this->agencyOwner->assignRole($ownerRole);

        // Client viewer: projects.view + campaigns.view, but NOT projects.view.all → scoped by membership.
        $viewerRole = Role::create(['tenant_id' => $tenant->id, 'name' => 'Client Viewer', 'slug' => 'client-viewer']);
        $viewerRole->givePermissionTo('projects.view', 'campaigns.view');
        $this->clientViewer = User::create(['tenant_id' => $tenant->id, 'name' => 'V', 'email' => 'v@client.test', 'password' => 'secret123']);
        $this->grantMembership($this->clientViewer, $tenant);
        $this->clientViewer->assignRole($viewerRole);

        $ws = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client', 'mode' => 'managed']);
        $this->authorized = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Authorized', 'status' => 'active']);
        $this->forbidden = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Forbidden', 'status' => 'active']);

        // The viewer is a member of the authorized project only.
        ProjectMembership::create([
            'tenant_id' => $tenant->id,
            'project_id' => $this->authorized->id,
            'user_id' => $this->clientViewer->id,
            'role' => 'client_viewer',
            'status' => 'active',
        ]);

        app(TenantContext::class)->forget();
    }

    public function test_client_viewer_project_list_shows_only_member_projects(): void
    {
        $names = $this->actingAs($this->clientViewer, 'sanctum')
            ->getJson('/api/v1/projects')->assertOk()->json('data.*.name');

        $this->assertContains('Authorized', $names);
        $this->assertNotContains('Forbidden', $names); // the other project must not leak into the switcher
    }

    public function test_agency_wide_viewer_sees_all_projects(): void
    {
        $names = $this->actingAs($this->agencyOwner, 'sanctum')
            ->getJson('/api/v1/projects')->assertOk()->json('data.*.name');

        $this->assertContains('Authorized', $names);
        $this->assertContains('Forbidden', $names);
    }

    public function test_client_viewer_can_access_authorized_project_campaigns(): void
    {
        $this->actingAs($this->clientViewer, 'sanctum')
            ->getJson("/api/v1/projects/{$this->authorized->id}/campaigns")
            ->assertOk();
    }

    public function test_client_viewer_is_denied_on_a_non_member_project(): void
    {
        $this->actingAs($this->clientViewer, 'sanctum')
            ->getJson("/api/v1/projects/{$this->forbidden->id}/campaigns")
            ->assertForbidden(); // 403 — member of authorized only
    }

    public function test_swapping_the_uuid_does_not_leak_another_project(): void
    {
        // Same route, hand-swapped id → still denied; no data from the other project is exposed.
        $this->actingAs($this->clientViewer, 'sanctum')
            ->getJson("/api/v1/projects/{$this->forbidden->id}/campaigns")
            ->assertForbidden();

        // A wholly unknown id fails closed as not-found (existence not leaked).
        $this->actingAs($this->clientViewer, 'sanctum')
            ->getJson('/api/v1/projects/00000000-0000-0000-0000-000000000000/campaigns')
            ->assertNotFound();
    }

    public function test_client_viewer_cannot_create_a_campaign_even_on_authorized_project(): void
    {
        $this->actingAs($this->clientViewer, 'sanctum')
            ->postJson("/api/v1/projects/{$this->authorized->id}/campaigns", ['name' => 'Nope'])
            ->assertForbidden(); // lacks campaigns.create
    }
}
