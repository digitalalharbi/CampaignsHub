<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PAY-AUDIT-001 — the caps a plan sells are the caps the API enforces.
 *
 * ## Why this file exists, when nine tests already covered subscriptions
 *
 * They covered the arithmetic and never the enforcement. `SubscriptionTest` asserts that
 * `withinLimit()` flips false at the cap — and reaches the cap by calling `increment()` itself,
 * three times, inside the test. Nothing in `app/` ever called `increment()`, so `usage_counters` was
 * written by tests and by nothing else: in production `usage()` returned 0 for every tenant,
 * `withinLimit()` was permanently `0 < cap`, and both `EnsureWithinPlanLimit` mounts passed
 * everything through. Five metrics sold, five unenforced, and a green suite over the top.
 *
 * So every assertion below goes through **HTTP**. A test that can reach the cap without creating
 * the thing is a test that cannot tell whether creating the thing counts, which is the one question
 * worth asking here.
 *
 * ## And the slot has to come back
 *
 * `test_archiving_a_project_returns_the_slot` is the reason `usage()` counts rows instead of
 * metering. A counter fed at each create would enforce the cap correctly on the way up and never
 * give a slot back on the way down: a customer who archived last quarter's work would find their
 * capacity permanently spent. That is worse than the bug being fixed here, because it takes
 * something the customer paid for rather than merely failing to stop them.
 */
final class PlanLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-caps', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->owner = User::create(['name' => 'O', 'email' => 'o@acme.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        $this->workspace = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client-caps', 'mode' => 'managed']);

        /*
         * Pinned to `starter` deliberately.
         *
         * `currentPlan()` falls back to the MOST PERMISSIVE active plan for a tenant with no
         * subscription — `scale`, whose every cap is null. A test that skipped this would assert
         * against «unlimited» and pass whatever the enforcement did.
         */
        app(SubscriptionService::class)->assignPlan(
            $this->tenant,
            SubscriptionPlan::where('code', 'starter')->firstOrFail(),
        );

        app(TenantContext::class)->forget();
    }

    private function createProject(string $name): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/projects', [
            'client_workspace_id' => $this->workspace->id,
            'name' => $name,
        ]);
    }

    /** The starter cap is three projects, and the fourth create is refused by the API. */
    public function test_the_project_cap_refuses_the_create_that_would_exceed_it(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->createProject("Project {$i}")->assertCreated();
        }

        $refused = $this->createProject('Project 4')->assertStatus(403);

        // The refusal carries the numbers, so the interface can say «3 of 3» rather than «not allowed».
        $refused->assertJsonPath('meta.plan_limit', true)
            ->assertJsonPath('meta.metric', 'projects')
            ->assertJsonPath('meta.used', 3)
            ->assertJsonPath('meta.limit', 3)
            ->assertJsonPath('meta.upgrade_path', '/app/subscriptions');

        // Refused means refused: nothing was written on the way to the 403.
        $this->assertSame(3, Project::withoutGlobalScopes()->count());
    }

    /**
     * Archiving frees the slot — the test that rules out a counter.
     *
     * Under a metered implementation this fails: the counter would still read 3 after the archive,
     * and the customer would have paid for a slot they can never use again.
     */
    public function test_archiving_a_project_returns_the_slot(): void
    {
        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->createProject("Project {$i}")->assertCreated()->json('data.id');
        }

        $this->createProject('One too many')->assertStatus(403);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$ids[0]}/archive")->assertOk();

        $this->createProject('Now there is room')->assertCreated();
    }

    /**
     * Restoring takes the slot back, so archive-create-restore cannot walk around the cap.
     *
     * A cap guarding only `store` would be defeated by three keystrokes.
     */
    public function test_restoring_an_archived_project_is_refused_when_the_slot_is_gone(): void
    {
        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->createProject("Project {$i}")->assertCreated()->json('data.id');
        }

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$ids[0]}/archive")->assertOk();
        $this->createProject('Took the freed slot')->assertCreated();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$ids[0]}/restore")
            ->assertStatus(403)
            ->assertJsonPath('meta.metric', 'projects');
    }

    /** Cloning is a create, and is capped as one. */
    public function test_cloning_is_refused_at_the_cap(): void
    {
        $first = $this->createProject('Original')->assertCreated()->json('data.id');
        $this->createProject('Second')->assertCreated();
        $this->createProject('Third')->assertCreated();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$first}/clone")
            ->assertStatus(403)
            ->assertJsonPath('meta.metric', 'projects');
    }

    /**
     * A seat is taken by the INVITATION, not by the account.
     *
     * Since TEAM-INVITE-001 an invitation creates no `User` at all, so a cap counting memberships
     * alone would let a workspace on three seats invite thirty people and refuse nothing until the
     * day they all accepted — by which point the product has already been given away.
     */
    public function test_a_pending_invitation_holds_a_seat(): void
    {
        Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'slug' => 'analyst']);

        // The owner already holds one of the three starter seats.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/settings/team', ['email' => 'a@acme.test', 'role' => 'analyst'])->assertStatus(201);
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/settings/team', ['email' => 'b@acme.test', 'role' => 'analyst'])->assertStatus(201);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/settings/team', ['email' => 'c@acme.test', 'role' => 'analyst'])
            ->assertStatus(403)
            ->assertJsonPath('meta.metric', 'team_members')
            ->assertJsonPath('meta.used', 3);
    }

    /** Withdrawing an invitation frees the seat it was holding. */
    public function test_withdrawing_an_invitation_frees_the_seat(): void
    {
        Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'slug' => 'analyst']);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/settings/team', ['email' => 'a@acme.test', 'role' => 'analyst'])->assertStatus(201);
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/settings/team', ['email' => 'b@acme.test', 'role' => 'analyst'])
            ->assertStatus(201)->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/settings/team', ['email' => 'c@acme.test', 'role' => 'analyst'])->assertStatus(403);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/settings/team/invitations/{$id}")->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/settings/team', ['email' => 'c@acme.test', 'role' => 'analyst'])->assertStatus(201);
    }

    /**
     * An unlimited cap stays unlimited — `scale` sells «no ceiling» and must not acquire one here.
     *
     * The counting path returns a real number for every metric now, so a null cap has to be checked
     * BEFORE the count is compared to anything, or «unlimited» silently becomes «zero».
     */
    public function test_a_plan_with_no_ceiling_refuses_nothing(): void
    {
        app(SubscriptionService::class)->assignPlan(
            $this->tenant,
            SubscriptionPlan::where('code', 'scale')->firstOrFail(),
        );

        for ($i = 1; $i <= 5; $i++) {
            $this->createProject("Project {$i}")->assertCreated();
        }

        $this->assertNull(app(SubscriptionService::class)->effectiveLimit($this->tenant, 'projects'));
        $this->assertNull(app(SubscriptionService::class)->remaining($this->tenant, 'projects'));
    }

    /** Usage is read from the projects themselves, so it is right without anything having metered it. */
    public function test_usage_reflects_what_exists_without_a_meter(): void
    {
        $service = app(SubscriptionService::class);

        $this->assertSame(0, $service->usage($this->tenant, 'projects'));

        $this->createProject('One')->assertCreated();
        $this->createProject('Two')->assertCreated();

        $this->assertSame(2, $service->usage($this->tenant, 'projects'), 'the count did not follow the creates');
        $this->assertSame(1, $service->remaining($this->tenant, 'projects'));

        // And it is per tenant: another workspace's projects are not this one's usage.
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-caps', 'status' => 'active']);
        $this->assertSame(0, $service->usage($other, 'projects'));
    }
}
