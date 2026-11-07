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
use Database\Seeders\AddonSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PRICING-ADDONS-001 — add-ons that are real: a capacity add-on raises the cap the API enforces, only once ACTIVE; a request grants
 * and charges nothing; an add-on that is not ready cannot be requested or activated; a price captured at activation does not move
 * when the catalogue is re-priced; every console act carries a reason.
 */
final class AddonsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $admin;

    private ClientWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->seed(AddonSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Solo Co', 'slug' => 'solo-co', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@solo.test', 'password' => 'secret1234']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $this->workspace = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client-solo', 'mode' => 'managed']);
        app(SubscriptionService::class)->assignPlan($this->tenant, SubscriptionPlan::where('code', 'solo')->firstOrFail());
        app(TenantContext::class)->forget();

        $this->admin = User::create(['name' => 'Operator', 'email' => 'op@platform.test', 'password' => 'secret1234']);
        $this->admin->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();
    }

    private function createProject(string $name): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/projects', ['client_workspace_id' => $this->workspace->id, 'name' => $name]);
    }

    public function test_the_shop_shows_readiness_and_never_a_price_for_what_is_not_ready(): void
    {
        $addons = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/subscriptions/addons')->assertOk()->json('data.addons'))->keyBy('code');

        $this->assertTrue($addons['extra_projects_5']['sold']);
        $this->assertSame('149.00', $addons['extra_projects_5']['price_monthly']);
        $this->assertSame('SAR', $addons['extra_projects_5']['currency']);
        $this->assertSame('coming_soon', $addons['ai_intelligence']['readiness']);
        $this->assertNull($addons['ai_intelligence']['price_monthly']);
        $this->assertFalse($addons['ai_intelligence']['sold']);

        $this->postJson('/api/v1/subscriptions/addons/ai_intelligence/request')->assertStatus(422);
    }

    public function test_a_request_grants_nothing_and_an_approved_add_on_raises_the_enforced_cap(): void
    {
        $this->createProject('P1')->assertCreated();
        $this->createProject('P2')->assertStatus(403)->assertJsonPath('meta.limit', 1);

        $this->app['auth']->forgetGuards();
        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/subscriptions/addons/extra_projects_5/request')->assertCreated()->json('data.request_id');
        $this->postJson('/api/v1/subscriptions/addons/extra_projects_5/request')->assertStatus(409);
        $this->createProject('P2')->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/admin/tenant-addons/{$id}", ['decision' => 'approve', 'reason' => 'Paid by bank transfer'])->assertOk();
        $this->assertSame(6, app(SubscriptionService::class)->effectiveLimit($this->tenant, 'projects'));
        $this->createProject('P2')->assertCreated();

        // Re-pricing the catalogue does not re-price an add-on already held.
        Addon::where('code', 'extra_projects_5')->update(['price_monthly' => 199]);
        $this->assertSame('149.00', (string) TenantAddon::query()->findOrFail($id)->unit_price);

        // Cancelled: the capacity goes back to the plan's.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/admin/tenant-addons/{$id}", ['decision' => 'cancel', 'reason' => 'Customer ended it'])->assertOk();
        $this->assertSame(1, app(SubscriptionService::class)->effectiveLimit($this->tenant, 'projects'));
        $this->assertSame(2, AuditLog::withoutGlobalScopes()->where('action', 'like', 'platform.tenant.addon_%')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_the_console_edits_price_and_readiness_with_a_reason_and_never_sells_without_a_price(): void
    {
        $ai = Addon::where('code', 'ai_intelligence')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/admin/addons/{$ai->id}", ['readiness' => 'available'])->assertStatus(422);
        $this->patchJson("/api/v1/admin/addons/{$ai->id}", ['readiness' => 'available', 'reason' => 'Ready, no price yet'])->assertStatus(422);
        $this->patchJson("/api/v1/admin/addons/{$ai->id}", ['readiness' => 'available', 'price_monthly' => 249, 'reason' => 'Priced after cost measurement'])
            ->assertOk()->assertJsonPath('data.addon.sold', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.addon.updated', 'entity_id' => 'ai_intelligence']);
    }

    public function test_a_grant_from_the_console_is_active_at_once_and_a_customer_cannot_use_the_console(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/admin/tenants/{$this->tenant->id}/addons", ['code' => 'extra_users_3', 'reason' => 'trying it'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/admin/tenants/{$this->tenant->id}/addons", ['code' => 'extra_users_3', 'quantity' => 2, 'reason' => 'Enterprise pilot'])->assertOk();
        $this->assertSame(1 + 6, app(SubscriptionService::class)->effectiveLimit($this->tenant, 'team_members'));
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/admin/tenants/{$this->tenant->id}/addons", ['code' => 'automation_pro', 'reason' => 'not ready'])->assertStatus(422);
    }
}
