<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Subscriptions\Addons\Addon;
use App\Domains\Subscriptions\Addons\TenantAddon;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * SUPERADMIN-LIMITS-001 — a workspace's own cap, set from the console, is the cap the API enforces: raised, unlimited, back to
 * the plan, or lapsing on a date. Proven through HTTP creates (as PlanLimitEnforcementTest does), never by asking the service.
 */
final class PlatformLimitOverrideTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-limits', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@acme.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $this->workspace = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client-limits', 'mode' => 'managed']);
        // Starter caps projects at three; without a subscription the most permissive plan applies and nothing would be capped.
        app(SubscriptionService::class)->assignPlan($this->tenant, SubscriptionPlan::where('code', 'starter')->firstOrFail());
        app(TenantContext::class)->forget();

        $this->admin = User::create(['name' => 'Operator', 'email' => 'op@platform.test', 'password' => 'secret1234']);
        $this->admin->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();
    }

    private function createProject(string $name): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/projects', ['client_workspace_id' => $this->workspace->id, 'name' => $name]);
    }

    private function setLimit(array $body): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/tenants/{$this->tenant->id}/limits/projects", $body + ['reason' => 'Agreed with the customer']);
    }

    private function row(array $limits, string $metric = 'projects'): array
    {
        return collect($limits)->firstWhere('metric', $metric);
    }

    public function test_a_raised_cap_lets_the_create_through_and_inherit_puts_the_plan_back(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->createProject("P{$i}")->assertCreated();
        }
        $this->createProject('P4')->assertStatus(403)->assertJsonPath('meta.limit', 3);

        $limits = $this->setLimit(['mode' => 'cap', 'limit' => 5])->assertOk()->json('data.limits');
        $this->assertSame(['plan_limit' => 3, 'effective_limit' => 5, 'usage' => 3], array_intersect_key($this->row($limits), array_flip(['plan_limit', 'effective_limit', 'usage'])));
        $this->createProject('P4')->assertCreated();

        $this->setLimit(['mode' => 'inherit'])->assertOk();
        $this->createProject('P5')->assertStatus(403)->assertJsonPath('meta.limit', 3);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'platform.tenant.limit_set')->get();
        $this->assertCount(2, $audit);
        $raise = $audit->first(fn ($a) => $a->after['mode'] === 'cap');
        $this->assertSame((string) $this->tenant->id, (string) $raise->tenant_id);
        $this->assertEquals(['mode' => 'cap', 'limit' => 5, 'expires_at' => null], $raise->after);
        $this->assertSame('Agreed with the customer', $raise->reason);
        $this->assertEquals(['overridden' => true, 'limit' => 5], $audit->first(fn ($a) => $a->after['mode'] === 'inherit')->before);
    }

    public function test_unlimited_lifts_the_cap_and_a_lower_cap_refuses_sooner(): void
    {
        $this->setLimit(['mode' => 'cap', 'limit' => 1])->assertOk();
        $this->createProject('P1')->assertCreated();
        $this->createProject('P2')->assertStatus(403)->assertJsonPath('meta.limit', 1);

        $limits = $this->setLimit(['mode' => 'unlimited'])->assertOk()->json('data.limits');
        $this->assertNull($this->row($limits)['effective_limit']);
        $this->assertNull($this->row($limits)['override']['limit']);
        for ($i = 2; $i <= 5; $i++) {
            $this->createProject("P{$i}")->assertCreated();
        }
    }

    /** A paid capacity add-on keeps adding on top of an operator's cap — an override never takes away what was bought. */
    public function test_a_capacity_add_on_still_adds_on_top_of_an_override(): void
    {
        $addon = Addon::query()->create([
            'code' => 'test_projects_2', 'kind' => 'capacity', 'name' => '2 projects', 'name_ar' => 'مشروعان', 'price_monthly' => 10,
            'currency' => 'SAR', 'grants' => ['limits' => ['projects' => 2]], 'readiness' => 'available', 'is_active' => true,
        ]);
        TenantAddon::query()->create([
            'tenant_id' => $this->tenant->id, 'addon_id' => $addon->id, 'quantity' => 1, 'status' => 'active', 'source' => 'console',
        ]);

        $limits = $this->setLimit(['mode' => 'cap', 'limit' => 1])->assertOk()->json('data.limits');

        $this->assertSame(3, $this->row($limits)['effective_limit'], 'the operator cap of 1 plus the 2 the add-on bought');
    }

    public function test_a_temporary_raise_lapses_on_its_date(): void
    {
        $this->setLimit(['mode' => 'cap', 'limit' => 10, 'expires_at' => now()->addDay()->toIso8601String()])->assertOk();
        for ($i = 1; $i <= 4; $i++) {
            $this->createProject("P{$i}")->assertCreated();
        }

        Carbon::setTestNow(now()->addDays(2));
        $this->createProject('P5')->assertStatus(403)->assertJsonPath('meta.limit', 3);
        $this->app['auth']->forgetGuards();
        $row = $this->row($this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/admin/tenants/{$this->tenant->id}/limits")->assertOk()->json('data.limits'));
        $this->assertTrue($row['lapsed']);
        $this->assertNull($row['override']);
        Carbon::setTestNow();
    }

    public function test_only_an_admin_with_a_reason_and_a_known_metric(): void
    {
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/admin/tenants/{$this->tenant->id}/limits/projects", ['mode' => 'unlimited', 'reason' => 'myself please'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/tenants/{$this->tenant->id}/limits/projects", ['mode' => 'unlimited'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/tenants/{$this->tenant->id}/limits/projects", ['mode' => 'cap', 'reason' => 'no number given'])->assertStatus(422)->assertJsonValidationErrors('limit');
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/tenants/{$this->tenant->id}/limits/rockets", ['mode' => 'unlimited', 'reason' => 'not a metric'])->assertNotFound();
    }

    public function test_the_plan_and_its_price_are_untouched(): void
    {
        $plan = SubscriptionPlan::where('code', 'starter')->firstOrFail();
        $before = $plan->only(['price_monthly', 'currency', 'limits']);

        $this->setLimit(['mode' => 'unlimited'])->assertOk();

        $this->assertSame($before, $plan->fresh()->only(['price_monthly', 'currency', 'limits']));
    }
}
