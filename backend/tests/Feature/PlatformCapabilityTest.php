<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
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
}
