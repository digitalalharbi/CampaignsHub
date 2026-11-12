<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Support\WorkspaceRolePresets;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TEAM-ROLE-PRESETS-001 — the ready-made roles, their checkboxes, and the walls around both
 * (Owner, MASTER_FINAL Appendix A task 7; contract §5 P0 3, 4, 10).
 */
final class WorkspaceRolePresetsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->tenant->id);
        $owner = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Tenant Owner', 'slug' => 'tenant-owner', 'is_system' => true]);
        $owner->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = $this->member('owner@a.test', $owner);
    }

    private function member(string $email, Role $role, ?Tenant $tenant = null, array $clientIds = []): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($user, $tenant ?? $this->tenant, Portal::Agency, 'member', $clientIds);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    /** @param list<string> $keys */
    private function customRole(string $slug, array $keys, ?Tenant $tenant = null): Role
    {
        $role = Role::create(['tenant_id' => ($tenant ?? $this->tenant)->id, 'name' => $slug, 'slug' => $slug]);
        $role->givePermissionTo(...$keys);

        return $role;
    }

    private function keysOf(string $slug, ?Tenant $tenant = null): array
    {
        return Role::where('tenant_id', ($tenant ?? $this->tenant)->id)->where('slug', $slug)->firstOrFail()
            ->permissions()->pluck('key')->sort()->values()->all();
    }

    public function test_the_seven_ready_made_roles_are_listed_and_reading_creates_nothing(): void
    {
        Sanctum::actingAs($this->owner);
        $before = Role::count();

        $res = $this->getJson('/api/v1/settings/roles')->assertOk();

        $this->assertSame(WorkspaceRolePresets::ORDER, array_column($res->json('data.roles'), 'slug'));
        $this->assertSame(['مدير المساحة', 'مدير مشروع', 'المالية', 'مشتري إعلانات', 'محلل', 'معدّ تقارير', 'مطّلع'], array_column($res->json('data.roles'), 'name_ar'));
        $this->assertTrue($res->json('data.roles.0.locked'));
        $this->assertNotEmpty($res->json('data.sections'));
        $this->assertSame($before, Role::count(), 'a read must not write a role');
    }

    public function test_finance_starts_with_every_client_and_the_money_and_no_campaigns_content_or_settings(): void
    {
        $finance = WorkspaceRolePresets::defaults(WorkspaceRolePresets::FINANCE);

        $this->assertContains('clients.view_all', $finance);
        $this->assertContains('budget.manage', $finance);
        $this->assertContains('billing.view', $finance);
        foreach ($finance as $key) {
            $this->assertFalse(str_starts_with($key, 'campaigns.') || str_starts_with($key, 'content.'), $key);
        }
        $this->assertNotContains('settings.manage', $finance);
        $this->assertNotContains('analytics.view', $finance);
    }

    /** «مدير مشروع: عملاؤه فقط في كل مكان» — no preset but the manager and finance reaches every client. */
    public function test_only_the_workspace_manager_and_finance_reach_every_client_by_default(): void
    {
        foreach (WorkspaceRolePresets::ORDER as $slug) {
            $hasAll = in_array(ClientScopeResolver::ALL_CLIENTS, WorkspaceRolePresets::defaults($slug), true);
            $this->assertSame(in_array($slug, [WorkspaceRolePresets::OWNER, WorkspaceRolePresets::FINANCE], true), $hasAll, $slug);
        }
    }

    public function test_a_finance_member_reads_the_budget_and_the_home_page_but_not_the_campaigns(): void
    {
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Ettifaq', 'slug' => 'ettifaq', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'Ettifaq', 'status' => 'active']);
        $finance = $this->member('fin@a.test', WorkspaceRolePresets::ensure((string) $this->tenant->id, WorkspaceRolePresets::FINANCE));

        $this->actingAs($finance, 'sanctum');
        $abilities = app(ProjectAbilities::class)->for($finance, (string) $project->id);

        $this->assertContains(ProjectCapability::BUDGET_MANAGE, $abilities);
        $this->assertContains(ProjectCapability::DASHBOARD_VIEW, $abilities);
        $this->assertNotContains(ProjectCapability::CAMPAIGNS_VIEW, $abilities);
        $this->assertNotContains(ProjectCapability::CAMPAIGNS_MANAGE, $abilities);
        $this->assertFalse($finance->hasPermission('settings.manage'));
    }

    public function test_a_project_manager_reaches_only_the_clients_chosen_for_them(): void
    {
        $mine = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Mine', 'slug' => 'mine', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $other = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'slug' => 'other', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $pm = $this->member('pm@a.test', WorkspaceRolePresets::ensure((string) $this->tenant->id, WorkspaceRolePresets::PROJECT_MANAGER), clientIds: [(string) $mine->id]);

        Sanctum::actingAs($pm);
        $this->getJson('/api/v1/agency/team')->assertForbidden(); // no users.view in the preset
        $this->getJson("/api/v1/app/clients/{$mine->id}")->assertOk();
        $this->assertContains($this->getJson("/api/v1/app/clients/{$other->id}")->status(), [403, 404]);
        $names = collect($this->getJson('/api/v1/app/clients')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Mine'], $names);
    }

    public function test_saving_the_boxes_changes_only_the_screen_keys_and_is_audited(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/settings/roles/viewer', ['permissions' => ['clients.view', 'reports.view', 'reports.export']])
            ->assertOk()->assertJsonPath('data.permissions', ['clients.view', 'reports.view', 'reports.export']);

        $keys = $this->keysOf('viewer');
        $this->assertContains('projects.view.all', $keys, 'a key off the screen is kept');
        $this->assertContains('reports.export', $keys);
        $this->assertNotContains('campaigns.view', $keys, 'an unticked box is removed');
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.roles.updated']);

        $this->postJson('/api/v1/settings/roles/viewer/reset')->assertOk();
        $this->assertContains('campaigns.view', $this->keysOf('viewer'));
        $this->assertNotContains('reports.export', $this->keysOf('viewer'));
    }

    public function test_the_workspace_manager_cannot_be_edited_and_unknown_or_off_screen_keys_are_refused(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/settings/roles/tenant-owner', ['permissions' => []])->assertStatus(422);
        $this->putJson('/api/v1/settings/roles/no-such-role', ['permissions' => []])->assertNotFound();
        // `projects.view.all` exists in the catalogue but is not a box: it cannot be sent.
        $this->putJson('/api/v1/settings/roles/viewer', ['permissions' => ['projects.view.all']])->assertStatus(422);
        $this->putJson('/api/v1/settings/roles/viewer', ['permissions' => ['platform.everything']])->assertStatus(422);
    }

    /** Role escalation: a box is switched ON only by somebody who holds that key. */
    public function test_nobody_grants_a_permission_they_do_not_hold(): void
    {
        $admin = $this->member('admin@a.test', $this->customRole('settings-admin', ['settings.manage', 'clients.view', 'reports.view']));
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/settings/roles')->assertOk()
            ->assertJsonPath('data.grantable', ['clients.view', 'reports.view', 'settings.manage']);

        // «كل العملاء» on their own colleague's role would walk out of the ceiling.
        $this->putJson('/api/v1/settings/roles/viewer', ['permissions' => ['clients.view', 'clients.view_all']])->assertForbidden();
        // Nor may they materialise their way past the rule.
        $this->assertDatabaseMissing('roles', ['tenant_id' => $this->tenant->id, 'slug' => 'viewer']);

        // Narrowing needs nothing: unticking boxes they do not hold is allowed.
        $this->putJson('/api/v1/settings/roles/viewer', ['permissions' => ['clients.view']])->assertOk();
        $this->assertSame(['clients.view', 'notifications.view', 'projects.view', 'projects.view.all'], $this->keysOf('viewer'));
    }

    /** Role escalation through assignment: inviting or moving somebody into a role wider than your own. */
    public function test_a_role_wider_than_the_actor_cannot_be_handed_out(): void
    {
        $admin = $this->member('admin@a.test', $this->customRole('settings-admin', ['settings.manage', 'clients.view']));
        $colleague = $this->member('col@a.test', $this->customRole('plain', ['clients.view']));
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/settings/team', ['email' => 'second@a.test', 'role' => 'tenant-owner'])->assertForbidden();
        $this->postJson('/api/v1/settings/team', ['email' => 'second@a.test', 'role' => 'finance'])->assertForbidden();
        $this->putJson("/api/v1/settings/team/{$colleague->uuid}/role", ['role' => 'tenant-owner'])->assertForbidden();
        $this->assertDatabaseMissing('workspace_invitations', ['email' => 'second@a.test']);
        $this->assertDatabaseMissing('roles', ['tenant_id' => $this->tenant->id, 'slug' => 'finance']);
        $this->assertFalse($colleague->fresh()->hasRole('tenant-owner'));
    }

    public function test_inviting_into_a_ready_made_role_creates_it_in_this_workspace_only(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active', 'account_type' => 'agency']);
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/settings/team')->assertOk()->assertJsonFragment(['slug' => 'project-manager']);
        $this->postJson('/api/v1/settings/team', ['email' => 'pm@a.test', 'role' => 'project-manager'])->assertStatus(201);

        $this->assertDatabaseHas('roles', ['tenant_id' => $this->tenant->id, 'slug' => 'project-manager', 'is_system' => true]);
        $this->assertDatabaseMissing('roles', ['tenant_id' => $other->id, 'slug' => 'project-manager']);
        $this->assertDatabaseHas('workspace_invitations', ['tenant_id' => $this->tenant->id, 'email' => 'pm@a.test', 'role_slug' => 'project-manager']);
    }

    /** Cross-tenant: another workspace's role of the same slug is neither read nor written. */
    public function test_another_workspaces_role_is_never_touched(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active', 'account_type' => 'agency']);
        $theirs = $this->customRole('viewer', ['clients.view', 'campaigns.view', 'leads.view'], $other);
        $theirCustom = $this->customRole('b-only', ['clients.view'], $other);
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/settings/roles')->assertOk();
        $this->putJson('/api/v1/settings/roles/viewer', ['permissions' => ['clients.view']])->assertOk();
        $this->putJson('/api/v1/settings/roles/b-only', ['permissions' => []])->assertNotFound();
        $this->postJson('/api/v1/settings/team', ['email' => 'x@a.test', 'role' => 'b-only'])->assertStatus(422);

        $this->assertSame(['campaigns.view', 'clients.view', 'leads.view'], $theirs->permissions()->pluck('key')->sort()->values()->all());
        $this->assertNotSame($theirs->id, Role::where('tenant_id', $this->tenant->id)->where('slug', 'viewer')->value('id'));
        $this->assertSame(1, $theirCustom->permissions()->count());
    }

    public function test_the_roles_screen_needs_settings_manage(): void
    {
        $plain = $this->member('plain@a.test', $this->customRole('plain', ['clients.view', 'users.view']));
        Sanctum::actingAs($plain);

        $this->getJson('/api/v1/settings/roles')->assertForbidden();
        $this->putJson('/api/v1/settings/roles/viewer', ['permissions' => []])->assertForbidden();
        $this->postJson('/api/v1/settings/roles/viewer/reset')->assertForbidden();
        $this->assertSame(0, DB::table('roles')->where('slug', 'viewer')->count());
    }
}
