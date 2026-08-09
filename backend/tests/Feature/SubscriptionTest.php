<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Subscriptions\Http\Controllers\SubscriptionController;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Models\UsageCounter;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Subscriptions are honest and fail-open by construction: a tenant with no subscription defaults to the most
 * permissive plan (never blocked), remaining computes, and every subscription is isolated to its tenant. The
 * plan-limit middleware denies fail-closed only when a real cap is exceeded.
 *
 * ## The limit tests below used to reach the cap by calling `increment()`, and that is what hid PAY-AUDIT-001
 *
 * `increment()` is the only writer of `usage_counters` and had no callers in `app/` — so the table was
 * written by these tests and by nothing else. Reaching the cap in the test meant the assertions proved
 * the service's arithmetic and never that creating a project moved the number. In production `usage()`
 * returned 0 for every tenant and every cap passed everything.
 *
 * `usage()` now counts the thing itself, so these reach the cap by CREATING PROJECTS. The enforcement
 * across HTTP lives in `PlanLimitEnforcementTest`, which is the coverage that was missing entirely.
 */
final class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        // Register the (orchestrator-wired) subscription endpoints so controller behaviour is covered here.
        Route::middleware(['auth:sanctum', 'tenant'])->prefix('api/v1')->group(function (): void {
            Route::get('subscriptions/plans', [SubscriptionController::class, 'plans']);
            Route::get('subscriptions/current', [SubscriptionController::class, 'current']);
            Route::post('subscriptions/change', [SubscriptionController::class, 'change']);
        });
    }

    private function service(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    /**
     * Real projects, because `usage('projects')` counts projects.
     *
     * Written straight to the table rather than through the model so a test can hand them to ANY
     * tenant — the isolation test needs two — without moving the request-scoped tenant context
     * around underneath itself.
     */
    private function makeProjects(int $n, ?Tenant $for = null): void
    {
        $tenant = $for ?? $this->tenant;
        $workspace = $this->workspaceFor($tenant);

        for ($i = 0; $i < $n; $i++) {
            DB::table('projects')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => (string) $tenant->id,
                'client_workspace_id' => $workspace,
                'name' => 'Project '.Str::uuid(),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** One client workspace per tenant — `projects.client_workspace_id` is a real foreign key. */
    private function workspaceFor(Tenant $tenant): string
    {
        $existing = DB::table('client_workspaces')->where('tenant_id', (string) $tenant->id)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid();
        DB::table('client_workspaces')->insert([
            'id' => $id,
            'tenant_id' => (string) $tenant->id,
            'name' => 'Client of '.$tenant->slug,
            'slug' => 'client-'.$tenant->slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** One report, dated — the monthly allowance is a question about WHEN, not how many in total. */
    private function makeReport(Carbon $at): void
    {
        $this->makeProjects(1);
        $project = DB::table('projects')->where('tenant_id', (string) $this->tenant->id)->value('id');

        DB::table('reports')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) $this->tenant->id,
            'project_id' => (string) $project,
            'name' => 'Monthly '.$at->format('Y-m-d'),
            'type' => 'executive',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function plan(string $code): SubscriptionPlan
    {
        return SubscriptionPlan::query()->where('code', $code)->firstOrFail();
    }

    private function ownerWithAllPermissions(?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create([
            'name' => 'O', 'email' => 'o-'.uniqid().'@a.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $tenant);
        $user->assignRole($role);

        return $user;
    }

    public function test_assign_plan_creates_one_subscription_and_is_idempotent(): void
    {
        $sub = $this->service()->assignPlan($this->tenant, $this->plan('growth'));

        $this->assertSame('growth', $sub->plan->code);
        $this->assertSame('active', $sub->status);
        $this->assertSame($this->tenant->id, $sub->tenant_id);

        // Moving to another plan updates the SAME row (one subscription per tenant).
        $this->service()->assignPlan($this->tenant, $this->plan('scale'));
        $this->assertSame(1, Subscription::query()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame('scale', $this->service()->currentPlan($this->tenant)->code);
    }

    public function test_within_limit_is_true_under_the_cap_and_false_at_the_cap(): void
    {
        $this->service()->assignPlan($this->tenant, $this->plan('starter')); // projects cap = 3

        $this->assertTrue($this->service()->withinLimit($this->tenant, 'projects')); // 0 < 3

        $this->makeProjects(2);
        $this->assertTrue($this->service()->withinLimit($this->tenant, 'projects')); // 2 < 3

        $this->makeProjects(1); // now 3
        $this->assertFalse($this->service()->withinLimit($this->tenant, 'projects')); // 3 !< 3
    }

    /**
     * `increment()` still meters, for a metric nothing can count.
     *
     * Asserted on a metric `usage()` does NOT recognise — `projects` is counted from the projects
     * table now, so metering it would prove nothing about either path. This is the fallback the
     * service keeps for a future metric that leaves no row behind.
     */
    public function test_increment_tracks_usage_as_an_upsert_for_a_metric_nothing_can_count(): void
    {
        $this->service()->increment($this->tenant, 'api_calls');
        $this->service()->increment($this->tenant, 'api_calls');

        $this->assertSame(2, $this->service()->usage($this->tenant, 'api_calls'));
        $this->assertSame(1, UsageCounter::query()
            ->where('tenant_id', $this->tenant->id)->where('metric', 'api_calls')->count());
    }

    /** And a counted metric ignores the meter entirely — the row is the answer. */
    public function test_a_counted_metric_ignores_the_meter(): void
    {
        $this->service()->assignPlan($this->tenant, $this->plan('starter'));
        $this->service()->increment($this->tenant, 'projects', 99);

        $this->assertSame(0, $this->service()->usage($this->tenant, 'projects'), 'the stale meter was read');
        $this->assertTrue($this->service()->withinLimit($this->tenant, 'projects'));
    }

    public function test_remaining_computes_and_unlimited_returns_null(): void
    {
        $this->service()->assignPlan($this->tenant, $this->plan('growth')); // projects cap = 25
        $this->makeProjects(2);

        $this->assertSame(23, $this->service()->remaining($this->tenant, 'projects'));

        // On scale, projects is unlimited (null cap) → remaining is null.
        $this->service()->assignPlan($this->tenant, $this->plan('scale'));
        $this->assertNull($this->service()->remaining($this->tenant, 'projects'));
        $this->assertTrue($this->service()->withinLimit($this->tenant, 'projects'));
    }

    /**
     * The monthly metric counts THIS month, and last month's work does not spend this month's budget.
     *
     * The period used to live only in the counter's `period` column. It now lives in the query, which
     * is what makes the allowance actually reset — a counter row for `2026-07` was never going to be
     * cleared by anything, because nothing ever wrote or read it outside these tests.
     */
    public function test_the_monthly_report_allowance_counts_this_month_only(): void
    {
        $this->service()->assignPlan($this->tenant, $this->plan('starter')); // reports_per_month cap = 10

        $this->makeReport(now());
        $this->makeReport(now()->startOfMonth()->subDay()); // last month

        $this->assertSame(1, $this->service()->usage($this->tenant, 'reports_per_month'));
        $this->assertSame(9, $this->service()->remaining($this->tenant, 'reports_per_month'));
    }

    public function test_tenant_without_a_subscription_defaults_to_the_most_permissive_plan_and_is_not_blocked(): void
    {
        // No subscription assigned.
        $this->assertNull($this->service()->subscriptionFor($this->tenant));
        $this->assertSame('scale', $this->service()->currentPlan($this->tenant)->code);

        // Even after usage, an unlimited default never blocks.
        $this->makeProjects(5);
        $this->assertTrue($this->service()->withinLimit($this->tenant, 'projects'));
    }

    public function test_usage_and_subscriptions_are_isolated_per_tenant(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);

        $this->service()->assignPlan($this->tenant, $this->plan('starter'));
        $this->service()->assignPlan($other, $this->plan('growth'));

        $this->makeProjects(3);              // A at its starter cap
        $this->makeProjects(1, $other);

        // A is capped; B is not — usage does not bleed across tenants.
        $this->assertFalse($this->service()->withinLimit($this->tenant, 'projects'));
        $this->assertTrue($this->service()->withinLimit($other, 'projects'));
        $this->assertSame(3, $this->service()->usage($this->tenant, 'projects'));
        $this->assertSame(1, $this->service()->usage($other, 'projects'));
        $this->assertSame('starter', $this->service()->currentPlan($this->tenant)->code);
        $this->assertSame('growth', $this->service()->currentPlan($other)->code);
    }

    public function test_plans_endpoint_lists_the_active_catalogue(): void
    {
        $user = $this->ownerWithAllPermissions();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/subscriptions/plans')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'starter')
            ->assertJsonPath('data.2.code', 'scale');
    }

    public function test_current_endpoint_reports_usage_and_default_plan_flag(): void
    {
        $user = $this->ownerWithAllPermissions();

        // No subscription → defaulted to the most permissive plan.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/subscriptions/current')
            ->assertOk()
            ->assertJsonPath('data.is_default_plan', true)
            ->assertJsonPath('data.plan.code', 'scale')
            ->assertJsonPath('data.usage.projects.used', 0);
    }

    public function test_change_plan_requires_the_manage_permission(): void
    {
        $tenant = Tenant::create(['name' => 'C', 'slug' => 'c', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Viewer', 'slug' => 'viewer']);
        $role->givePermissionTo('subscriptions.view'); // view but NOT manage
        $viewer = User::create([
            'name' => 'V', 'email' => 'v@c.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($viewer, $tenant);
        $viewer->assignRole($role);

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/subscriptions/change', ['plan_code' => 'growth'])
            ->assertForbidden();
    }

    /**
     * A WORKSPACE OWNER cannot assign themselves a plan, however many permissions they hold.
     *
     * This endpoint used to be gated on `subscriptions.manage`, which every owner has — so one POST
     * granted the largest plan for nothing, past the checkout, past the webhook, past the whole
     * activation contract. Changing your own plan now costs money and goes through
     * `/subscriptions/plan-change`, where an upgrade waits for a confirmed payment.
     */
    public function test_a_workspace_owner_cannot_grant_themselves_a_plan(): void
    {
        $owner = $this->ownerWithAllPermissions();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/subscriptions/change', ['plan_code' => 'growth', 'seats' => 5])
            ->assertForbidden();

        $this->assertNotSame('growth', $this->service()->currentPlan($this->tenant)->code);
    }

    public function test_change_plan_assigns_the_subscription(): void
    {
        $user = $this->ownerWithAllPermissions();
        // The operator's grant, from the platform console — not a customer action.
        $user->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($user->refresh(), 'sanctum')->postJson('/api/v1/subscriptions/change', ['plan_code' => 'growth', 'seats' => 5])
            ->assertCreated()
            ->assertJsonPath('data.plan.code', 'growth')
            ->assertJsonPath('data.subscription.seats', 5);

        $this->assertSame('growth', $this->service()->currentPlan($this->tenant)->code);
    }
}
