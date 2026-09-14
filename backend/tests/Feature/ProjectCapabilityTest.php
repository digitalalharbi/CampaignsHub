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
    /**
     * TEAM-PROJECT-RBAC-001 — the narrowing that guards a READ was never put on the WRITES.
     *
     * ## The defect
     *
     * `show` learned it: «a client viewer confined to one project could read the neighbouring
     * client's record by putting its id in the URL», and it now asks `reachable()` before answering.
     * `update`, `archive`, `restore`, `pause`, `resume` and `clone` ask nothing of the kind. They
     * check the TENANT permission — `projects.update`, `projects.create` — and then look the project
     * up with a tenant-scoped `find()`, which by definition finds every project in the agency.
     *
     * So a member confined to one client could pause, archive, rename or copy ANOTHER client's
     * project by putting its id in the URL. That is the same defect one rung more serious: reading a
     * neighbour's project name is a disclosure, and pausing their campaigns stops their advertising.
     *
     * The id is not a secret — it is in the address of every project they legitimately open.
     *
     * ## Why the tenant permission is not the answer
     *
     * `projects.update` says «may this person run clients at all», which is exactly the sentence the
     * route-coverage list uses to justify exempting these routes from a project capability. That
     * sentence is true and it is not a scope: an account manager who runs one client holds it, and
     * nothing about holding it says which client. `projects.view.all` is the permission that means
     * «every project in this agency», and `reachable()` already reads it.
     */
    public function test_a_confined_member_cannot_pause_or_archive_a_neighbouring_project(): void
    {
        $manager = $this->confinedWriter();

        foreach (['pause', 'archive', 'restore', 'resume'] as $action) {
            $this->actingAs($manager, 'sanctum')
                ->postJson("/api/v1/projects/{$this->otherProject->id}/{$action}")
                ->assertForbidden();
        }

        $this->assertSame(
            'active',
            $this->otherProject->fresh()->status,
            'a neighbouring client’s project changed state',
        );
    }

    /** Renaming is the same act on the same row, through a different verb. */
    public function test_a_confined_member_cannot_rename_a_neighbouring_project(): void
    {
        $manager = $this->confinedWriter();

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/projects/{$this->otherProject->id}", ['name' => 'Taken over'])
            ->assertForbidden();

        $this->assertNotSame('Taken over', $this->otherProject->fresh()->name);
    }

    /**
     * And copying it, which takes the neighbour's configuration rather than changing it.
     *
     * A copy is a read the reader keeps: the name, the workspace, the account manager and whatever
     * the clone carries across, in a project they then own.
     */
    public function test_a_confined_member_cannot_clone_a_neighbouring_project(): void
    {
        $manager = $this->confinedWriter();
        $before = Project::withoutGlobalScopes()->count();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/projects/{$this->otherProject->id}/clone")
            ->assertForbidden();

        $this->assertSame($before, Project::withoutGlobalScopes()->count(), 'a copy of the neighbour was made');
    }

    /**
     * The guard is a narrowing, not a lockout — the same member acts on their OWN project.
     *
     * A refusal that refuses everybody proves nothing about scope; it proves the route is broken.
     */
    public function test_the_same_member_still_pauses_their_own_project(): void
    {
        $manager = $this->confinedWriter();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/pause")
            ->assertOk();

        $this->assertSame('paused', $this->project->fresh()->status);
    }

    /**
     * An agency-wide reader is not narrowed, because that is what `projects.view.all` means.
     *
     * Narrowing them would break the account the permission exists for — the person who runs every
     * client — and `reachable()` already answers «null» for them on the read path.
     */
    public function test_an_agency_wide_holder_still_reaches_every_project(): void
    {
        $owner = $this->tenantUser(['projects.view', 'projects.view.all', 'projects.update']);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->otherProject->id}/pause")
            ->assertOk();
    }

    /**
     * Every project LIFECYCLE route refuses a confined member — swept, not enumerated by hand.
     *
     * ## Why a sweep and not three more cases
     *
     * The routes under `/projects/{project}/…` are guarded by `ResolveProject`, which has applied
     * this exact narrowing since it was written. The lifecycle routes are the ones OUTSIDE that
     * middleware — they act on the project rather than inside it — and their protection is a line in
     * a controller that somebody has to remember. It was remembered once, on `show`, and forgotten on
     * every write beside it.
     *
     * So the guard is the same shape as the defect: it finds the routes by what they lack — a
     * `{project}` in the path and no `ResolveProject` above them — and drives each one. A route added
     * to this group tomorrow is swept rather than trusted.
     *
     * ## What «refuses» means here
     *
     * 403 specifically, and never a 2xx. A 404 would be a different answer with a different meaning,
     * and an empty 200 is the failure this whole row is about: a refusal dressed as an answer.
     */
    public function test_every_lifecycle_route_refuses_a_confined_member(): void
    {
        $manager = $this->confinedWriter();
        $swept = [];
        $offenders = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'api.v1.projects.') || ! str_contains($route->uri(), '{project}')) {
                continue;
            }

            /* Anything with a second bound id belongs to a nested resource, not to the lifecycle. */
            if (preg_match('/\{(?!project\??\})[a-zA-Z_]+\??\}/', $route->uri())) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_contains($middleware, 'ResolveProject')) {
                    continue 2;
                }
            }

            $verb = collect($route->methods())->first(fn (string $m): bool => $m !== 'HEAD');
            $uri = '/'.str_replace('{project}', (string) $this->otherProject->id, $route->uri());

            $swept[] = $name;

            $status = $this->actingAs($manager, 'sanctum')
                ->json((string) $verb, $uri, [])
                ->getStatusCode();

            if ($status !== 403) {
                $offenders[] = "{$name} → {$verb} answered {$status}";
            }
        }

        sort($swept);
        sort($offenders);

        /*
         * The sweep has to find the routes, or it proves nothing by finding no offenders. Seven is
         * what the group holds today — show, update, archive, restore, clone, pause, resume.
         */
        $this->assertGreaterThanOrEqual(7, count($swept), 'the lifecycle sweep found almost no routes: '.implode(', ', $swept));

        $this->assertSame(
            [],
            $offenders,
            "A project lifecycle route acted on a project this member cannot reach.\n"
            ."Call `authorizeReach()` after `find()`, as `show` and the transitions do:\n  "
            .implode("\n  ", $offenders),
        );
    }

    /**
     * A member who may write inside ONE project, and is not an agency-wide reader.
     *
     * This is the shape the defect lives in: permissions are freeform strings a tenant grants, and
     * «may edit a project» and «may see every project» are two of them. An account manager running a
     * single client holds the first and not the second.
     */
    private function confinedWriter(): User
    {
        $user = $this->tenantUser(['projects.view', 'projects.update', 'projects.create']);

        ProjectMembership::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id' => $user->id,
            'role' => ProjectRole::MARKETING_MANAGER,
            'status' => 'active',
            'joined_at' => Carbon::now(),
        ]);

        return $user;
    }

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
