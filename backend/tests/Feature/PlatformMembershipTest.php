<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SUPERADMIN-MEMBERS-001 — a person's role and membership in ONE workspace, changed from the console, with a reason, audited in
 * that workspace, never touching their other workspaces or deleting their account, and never leaving a workspace ownerless.
 */
final class PlatformMembershipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->acme = $this->tenant('Acme');
        $this->beta = $this->tenant('Beta');
        app(TenantContext::class)->forget();
    }

    private function tenant(string $name): Tenant
    {
        $t = Tenant::create(['name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
        foreach (['tenant-owner' => 'Owner', 'media-buyer' => 'Media buyer'] as $slug => $label) {
            Role::create(['tenant_id' => $t->id, 'name' => $label, 'slug' => $slug]);
        }

        return $t;
    }

    private function person(string $email, array $in): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => 'secret1234']);
        foreach ($in as [$tenant, $slug]) {
            app(TenantContext::class)->setTenantId((string) $tenant->id);
            $this->grantMembership($u, $tenant);
            $u->roles()->attach(Role::query()->where('tenant_id', $tenant->id)->where('slug', $slug)->value('id'));
        }
        app(TenantContext::class)->forget();

        return $u;
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'Operator', 'email' => 'op-'.uniqid().'@platform.test', 'password' => 'secret1234']);
        $u->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $u;
    }

    private function act(Tenant $tenant, User $user, array $body)
    {
        return $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/tenants/'.$tenant->id.'/members/'.$user->uuid, $body + ['reason' => 'Requested by the agency owner']);
    }

    private function rolesIn(User $u, Tenant $t): array
    {
        return $u->roles()->where('roles.tenant_id', $t->id)->pluck('slug')->all();
    }

    public function test_only_a_platform_admin_acts_and_always_with_a_reason(): void
    {
        $sam = $this->person('sam@x.test', [[$this->acme, 'media-buyer']]);
        $this->actingAs($sam, 'sanctum')->postJson('/api/v1/admin/tenants/'.$this->acme->id.'/members/'.$sam->uuid, ['action' => 'suspend', 'reason' => 'whatever reason'])->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/v1/admin/tenants/'.$this->acme->id.'/members/'.$sam->uuid, ['action' => 'suspend'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_a_role_change_replaces_this_workspaces_role_only_and_is_audited_there(): void
    {
        $this->person('owner@x.test', [[$this->acme, 'tenant-owner']]);
        $sam = $this->person('sam@x.test', [[$this->acme, 'media-buyer'], [$this->beta, 'media-buyer']]);

        $this->act($this->acme, $sam, ['action' => 'change_role', 'role' => 'tenant-owner'])->assertOk()
            ->assertJsonPath('data.member.roles', ['tenant-owner']);

        $this->assertSame(['tenant-owner'], $this->rolesIn($sam, $this->acme));
        $this->assertSame(['media-buyer'], $this->rolesIn($sam, $this->beta), 'another workspace keeps its role');
        $audit = AuditLog::query()->where('action', 'platform.member.change_role')->sole();
        $this->assertSame((string) $this->acme->id, (string) $audit->tenant_id);
        $this->assertSame(['media-buyer'], $audit->before['roles']);
        $this->assertSame('Requested by the agency owner', $audit->reason);
    }

    public function test_a_role_from_another_workspace_is_refused(): void
    {
        $sam = $this->person('sam@x.test', [[$this->acme, 'media-buyer']]);
        Role::create(['tenant_id' => $this->beta->id, 'name' => 'Beta only', 'slug' => 'beta-only']);

        $this->act($this->acme, $sam, ['action' => 'change_role', 'role' => 'beta-only'])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_the_last_owner_cannot_be_demoted_suspended_or_removed(): void
    {
        $owner = $this->person('owner@x.test', [[$this->acme, 'tenant-owner']]);

        $this->act($this->acme, $owner, ['action' => 'change_role', 'role' => 'media-buyer'])->assertStatus(422)->assertJsonValidationErrors('role');
        $this->act($this->acme, $owner, ['action' => 'suspend'])->assertStatus(422)->assertJsonValidationErrors('action');
        $this->act($this->acme, $owner, ['action' => 'remove'])->assertStatus(422)->assertJsonValidationErrors('action');
        $this->assertSame(['tenant-owner'], $this->rolesIn($owner, $this->acme));
        $this->assertSame('active', Membership::withoutGlobalScopes()->where('user_id', $owner->id)->value('status'));
    }

    public function test_suspend_and_reactivate_touch_this_membership_only(): void
    {
        $this->person('owner@x.test', [[$this->acme, 'tenant-owner']]);
        $sam = $this->person('sam@x.test', [[$this->acme, 'media-buyer'], [$this->beta, 'media-buyer']]);

        $this->act($this->acme, $sam, ['action' => 'suspend'])->assertOk()->assertJsonPath('data.member.status', 'suspended');
        $this->assertSame('active', Membership::withoutGlobalScopes()->where('user_id', $sam->id)->where('tenant_id', $this->beta->id)->value('status'));
        $this->assertNull($sam->fresh()->disabled_at, 'the account is not disabled');

        $this->act($this->acme, $sam, ['action' => 'reactivate'])->assertOk()->assertJsonPath('data.member.status', 'active');
    }

    public function test_remove_leaves_the_workspace_and_keeps_the_account(): void
    {
        $this->person('owner@x.test', [[$this->acme, 'tenant-owner']]);
        $sam = $this->person('sam@x.test', [[$this->acme, 'media-buyer']]);

        $this->act($this->acme, $sam, ['action' => 'remove'])->assertOk()->assertJsonPath('data.member.member', false);

        $this->assertNotNull(User::query()->find($sam->id), 'the account is never deleted from the console');
        $this->assertSame([], $this->rolesIn($sam, $this->acme));
        $this->assertFalse(Membership::withoutGlobalScopes()->where('user_id', $sam->id)->exists());
        $this->assertNotNull($sam->fresh()->sessions_valid_after, 'with no workspace left, their sessions end');
    }

    public function test_someone_outside_the_workspace_or_another_platform_admin_is_refused(): void
    {
        $stranger = $this->person('stranger@x.test', [[$this->beta, 'media-buyer']]);
        $this->act($this->acme, $stranger, ['action' => 'suspend'])->assertNotFound();

        $other = $this->admin();
        $this->act($this->acme, $other, ['action' => 'suspend'])->assertForbidden();
    }

    public function test_the_tenant_view_lists_each_persons_roles_and_the_workspaces_roles(): void
    {
        $this->person('sam@x.test', [[$this->acme, 'media-buyer']]);

        $data = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/admin/tenants/'.$this->acme->id)->assertOk()->json('data');

        $this->assertSame(['media-buyer'], collect($data['people'])->firstWhere('email', 'sam@x.test')['roles']);
        $this->assertEqualsCanonicalizing(['tenant-owner', 'media-buyer'], collect($data['available_roles'])->pluck('slug')->all());
    }
}
