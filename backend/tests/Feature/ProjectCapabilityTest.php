<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * TEAM-PROJECT-RBAC-001 — what a person may do inside one project, decided on the server.
 *
 * `project_memberships.role` and `.permissions` have existed since the table was created and nothing
 * read either of them. The invitation form offered «Media buyer» and «Client viewer», stored the
 * choice, and changed precisely nothing about what the person could then do — their access was
 * whatever their TENANT role granted, on every project they could reach.
 *
 * Every case below is one of the acceptance conditions, written as the refusal it has to produce.
 * The tests are about the ANSWER, not about a hidden menu: a control that is not drawn is still a
 * URL, and the question here is what happens when somebody opens it.
 */
final class ProjectCapabilityTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Project $otherProject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
        ]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'آساس الثبات', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'Lead generation', 'status' => 'active',
        ]);

        $this->otherProject = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'Another client', 'status' => 'active',
        ]);
    }

    /**
     * The rule the whole class exists for: a media buyer gets the numbers, not the people.
     *
     * They see that a campaign produced forty leads and what each cost. A name and a phone number
     * are not performance data, and granting them by default is how a performance dashboard becomes
     * an unaudited copy of a client's contact list.
     */
    public function test_a_media_buyer_receives_no_lead_identities(): void
    {
        $buyer = $this->member(ProjectRole::MEDIA_BUYER);
        $abilities = app(ProjectAbilities::class);

        $this->assertTrue($abilities->allows($buyer, $this->project->id, ProjectCapability::LEADS_VIEW));
        $this->assertTrue($abilities->allows($buyer, $this->project->id, ProjectCapability::CAMPAIGNS_MANAGE));

        $this->assertFalse($abilities->allows($buyer, $this->project->id, ProjectCapability::LEADS_PII_VIEW));
        $this->assertFalse($abilities->allows($buyer, $this->project->id, ProjectCapability::LEADS_EXPORT));
    }

    /** A lead agent works one lead at a time: they may read and update, never reassign or export. */
    public function test_a_lead_agent_can_work_a_lead_but_not_hand_it_on_or_take_it_out(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);
        $abilities = app(ProjectAbilities::class);

        $this->assertTrue($abilities->allows($agent, $this->project->id, ProjectCapability::LEADS_PII_VIEW));
        $this->assertTrue($abilities->allows($agent, $this->project->id, ProjectCapability::LEADS_UPDATE));

        $this->assertFalse($abilities->allows($agent, $this->project->id, ProjectCapability::LEADS_ASSIGN));
        $this->assertFalse($abilities->allows($agent, $this->project->id, ProjectCapability::LEADS_EXPORT));
        $this->assertFalse($abilities->allows($agent, $this->project->id, ProjectCapability::CAMPAIGNS_MANAGE));
    }

    /**
     * Management reads results without reading people.
     *
     * `leads.view` without `leads.pii.view` is the whole reason the two are separate capabilities:
     * «how many, how fast, what did each cost» needs no phone number.
     */
    public function test_management_sees_the_results_without_the_identities(): void
    {
        $manager = $this->member(ProjectRole::MANAGEMENT_VIEWER);
        $abilities = app(ProjectAbilities::class);

        $this->assertTrue($abilities->allows($manager, $this->project->id, ProjectCapability::LEADS_VIEW));
        $this->assertTrue($abilities->allows($manager, $this->project->id, ProjectCapability::ANALYTICS_VIEW));
        $this->assertTrue($abilities->allows($manager, $this->project->id, ProjectCapability::BUDGET_VIEW));

        $this->assertFalse($abilities->allows($manager, $this->project->id, ProjectCapability::LEADS_PII_VIEW));
        $this->assertFalse($abilities->allows($manager, $this->project->id, ProjectCapability::BUDGET_MANAGE));
    }

    /** A grant is per project. Being trusted on one client says nothing about another. */
    public function test_a_grant_on_one_project_does_not_reach_another(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT);

        $this->assertTrue(app(ProjectAbilities::class)->allows($agent, $this->project->id, ProjectCapability::LEADS_PII_VIEW));
        $this->assertFalse(app(ProjectAbilities::class)->allows($agent, $this->otherProject->id, ProjectCapability::LEADS_PII_VIEW));
        $this->assertSame([], app(ProjectAbilities::class)->for($agent, $this->otherProject->id));
    }

    /**
     * An agency-wide reader reads every project — as a VIEWER, and never with lead identities.
     *
     * `projects.view.all` is the tenant permission that lets an agency operator open any of their own
     * projects. It must not carry the grant that has to be made per project by somebody who knows
     * the client.
     */
    public function test_an_agency_wide_reader_reads_every_project_but_holds_no_identities(): void
    {
        $reader = $this->tenantUser(['projects.view', 'projects.view.all', 'analytics.view']);
        $abilities = app(ProjectAbilities::class);

        $this->assertTrue($abilities->allows($reader, $this->otherProject->id, ProjectCapability::DASHBOARD_VIEW));
        $this->assertTrue($abilities->allows($reader, $this->otherProject->id, ProjectCapability::ANALYTICS_VIEW));
        $this->assertFalse($abilities->allows($reader, $this->otherProject->id, ProjectCapability::LEADS_PII_VIEW));
        $this->assertFalse($abilities->allows($reader, $this->otherProject->id, ProjectCapability::LEADS_VIEW));
    }

    /**
     * Agency staff are not members of their clients' projects, and must not lose what they had.
     *
     * The operator who set a spend limit yesterday sets one today. A permission model that takes
     * that away is a regression dressed as a security fix, and it is how a rollout gets reverted.
     */
    public function test_a_tenant_role_still_answers_where_there_is_no_membership(): void
    {
        $operator = $this->tenantUser(['projects.view', 'projects.view.all', 'analytics.view', 'budget.manage']);

        $this->assertTrue(app(ProjectAbilities::class)->allows($operator, $this->project->id, ProjectCapability::BUDGET_MANAGE));
    }

    /**
     * And a MEMBERSHIP narrows, even for somebody whose tenant role is generous.
     *
     * This is the direction that makes the project layer worth having. A media buyer invited to one
     * client gets the media buyer's access on that client — a union with the tenant role would mean
     * a project role could only ever add, and «this person may not see this client's callers» would
     * be unsayable.
     */
    public function test_a_membership_narrows_a_generous_tenant_role(): void
    {
        $buyer = $this->tenantUser(['projects.view', 'projects.view.all', 'analytics.view', 'leads.view', 'leads.pii.view']);

        ProjectMembership::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $buyer->id,
            'role' => ProjectRole::MEDIA_BUYER, 'status' => 'active', 'joined_at' => Carbon::now(),
        ]);

        $abilities = app(ProjectAbilities::class);

        // Narrowed here…
        $this->assertFalse($abilities->allows($buyer, $this->project->id, ProjectCapability::LEADS_PII_VIEW));
        // …and unchanged on the project they were never invited to, where the tenant role still answers.
        $this->assertTrue($abilities->allows($buyer, $this->otherProject->id, ProjectCapability::LEADS_PII_VIEW));
    }

    /**
     * The tenant administrator owns every project in their tenant.
     *
     * Somebody has to be able to grant the rest, and it must not be possible to lock the owner out
     * of their own client by giving them a narrow membership.
     */
    public function test_a_tenant_administrator_is_never_locked_out_by_a_membership(): void
    {
        $owner = $this->tenantUser(['projects.view', 'settings.manage']);

        ProjectMembership::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $owner->id,
            'role' => ProjectRole::VIEWER, 'status' => 'active', 'joined_at' => Carbon::now(),
        ]);

        $this->assertTrue(app(ProjectAbilities::class)->allows($owner, $this->project->id, ProjectCapability::SETTINGS_MANAGE));
    }

    /** A membership that lapsed is not a membership — and nobody revisits an expiry date. */
    public function test_an_expired_or_inactive_membership_grants_nothing(): void
    {
        $expired = $this->member(ProjectRole::SALES_MANAGER, ['expires_at' => Carbon::now()->subDay()]);
        $removed = $this->member(ProjectRole::SALES_MANAGER, ['status' => 'removed']);

        $this->assertSame([], app(ProjectAbilities::class)->for($expired, $this->project->id));
        $this->assertSame([], app(ProjectAbilities::class)->for($removed, $this->project->id));
    }

    /**
     * The unknown case is no — in both directions.
     *
     * A capability that is not in the catalogue is refused however it is asked for, and a membership
     * whose JSON `permissions` column names one is not thereby granted it. That column is written by
     * an API, and «leads.*», «admin» or a typo must not become access.
     */
    public function test_an_unknown_capability_is_refused_and_cannot_be_granted(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT, ['permissions' => ['leads.*', 'admin', 'leads.export ']]);
        $abilities = app(ProjectAbilities::class);

        $this->assertFalse($abilities->allows($agent, $this->project->id, 'leads.*'));
        $this->assertFalse($abilities->allows($agent, $this->project->id, 'admin'));
        $this->assertFalse($abilities->allows($agent, $this->project->id, ProjectCapability::LEADS_EXPORT));
    }

    /** A named extra IS granted — that is what the column is for, one capability at a time. */
    public function test_an_explicit_extra_is_granted_when_it_is_a_real_capability(): void
    {
        $agent = $this->member(ProjectRole::LEAD_AGENT, ['permissions' => [ProjectCapability::LEADS_ASSIGN]]);

        $this->assertTrue(app(ProjectAbilities::class)->allows($agent, $this->project->id, ProjectCapability::LEADS_ASSIGN));
        // And it grants only what it names.
        $this->assertFalse(app(ProjectAbilities::class)->allows($agent, $this->project->id, ProjectCapability::LEADS_EXPORT));
    }

    /**
     * A role written by an older release still lets its holder read the project.
     *
     * The column holds `account_manager`, `client_viewer` and six others in production. They map to
     * the nearest preset rather than being renamed in place — a migration that rewrote live rows
     * would be changing people's access as a side effect of a naming decision — and an unrecognised
     * name falls to `viewer`, never to nothing: a silent total refusal reads to a real employee as an
     * outage.
     */
    public function test_a_role_name_from_an_older_release_still_reads_the_project(): void
    {
        $legacy = $this->member('client_viewer');
        $unknown = $this->member('something_nobody_defined');
        $abilities = app(ProjectAbilities::class);

        foreach ([$legacy, $unknown] as $user) {
            $this->assertTrue($abilities->allows($user, $this->project->id, ProjectCapability::DASHBOARD_VIEW));
            $this->assertFalse($abilities->allows($user, $this->project->id, ProjectCapability::LEADS_PII_VIEW));
        }
    }

    /** Every preset grants only capabilities that exist. A typo in a preset is a silent hole. */
    public function test_no_preset_names_a_capability_that_does_not_exist(): void
    {
        foreach (ProjectRole::presets() as $role => $capabilities) {
            foreach ($capabilities as $capability) {
                $this->assertTrue(
                    ProjectCapability::exists($capability),
                    "preset [{$role}] grants [{$capability}], which is not a capability",
                );
            }
        }
    }

    /**
     * The refusal on a real route, which is the only place it counts.
     *
     * A capability object nobody consults is a capability object. `project.can:budget.manage` sits on
     * the spend-limit routes, so a lead agent who opens the URL — from our screen, from a bookmark,
     * from `curl` — is refused by the server.
     *
     * **403, and not 404 or an empty 200.** The three refusals are not equivalent: an empty 200 tells
     * the caller this client has no spend limits, which is a false statement about the client's
     * business rather than a refusal, and a 404 tells a colleague the project does not exist when
     * what they need to hear is «ask for access».
     */
    public function test_the_route_itself_refuses_a_member_without_the_capability(): void
    {
        /*
         * The agent's TENANT role carries everything the controller checks, so the only thing left
         * to refuse them is the project capability. Without that, this test would pass on the tenant
         * layer and prove nothing.
         */
        $agent = $this->member(ProjectRole::LEAD_AGENT);

        $this->actingAs($agent, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/spend-limits")
            ->assertForbidden();

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/spend-limits", [
                'scope' => 'project', 'amount' => 1000, 'currency' => 'SAR', 'period' => 'monthly',
            ])
            ->assertForbidden();
    }

    /** And lets through the member who does hold it. A guard that refuses everybody is not a guard. */
    public function test_the_route_admits_a_member_who_holds_the_capability(): void
    {
        $manager = $this->member(ProjectRole::MARKETING_MANAGER);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/spend-limits")
            ->assertOk();
    }

    /** @param  list<string>  $permissions */
    private function tenantUser(array $permissions): User
    {
        $user = $this->user();
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::whereIn('key', $permissions)->pluck('key')->all());
        $user->assignRole($role);

        return $user;
    }

    private function user(): User
    {
        $user = User::create([
            'name' => 'U', 'email' => 'u-'.uniqid().'@test.test', 'password' => 'secret123',
            'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $this->tenant);

        return $user;
    }

    /**
     * A project member: a tenant role that lets them through the door, and a membership that decides
     * what they may do once inside.
     *
     * The tenant role carries only what the ROUTE needs to reach its controller — `projects.view`
     * for the door, and the two permissions `SpendLimitController` itself checks. The two layers
     * compose deliberately: the tenant role says what an employee may do in this workspace at all,
     * and the project capability says whether they may do it to THIS client. A route test whose
     * subject held a generous tenant role would pass on that role and prove nothing about the
     * capability it was written for.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function member(string $role, array $overrides = []): User
    {
        $user = $this->tenantUser(['projects.view', 'campaigns.view', 'campaigns.budget.change']);

        ProjectMembership::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => Carbon::now(),
            ...$overrides,
        ]);

        return $user;
    }
}
