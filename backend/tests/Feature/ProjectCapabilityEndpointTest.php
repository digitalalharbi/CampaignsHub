<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TEAM-PROJECT-RBAC-001 — what a person may do in a project, said once, for drawing the rail.
 *
 * The navigation is a static list today: every link is offered to everybody, so a media buyer on a
 * client's project is shown «Team & permissions», clicks it, and is refused. That reads as a broken
 * product rather than as a boundary, and it teaches a reader to distrust the rail.
 *
 * This endpoint is NOT authorisation and these tests say so where it matters: it is read to decide
 * what to DRAW, while every route it describes states and enforces its own capability. The last test
 * is the one that keeps that honest.
 */
final class ProjectCapabilityEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-caps', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-caps', 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
    }

    private function member(string $email, ?string $projectRole, string ...$permissions): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        Membership::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'portal' => 'agency', 'status' => 'active',
        ]);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => $email, 'slug' => 'r-'.uniqid()]);
        foreach ($permissions as $permission) {
            if (Permission::where('key', $permission)->exists()) {
                $role->givePermissionTo($permission);
            }
        }
        $user->assignRole($role);

        if ($projectRole !== null) {
            ProjectMembership::create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'user_id' => $user->id,
                'role' => $projectRole,
                'status' => 'active',
            ]);
        }

        return $user->fresh();
    }

    /** @return list<string> */
    private function capabilities(User $user): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/capabilities")
            ->assertOk()
            ->json('data.capabilities');
    }

    public function test_a_marketing_manager_may_manage_the_campaigns_they_run(): void
    {
        $caps = $this->capabilities($this->member('manager@caps.test', ProjectRole::MARKETING_MANAGER, 'projects.view', 'campaigns.view'));

        $this->assertContains(ProjectCapability::CAMPAIGNS_MANAGE, $caps);
        $this->assertContains(ProjectCapability::REPORTS_VIEW, $caps);
    }

    /**
     * A viewer's rail is shorter, and this is the pair that makes the endpoint worth having.
     *
     * `team.manage` is the link a viewer is offered today and refused on: the rail cannot know it is
     * not theirs, because the rail knows nothing.
     */
    public function test_a_viewer_is_not_told_they_may_manage_the_team(): void
    {
        $caps = $this->capabilities($this->member('viewer@caps.test', ProjectRole::VIEWER, 'projects.view', 'campaigns.view'));

        $this->assertNotContains(ProjectCapability::TEAM_MANAGE, $caps);
        $this->assertNotContains(ProjectCapability::CAMPAIGNS_MANAGE, $caps);
        $this->assertContains(ProjectCapability::DASHBOARD_VIEW, $caps);
    }

    /** Somebody with no membership at all cannot ask about the project — the `project` gate answers first. */
    public function test_a_stranger_to_the_project_is_refused(): void
    {
        // `projects.view` and no membership: reaching the project at all is what is refused here.
        $stranger = $this->member('stranger@caps.test', null, 'projects.view');

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/capabilities")
            ->assertForbidden();
    }

    /**
     * **The endpoint is not the enforcement, and this is the test that proves it.**
     *
     * A viewer is not told they may manage campaigns — and if they ignore the rail and call the
     * route anyway, the middleware refuses. An endpoint that listed capabilities would not become
     * security by being read by a menu, and a menu that hid a link would not become security either.
     */
    public function test_the_route_refuses_the_viewer_who_was_not_offered_it(): void
    {
        $viewer = $this->member('viewer2@caps.test', ProjectRole::VIEWER, 'projects.view', 'campaigns.view', 'campaigns.launch');

        $this->assertNotContains(ProjectCapability::CAMPAIGNS_MANAGE, $this->capabilities($viewer));

        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/campaigns", ['name' => 'Anything'])
            ->assertForbidden();
    }
}
