<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Models\TenantLimitOverride;
use App\Domains\Subscriptions\Services\PlanLimitFit;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PLAN-LIMITS-METER-001 (Owner, 2026-11-13) — a limit only ever stops ADDING, and a plan is never entered over its limits.
 *
 * Three claims, each asserted through HTTP or the lifecycle rather than the arithmetic alone:
 *
 *  1. At the cap, adding is refused and everything that already exists keeps working (read, rename) — nothing is stopped.
 *  2. A move to a plan the current usage does not fit is refused, naming exactly what is over, and removes nothing.
 *  3. A change booked for the period's end is not applied if the workspace has outgrown the target by then.
 */
final class PlanLimitsMeterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ClientWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-limits-meter', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-lm']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@limits-meter.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($this->owner, $this->tenant, role: 'owner');
        $this->owner->assignRole($role);

        $this->workspace = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client-lm', 'mode' => 'managed']);
    }

    private function onPlan(string $code): Subscription
    {
        $plan = SubscriptionPlan::where('code', $code)->firstOrFail();
        $end = Carbon::now()->addDays(20);

        $subscription = new Subscription;
        $subscription->forceFill([
            'tenant_id' => $this->tenant->id, 'plan_id' => $plan->getKey(), 'status' => 'active', 'billing_interval' => 'monthly',
            'unit_amount' => $plan->price_monthly, 'currency' => $plan->currency,
            'current_period_start' => $end->copy()->subDays(30), 'current_period_end' => $end, 'seats' => 1,
        ])->save();

        return $subscription->refresh();
    }

    private function projects(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            Project::create(['client_workspace_id' => $this->workspace->id, 'name' => "P{$i}", 'status' => 'active']);
        }
    }

    public function test_at_the_cap_adding_is_refused_but_what_exists_keeps_working(): void
    {
        $this->onPlan('solo');
        $this->projects(1);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/projects', ['client_workspace_id' => $this->workspace->id, 'name' => 'Four'])
            ->assertStatus(403)->assertJsonPath('meta.plan_limit', true)->assertJsonPath('meta.used', 1)->assertJsonPath('meta.limit', 1);

        // Running work is untouched: the list still reads, and an existing project can still be renamed.
        $list = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/projects')->assertOk();
        $this->assertCount(1, $list->json('data'));

        $first = Project::withoutGlobalScopes()->where('name', 'P1')->firstOrFail();
        $this->actingAs($this->owner, 'sanctum')->patchJson('/api/v1/projects/'.$first->id, ['name' => 'Renamed'])->assertOk();
        $this->assertSame('Renamed', $first->refresh()->name);
        $this->assertSame(1, Project::withoutGlobalScopes()->count(), 'nothing was removed to make room');
    }

    public function test_a_downgrade_that_does_not_fit_is_refused_and_names_what_is_over(): void
    {
        $this->onPlan('team');
        $this->projects(3); // solo allows 1

        $quote = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/subscriptions/plan-change/quote', ['plan_code' => 'solo', 'billing_interval' => 'monthly'])
            ->assertOk();
        $quote->assertJsonPath('data.fit.fits', false);
        $this->assertSame([['metric' => 'projects', 'used' => 3, 'limit' => 1, 'over' => 2]], collect($quote->json('data.fit.exceeds'))->map(fn (array $e): array => array_intersect_key($e, array_flip(['metric', 'used', 'limit', 'over'])))->all());

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/subscriptions/plan-change', ['plan_code' => 'solo', 'billing_interval' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonPath('meta.plan_downgrade_blocked', true)
            ->assertJsonPath('meta.exceeds.0.metric', 'projects')
            ->assertJsonPath('meta.exceeds.0.over', 2);

        // Nothing was deleted, nothing was scheduled, nothing was charged, and the plan is the same.
        $this->assertSame(3, Project::withoutGlobalScopes()->count());
        $subscription = Subscription::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame('team', $subscription->plan->code);
        $this->assertNull($subscription->scheduled_plan_id);
        $this->assertSame(0, SubscriptionPayment::withoutGlobalScopes()->count());
    }

    public function test_a_downgrade_that_fits_is_scheduled_as_before(): void
    {
        $this->onPlan('team');
        $this->projects(1);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/subscriptions/plan-change/quote', ['plan_code' => 'solo', 'billing_interval' => 'monthly'])
            ->assertOk()->assertJsonPath('data.fit.fits', true);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/subscriptions/plan-change', ['plan_code' => 'solo', 'billing_interval' => 'monthly'])
            ->assertCreated()->assertJsonPath('data.scheduled_plan', 'solo');
    }

    public function test_a_move_up_to_a_bigger_plan_is_never_blocked_by_usage(): void
    {
        $this->onPlan('solo');
        $this->projects(1);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/subscriptions/plan-change/quote', ['plan_code' => 'team', 'billing_interval' => 'monthly'])
            ->assertOk()->assertJsonPath('data.fit.fits', true);
    }

    public function test_a_booked_downgrade_is_withdrawn_if_the_workspace_outgrew_the_target_by_the_time_it_applies(): void
    {
        $subscription = $this->onPlan('team');
        $this->projects(1);
        app(SubscriptionLifecycle::class)->changePlan($subscription, SubscriptionPlan::where('code', 'solo')->firstOrFail(), 'monthly');
        $this->assertNotNull($subscription->refresh()->scheduled_plan_id);

        $this->projects(2); // 3 now, over solo's 1, while the change was waiting
        Carbon::setTestNow(Carbon::now()->addDays(21));

        $applied = app(SubscriptionLifecycle::class)->applyDueScheduledChanges();

        $this->assertSame(0, $applied);
        $subscription->refresh();
        $this->assertSame('team', $subscription->plan->code, 'they stay on the plan they paid for');
        $this->assertNull($subscription->scheduled_plan_id);
        $this->assertSame(3, Project::withoutGlobalScopes()->count());
        Carbon::setTestNow();
    }

    public function test_a_cap_the_platform_set_for_this_workspace_is_not_made_tighter_by_a_plan_change(): void
    {
        $this->onPlan('team');
        $this->projects(3);
        $tenant = Tenant::findOrFail($this->tenant->id);
        $starter = SubscriptionPlan::where('code', 'solo')->firstOrFail();

        $this->assertSame(['projects'], array_column(app(PlanLimitFit::class)->exceeding($tenant, $starter), 'metric'));

        // The platform owner gave THIS workspace ten projects whatever the plan: moving plan cannot take that away.
        TenantLimitOverride::create(['tenant_id' => $this->tenant->id, 'metric' => 'projects', 'limit_value' => 10, 'reason' => 'agreed']);

        $this->assertSame([], app(PlanLimitFit::class)->exceeding($tenant, $starter));
    }
}
