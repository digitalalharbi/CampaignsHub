<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SUPERADMIN-FEATURES-001 — capabilities switched from the console are refused on the SERVER, not merely hidden.
 *
 * Platform level: off for every workspace. Tenant level: one workspace's own switch, on / off / back to inheriting. Both are read
 * by the same resolver the `module:` middleware enforces; every change carries a reason into the audit trail.
 */
final class PlatformCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /** @return array{0: Tenant, 1: User} */
    private function workspace(): array
    {
        $tenant = Tenant::create(['name' => 'W', 'slug' => 'cap-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'U', 'email' => uniqid().'@cap.test', 'password' => 'secret1234']);
        $user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $user, tenant: $tenant, portal: Portal::App, role: 'owner'));
        app(TenantContext::class)->forget();

        return [$tenant, $user];
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'Operator', 'email' => 'op-'.uniqid().'@platform.test', 'password' => 'secret1234']);
        $u->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $u;
    }

    private function shortLinks(User $member): int
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($member, 'sanctum')->withHeaders(self::SPA)->getJson('/api/v1/short-links')->status();
    }

    public function test_only_a_platform_admin_switches_capabilities(): void
    {
        [$tenant, $member] = $this->workspace();

        $this->actingAs($member, 'sanctum')->putJson('/api/v1/admin/capabilities/smart_links', ['disabled' => true, 'reason' => 'trying it'])->assertForbidden();
        $this->actingAs($member, 'sanctum')->putJson("/api/v1/admin/tenants/{$tenant->id}/capabilities/smart_links", ['enabled' => false, 'reason' => 'trying it'])->assertForbidden();
    }

    public function test_a_platform_switch_off_is_refused_for_every_workspace_and_comes_back_when_switched_on(): void
    {
        [, $a] = $this->workspace();
        [, $b] = $this->workspace();
        $admin = $this->admin();
        $this->assertSame(200, $this->shortLinks($a));

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/capabilities/smart_links', ['disabled' => true, 'reason' => 'Incident on the redirect service'])
            ->assertOk();

        $this->assertSame(403, $this->shortLinks($a));
        $this->assertSame(403, $this->shortLinks($b));
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.capability.disabled', 'reason' => 'Incident on the redirect service']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/capabilities/smart_links', ['disabled' => false, 'reason' => 'Redirect service restored'])->assertOk();
        $this->assertSame(200, $this->shortLinks($a));
    }

    public function test_a_tenant_switch_affects_that_workspace_only_and_can_return_to_inheriting(): void
    {
        [$tenant, $member] = $this->workspace();
        [, $other] = $this->workspace();
        $admin = $this->admin();

        $states = $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/tenants/{$tenant->id}/capabilities/smart_links", ['enabled' => false, 'reason' => 'Customer asked to hide it'])
            ->assertOk()->json('data.capabilities');
        $smart = collect($states)->firstWhere('module', 'smart_links');
        $this->assertFalse($smart['enabled']);
        $this->assertFalse($smart['tenant_switch']);

        $this->assertSame(403, $this->shortLinks($member));
        $this->assertSame(200, $this->shortLinks($other), 'another workspace is untouched');

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'platform.tenant.capability_set')->sole();
        $this->assertSame((string) $tenant->id, (string) $audit->tenant_id);
        $this->assertSame('Customer asked to hide it', $audit->reason);

        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/tenants/{$tenant->id}/capabilities/smart_links", ['enabled' => null, 'reason' => 'Back to the plan default'])->assertOk();
        $this->assertSame(200, $this->shortLinks($member));
    }

    public function test_every_switch_needs_a_reason_and_an_unknown_module_is_not_found(): void
    {
        [$tenant] = $this->workspace();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/capabilities/smart_links', ['disabled' => true])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/tenants/{$tenant->id}/capabilities/smart_links", ['enabled' => false])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/capabilities/no_such_module', ['disabled' => true, 'reason' => 'nothing at all'])->assertNotFound();
    }

    /** Plan level: a plan carries a module or not; its active subscribers gain or lose it through the same resolver. */
    public function test_a_plan_carrying_a_module_entitles_its_subscribers_and_leaves_price_and_currency_alone(): void
    {
        [$tenant] = $this->workspace();
        [$other] = $this->workspace();
        $plan = SubscriptionPlan::create(['code' => 'growth', 'name' => 'Growth', 'price_monthly' => 499, 'currency' => 'USD', 'is_active' => true]);
        Subscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'seats' => 1]);
        $admin = $this->admin();
        $entitled = fn (Tenant $t) => collect($this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/tenants/{$t->id}/capabilities")->assertOk()
            ->json('data.capabilities'))->firstWhere('module', 'influencer_intelligence')['entitled'];

        $this->assertFalse($entitled($tenant));

        $data = $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/plans/{$plan->id}/capabilities/influencer_intelligence", ['enabled' => true, 'reason' => 'Growth now includes influencers'])
            ->assertOk()->json('data');
        $row = collect($data['plans'])->firstWhere('code', 'growth');
        $this->assertTrue($row['modules']['influencer_intelligence']);
        $this->assertSame(1, $row['workspaces']);

        $this->assertTrue($entitled($tenant));
        $this->assertFalse($entitled($other), 'a workspace on no plan is untouched');
        $fresh = $plan->fresh();
        $this->assertSame('499.00', (string) $fresh->price_monthly);
        $this->assertSame('USD', $fresh->currency);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'platform.plan.capability_set')->sole();
        $this->assertSame(['module' => 'influencer_intelligence', 'carried' => true, 'workspaces' => 1], $audit->after);
        $this->assertSame('Growth now includes influencers', $audit->reason);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/plans/{$plan->id}/capabilities/influencer_intelligence", ['enabled' => false, 'reason' => 'Withdrawn from Growth'])->assertOk();
        $this->assertFalse($entitled($tenant));
    }

    public function test_plan_switches_need_an_admin_a_reason_and_a_real_module(): void
    {
        [, $member] = $this->workspace();
        $plan = SubscriptionPlan::create(['code' => 'starter', 'name' => 'Starter', 'price_monthly' => 99, 'currency' => 'USD', 'is_active' => true]);

        $this->actingAs($member, 'sanctum')->putJson("/api/v1/admin/plans/{$plan->id}/capabilities/link_in_bio", ['enabled' => true, 'reason' => 'trying it'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/plans/{$plan->id}/capabilities/link_in_bio", ['enabled' => true])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/plans/{$plan->id}/capabilities/no_such_module", ['enabled' => true, 'reason' => 'nothing at all'])->assertNotFound();
        $this->assertNull($plan->fresh()->features);
    }
}
