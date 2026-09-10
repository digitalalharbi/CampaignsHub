<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TEAM-PROJECT-RBAC-001 — the metrics API was the one read surface the project role did not narrow.
 *
 * `ProjectAbilities` states the rule this product sells: «a project membership is a NARROWING, and
 * it is authoritative — a media buyer invited to this project gets the media buyer's capabilities
 * here even if their tenant role is generous». Ninety-seven project-scoped routes enforce it through
 * `project.can:…`, including this controller's own three budget routes.
 *
 * Eighteen did not. `MetricsController::authorizeView()` asked `hasPermission('campaigns.view')` —
 * a TENANT permission, with no project in the question at all — so a lead agent, whose preset grants
 * no `campaigns.view`, no `dashboard.view` and no `analytics.view`, was refused the creative library
 * and the budget of a project while being served its spend, its revenue, its campaign breakdown, its
 * platform performance, its funnel and its entity drill-down. Everything a client's money looks
 * like, through the surface nobody had guarded.
 *
 * «Hiding a menu item is NOT security» is the owner's own sentence, and this is the case it names:
 * the navigation does not offer a lead agent the dashboard, and the URL was one request away.
 */
final class MetricsProjectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    /** Every metrics route that had no capability guard on it. */
    private const READS = [
        'metrics/summary',
        'metrics/campaigns',
        'metrics/platforms',
        'metrics/timeseries',
        'metrics/funnel',
        'metrics/entities/ad_set',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    /**
     * A tenant role generous enough to reach the project, and deliberately WITHOUT `settings.manage`.
     *
     * `settings.manage` is the tenant-administrator bypass in `ProjectAbilities` and grants every
     * project capability outright — a test subject holding it would prove nothing about narrowing.
     */
    private function member(string $projectRole): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Staff', 'slug' => 'staff-'.uniqid()]);
        $role->givePermissionTo('projects.view', 'campaigns.view', 'analytics.view', 'reports.view', 'leads.view', 'tasks.view');

        $user = User::create(['name' => 'M', 'email' => 'm-'.uniqid().'@t.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        ProjectMembership::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id' => $user->id,
            'role' => $projectRole,
            'status' => 'active',
        ]);

        return $user;
    }

    private function read(User $user, string $path): int
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/{$path}?from=2026-08-01&to=2026-08-31")
            ->getStatusCode();
    }

    /**
     * A lead agent may read one lead at a time. They may not read the account's money.
     *
     * The preset grants `leads.view`, `leads.pii.view`, `leads.update` and `tasks.view` — and nothing
     * about campaigns, the dashboard or analytics. Every route below answers with money.
     */
    public function test_a_lead_agent_cannot_read_the_projects_metrics(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);

        foreach (self::READS as $path) {
            $this->assertSame(403, $this->read($agent, $path), "{$path} answered a reader with no analytics capability on this project");
        }
    }

    /**
     * The control: the three routes that ALREADY carried `project.can:budget.view` refuse them.
     *
     * If this passed while the block above failed, the mechanism would be in doubt rather than its
     * application — so the same reader is put to both, and the difference is the defect.
     */
    public function test_the_budget_routes_already_refused_the_same_reader(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);

        $this->assertSame(403, $this->read($agent, 'metrics/budget'));
        $this->assertSame(403, $this->read($agent, 'metrics/budget-accounts'));
    }

    /**
     * A narrowing narrows; it does not lock the project's own readers out.
     *
     * The viewer preset carries `dashboard.view`, `analytics.view`, `campaigns.view` and
     * `reports.view`, and every route above must answer them exactly as before. A fix that refused
     * this reader would be the regression dressed as a security fix that `ProjectAbilities`'
     * docblock warns about.
     */
    public function test_a_project_viewer_still_reads_every_metric(): void
    {
        $viewer = $this->member(ProjectRole::VIEWER);

        foreach (self::READS as $path) {
            $this->assertSame(200, $this->read($viewer, $path), "{$path} refused a reader the project granted analytics to");
        }
    }

    /**
     * Agency staff are not members of their clients' projects, and never have been.
     *
     * Their access comes from the tenant role through `ProjectAbilities::fromTenant()`, gated on
     * `projects.view.all` — the same gate `ResolveProject` already applies before this controller is
     * reached. Nothing here may change for them.
     */
    public function test_agency_staff_without_a_membership_are_unaffected(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'slug' => 'analyst-'.uniqid()]);
        $role->givePermissionTo('projects.view', 'projects.view.all', 'campaigns.view', 'analytics.view', 'reports.view');

        $analyst = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($analyst, $this->tenant);
        $analyst->assignRole($role);

        foreach (self::READS as $path) {
            $this->assertSame(200, $this->read($analyst, $path), "{$path} refused agency staff whose tenant role grants it");
        }
    }

    /** A tenant administrator cannot be locked out of their own client by a narrow membership. */
    public function test_a_tenant_administrator_is_never_narrowed(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $owner = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($owner, $this->tenant);
        $owner->assignRole($role);

        ProjectMembership::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'user_id' => $owner->id, 'role' => ProjectRole::LEAD_AGENT, 'status' => 'active',
        ]);

        $this->assertSame(200, $this->read($owner, 'metrics/summary'));
    }

    /**
     * `index` respects the narrowing and `show` did not — the same shape, one rung up.
     *
     * The listing filters to the projects a non-agency reader is an active member of, with a comment
     * saying so. `show` asked only `hasPermission('projects.view')` and then `find()`, which is
     * tenant-scoped and nothing more — so a client viewer confined to one project could read the
     * neighbouring client's project record by putting its id in the URL: its name, its budget window,
     * its status and the workspace it belongs to.
     *
     * That the list hides what the direct route serves is the tell. A reader who cannot see a thing
     * in the list is being told it is not theirs, and the id is not a secret: it is in every URL of
     * every project they CAN see, one increment of curiosity away.
     */
    public function test_a_confined_member_cannot_read_a_neighbours_project_by_id(): void
    {
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'slug' => 'o-'.uniqid(), 'mode' => 'managed']);
        $neighbour = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Neighbour', 'status' => 'active']);

        // A member of THIS project only, without the agency-wide `projects.view.all`.
        $confined = $this->member(ProjectRole::VIEWER);

        $listed = $this->actingAs($confined, 'sanctum')->getJson('/api/v1/projects')->assertOk();
        $this->assertNotContains(
            'Neighbour',
            array_column((array) $listed->json('data'), 'name'),
            'the listing already hides the neighbour, which is what makes the direct route the defect',
        );

        $this->actingAs($confined, 'sanctum')
            ->getJson("/api/v1/projects/{$neighbour->id}")
            ->assertForbidden();
    }

    /** And they may not change one either — the write side of the same question. */
    public function test_a_confined_member_cannot_alter_a_neighbours_project(): void
    {
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'slug' => 'o-'.uniqid(), 'mode' => 'managed']);
        $neighbour = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Neighbour', 'status' => 'active']);

        $confined = $this->member(ProjectRole::VIEWER);

        $this->actingAs($confined, 'sanctum')
            ->postJson("/api/v1/projects/{$neighbour->id}/pause")
            ->assertForbidden();

        $this->assertSame('active', $neighbour->refresh()->status);
    }

    /** Agency staff keep reaching every project in their tenant, as they always have. */
    public function test_agency_staff_still_read_any_project_by_id(): void
    {
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'slug' => 'o-'.uniqid(), 'mode' => 'managed']);
        $neighbour = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Neighbour', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'slug' => 'an-'.uniqid()]);
        $role->givePermissionTo('projects.view', 'projects.view.all', 'campaigns.view');

        $analyst = User::create(['name' => 'A', 'email' => 'aa-'.uniqid().'@t.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($analyst, $this->tenant);
        $analyst->assignRole($role);

        $this->actingAs($analyst, 'sanctum')
            ->getJson("/api/v1/projects/{$neighbour->id}")
            ->assertOk();
    }
}
