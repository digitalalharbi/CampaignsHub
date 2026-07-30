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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Subscriptions are honest and fail-open by construction: a tenant with no subscription defaults to the most
 * permissive plan (never blocked), limits are enforced against REAL usage counters, increments meter usage,
 * remaining computes, and every counter/subscription is isolated to its tenant. The plan-limit middleware
 * denies fail-closed only when a real cap is exceeded.
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
            'tenant_id' => $tenant->id, 'name' => 'O', 'email' => 'o-'.uniqid().'@a.test',
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

        $this->service()->increment($this->tenant, 'projects');
        $this->service()->increment($this->tenant, 'projects');
        $this->assertTrue($this->service()->withinLimit($this->tenant, 'projects')); // 2 < 3

        $this->service()->increment($this->tenant, 'projects'); // now 3
        $this->assertFalse($this->service()->withinLimit($this->tenant, 'projects')); // 3 !< 3
    }

    public function test_increment_tracks_usage_as_an_upsert(): void
    {
        $this->service()->increment($this->tenant, 'projects');
        $this->service()->increment($this->tenant, 'projects');

        $this->assertSame(2, $this->service()->usage($this->tenant, 'projects'));
        $this->assertSame(1, UsageCounter::query()
            ->where('tenant_id', $this->tenant->id)->where('metric', 'projects')->count());
    }

    public function test_remaining_computes_and_unlimited_returns_null(): void
    {
        $this->service()->assignPlan($this->tenant, $this->plan('growth')); // projects cap = 25
        $this->service()->increment($this->tenant, 'projects');
        $this->service()->increment($this->tenant, 'projects');

        $this->assertSame(23, $this->service()->remaining($this->tenant, 'projects'));

        // On scale, projects is unlimited (null cap) → remaining is null.
        $this->service()->assignPlan($this->tenant, $this->plan('scale'));
        $this->assertNull($this->service()->remaining($this->tenant, 'projects'));
        $this->assertTrue($this->service()->withinLimit($this->tenant, 'projects'));
    }

    public function test_monthly_metric_uses_a_monthly_period(): void
    {
        $this->service()->assignPlan($this->tenant, $this->plan('starter')); // reports_per_month cap = 10
        $this->service()->increment($this->tenant, 'reports_per_month');

        $period = now()->format('Y-m');
        $this->assertDatabaseHas('usage_counters', [
            'tenant_id' => $this->tenant->id, 'metric' => 'reports_per_month', 'period' => $period, 'count' => 1,
        ]);
        $this->assertSame(9, $this->service()->remaining($this->tenant, 'reports_per_month'));
    }

    public function test_tenant_without_a_subscription_defaults_to_the_most_permissive_plan_and_is_not_blocked(): void
    {
        // No subscription assigned.
        $this->assertNull($this->service()->subscriptionFor($this->tenant));
        $this->assertSame('scale', $this->service()->currentPlan($this->tenant)->code);

        // Even after usage, an unlimited default never blocks.
        $this->service()->increment($this->tenant, 'projects', 1000);
        $this->assertTrue($this->service()->withinLimit($this->tenant, 'projects'));
    }

    public function test_usage_and_subscriptions_are_isolated_per_tenant(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);

        $this->service()->assignPlan($this->tenant, $this->plan('starter'));
        $this->service()->assignPlan($other, $this->plan('growth'));

        $this->service()->increment($this->tenant, 'projects', 3); // A at its starter cap
        $this->service()->increment($other, 'projects', 1);

        // A is capped; B is not — counters do not bleed across tenants.
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
            'tenant_id' => $tenant->id, 'name' => 'V', 'email' => 'v@c.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($viewer, $tenant);
        $viewer->assignRole($role);

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/subscriptions/change', ['plan_code' => 'growth'])
            ->assertForbidden();
    }

    public function test_change_plan_assigns_the_subscription(): void
    {
        $user = $this->ownerWithAllPermissions();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/subscriptions/change', ['plan_code' => 'growth', 'seats' => 5])
            ->assertCreated()
            ->assertJsonPath('data.plan.code', 'growth')
            ->assertJsonPath('data.subscription.seats', 5);

        $this->assertSame('growth', $this->service()->currentPlan($this->tenant)->code);
    }
}
