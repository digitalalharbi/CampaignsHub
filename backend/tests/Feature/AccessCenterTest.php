<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Support\AccessCatalogue;
use App\Domains\Access\Support\WorkspaceRolePresets;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Identity\Services\InvitationService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ACCESS-CENTER-001 — «الفريق والنطاقات»: invitation categories, per-invitation areas, the one resolution point,
 * and the walls around all of it (owner request 2026-11-15; contract §5 P0 3, 4, 10).
 */
final class AccessCenterTest extends TestCase
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
    private function role(string $slug, array $keys, ?Tenant $tenant = null): Role
    {
        $role = Role::create(['tenant_id' => ($tenant ?? $this->tenant)->id, 'name' => $slug, 'slug' => $slug, 'is_system' => true]);
        $role->givePermissionTo(...$keys);

        return $role;
    }

    private function client(string $name, ?Tenant $tenant = null): ClientWorkspace
    {
        return ClientWorkspace::create(['tenant_id' => ($tenant ?? $this->tenant)->id, 'name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
    }

    private function accept(string $invitationId, string $name = 'New Person'): User
    {
        $token = 'tok-'.$invitationId;
        DB::table('workspace_invitations')->where('id', $invitationId)->update(['token_hash' => hash('sha256', $token)]);

        return app(InvitationService::class)->accept($token, $name, 'secret1234');
    }

    /** @return list<string> */
    private function keysIn(User $user): array
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);

        return $user->fresh()->permissionKeys()->sort()->values()->all();
    }

    // ── The catalogue: one source, no drift ────────────────────────────────────────────────────────────────────

    public function test_every_catalogue_key_sits_in_exactly_one_area_or_in_the_base_or_the_scope(): void
    {
        $catalogue = Permission::pluck('key')->map(fn ($k) => (string) $k)->all();
        $placed = [];
        foreach (AccessCatalogue::areas() as $area) {
            foreach ([...$area['view'], ...$area['manage']] as $key) {
                $this->assertContains($key, $catalogue, "area {$area['key']} names {$key}, which the catalogue does not hold");
                $placed[] = $key;
            }
        }
        $this->assertSame(array_unique($placed), $placed, 'a key sits in two areas');

        $outside = [...AccessCatalogue::BASE, ...AccessCatalogue::SCOPE];
        $missing = array_values(array_diff($catalogue, $placed, $outside));
        $this->assertSame([], $missing, 'catalogue keys in no area');
        $this->assertCount(10, AccessCatalogue::areas());
    }

    public function test_the_roles_screen_checkboxes_all_belong_to_an_area(): void
    {
        foreach (WorkspaceRolePresets::editableKeys() as $key) {
            $this->assertTrue(AccessCatalogue::areaOf($key) !== null || in_array($key, AccessCatalogue::SCOPE, true), $key);
        }
    }

    public function test_the_api_renders_the_areas_and_every_built_in_category(): void
    {
        Sanctum::actingAs($this->owner);
        $res = $this->getJson('/api/v1/settings/access')->assertOk();

        $this->assertSame(AccessCatalogue::areaKeys(), array_column($res->json('data.areas'), 'key'));
        $slugs = array_column($res->json('data.categories'), 'slug');
        foreach (WorkspaceRolePresets::ORDER as $slug) {
            $this->assertContains($slug, $slugs);
        }
        $finance = collect($res->json('data.categories'))->firstWhere('slug', 'finance');
        $this->assertSame('manage', $finance['areas']['finance']['level']);
        $this->assertSame('none', $finance['areas']['campaigns']['level']);
        $this->assertTrue($res->json('data.me.is_owner'));
    }

    // ── Characterisation: nothing changes for anybody who has no override ──────────────────────────────────────

    public function test_every_built_in_role_resolves_exactly_its_role_keys_when_nobody_set_an_override(): void
    {
        foreach (WorkspaceRolePresets::ORDER as $slug) {
            $role = WorkspaceRolePresets::ensure((string) $this->tenant->id, $slug);
            $user = $this->member("{$slug}@a.test", $role);
            $expected = $role->permissions()->pluck('key')->sort()->values()->all();

            $this->assertSame($expected, $this->keysIn($user), $slug);
        }
    }

    public function test_a_legacy_role_an_agency_portal_member_and_a_client_portal_user_keep_their_access(): void
    {
        $legacy = $this->role('account-manager', ['clients.view', 'campaigns.view', 'campaigns.launch', 'projects.view', 'projects.view.all']);
        $am = $this->member('am@a.test', $legacy);
        $this->assertSame(['campaigns.launch', 'campaigns.view', 'clients.view', 'projects.view', 'projects.view.all'], $this->keysIn($am));

        $client = $this->client('Acme');
        $portal = User::create(['name' => 'Portal', 'email' => 'portal@a.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($portal, $this->tenant, Portal::ClientPortal, 'client_viewer', [(string) $client->id]);
        $this->assertSame([], $this->keysIn($portal), 'a client-portal user holds no staff key, before and after');

        // The board lists the portal user under the client audience with their own portal role, untouched.
        Sanctum::actingAs($this->owner);
        $row = collect($this->getJson('/api/v1/settings/access')->assertOk()->json('data.members'))->firstWhere('email', 'portal@a.test');
        $this->assertSame('client', $row['audience']);
        $this->assertSame('client_viewer', $row['category']);
        $this->assertNull($row['areas']);
        $this->assertSame('client_viewer', DB::table('memberships')->where('user_id', $portal->id)->value('role'));
    }

    public function test_the_existing_team_invite_still_works_for_the_owner_and_carries_no_override(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/settings/team', ['email' => 'pm@a.test', 'role' => 'project-manager'])->assertStatus(201)->json('data.id');
        $this->assertNull(DB::table('workspace_invitations')->where('id', $id)->value('access_overrides'));

        $user = $this->accept($id);
        $this->assertSame(WorkspaceRolePresets::ensure((string) $this->tenant->id, 'project-manager')->permissions()->pluck('key')->sort()->values()->all(), $this->keysIn($user));
        $this->assertSame(0, DB::table('member_access_overrides')->count());
    }

    // ── Categories: the owner's alone ──────────────────────────────────────────────────────────────────────────

    public function test_the_owner_creates_renames_duplicates_and_deletes_an_unused_category_and_each_step_is_audited(): void
    {
        Sanctum::actingAs($this->owner);

        $slug = $this->postJson('/api/v1/settings/access/categories', [
            'name' => 'مشتري بلا مالية', 'from' => 'media-buyer', 'areas' => ['finance' => 'none'], 'reason' => 'no money',
        ])->assertStatus(201)->json('data.slug');
        $role = Role::where('tenant_id', $this->tenant->id)->where('slug', $slug)->firstOrFail();
        $keys = $role->permissions()->pluck('key')->all();
        $this->assertFalse($role->is_system);
        $this->assertContains('campaigns.launch', $keys);
        $this->assertNotContains('budget.view', $keys);
        $this->assertContains('projects.view.all', $keys, 'the base is always kept');

        $this->putJson("/api/v1/settings/access/categories/{$slug}", ['name' => 'مشتري', 'areas' => ['content' => 'view']])->assertOk();
        $this->assertSame('مشتري', $role->fresh()->name);
        $this->assertNotContains('content.create', $role->fresh()->permissions()->pluck('key')->all());

        $this->postJson('/api/v1/settings/access/categories', ['name' => 'مشتري'])->assertStatus(422); // name taken
        $this->deleteJson("/api/v1/settings/access/categories/{$slug}")->assertOk();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);

        foreach (['access.category_created', 'access.category_updated', 'access.category_deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => $action, 'user_id' => $this->owner->id]);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.category_created', 'reason' => 'no money']);
    }

    public function test_a_category_in_use_and_a_built_in_category_cannot_be_deleted_or_reshaped_here(): void
    {
        Sanctum::actingAs($this->owner);
        $slug = $this->postJson('/api/v1/settings/access/categories', ['name' => 'Custom', 'areas' => ['results' => 'view']])->assertStatus(201)->json('data.slug');
        $this->member('held@a.test', Role::where('tenant_id', $this->tenant->id)->where('slug', $slug)->firstOrFail());

        $this->deleteJson("/api/v1/settings/access/categories/{$slug}")->assertStatus(422);
        $this->putJson('/api/v1/settings/access/categories/viewer', ['areas' => ['finance' => 'manage']])->assertNotFound(); // not stored yet
        WorkspaceRolePresets::ensure((string) $this->tenant->id, 'viewer');
        $this->putJson('/api/v1/settings/access/categories/viewer', ['areas' => ['finance' => 'manage']])->assertStatus(422);
        $this->deleteJson('/api/v1/settings/access/categories/tenant-owner')->assertStatus(422);
    }

    public function test_only_the_owner_shapes_categories(): void
    {
        $admin = $this->member('admin@a.test', $this->role('settings-admin', Permission::where('key', '!=', 'clients.view_all')->pluck('key')->all()));
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/settings/access')->assertOk()->assertJsonPath('data.me.is_owner', false);
        $this->postJson('/api/v1/settings/access/categories', ['name' => 'Mine', 'areas' => ['results' => 'view']])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['name' => 'Mine']);
    }

    public function test_a_crafted_category_payload_is_refused(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/settings/access/categories', ['name' => 'X', 'areas' => ['root' => 'manage']])->assertStatus(422);
        $this->postJson('/api/v1/settings/access/categories', ['name' => 'X', 'areas' => ['finance' => 'admin']])->assertStatus(422);
        $this->postJson('/api/v1/settings/access/categories', ['name' => 'X', 'from' => 'nope'])->assertStatus(422);
        $this->assertDatabaseMissing('roles', ['name' => 'X']);
    }

    // ── Invitations: category + areas for THIS invitation ───────────────────────────────────────────────────────

    public function test_an_invitation_with_a_category_and_areas_switched_off_lands_on_the_member_after_acceptance(): void
    {
        $client = $this->client('Acme');
        Sanctum::actingAs($this->owner);

        $res = $this->postJson('/api/v1/settings/access/invitations', [
            'email' => 'Buyer@A.test', 'category' => 'media-buyer', 'areas' => ['finance' => 'none', 'content' => 'view'],
            'client_ids' => [(string) $client->id], 'reason' => 'campaigns only',
        ])->assertStatus(201);
        $this->assertSame('none', $res->json('data.areas.finance.level'));
        $id = $res->json('data.id');

        $stored = json_decode((string) DB::table('workspace_invitations')->where('id', $id)->value('access_overrides'), true);
        $this->assertSame([], $stored['allow']);
        $this->assertContains('budget.view', $stored['deny']);
        $this->assertContains('content.create', $stored['deny']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.team.invited', 'entity_id' => $id, 'reason' => 'campaigns only']);

        $user = $this->accept($id);
        $keys = $this->keysIn($user);
        $this->assertContains('campaigns.launch', $keys);
        $this->assertContains('content.view', $keys);
        $this->assertNotContains('content.create', $keys);
        $this->assertNotContains('budget.view', $keys);
        $this->assertSame([(string) $client->id], DB::table('membership_scopes')->join('memberships', 'memberships.id', '=', 'membership_scopes.membership_id')
            ->where('memberships.user_id', $user->id)->pluck('scope_id')->map(fn ($i) => (string) $i)->all());

        // Enforced on the routes, not only in the list: the finance surface refuses them.
        Sanctum::actingAs($user->fresh());
        $this->assertFalse($user->fresh()->hasPermission('budget.view'));
        $this->getJson('/api/v1/settings/access')->assertForbidden();
    }

    public function test_switching_an_area_on_for_one_invitation_adds_only_that_area(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/settings/access/invitations', ['email' => 'v@a.test', 'category' => 'viewer', 'areas' => ['leads' => 'manage']])
            ->assertStatus(201)->json('data.id');

        $keys = $this->keysIn($this->accept($id));
        $this->assertContains('leads.pii.view', $keys);
        $this->assertNotContains('campaigns.launch', $keys);
        $this->assertNotContains('budget.view', $keys);
    }

    public function test_a_pending_invitation_can_be_reshaped_and_resent_and_the_old_link_dies(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/settings/access/invitations', ['email' => 'r@a.test', 'category' => 'analyst'])->assertStatus(201)->json('data.id');

        $this->putJson("/api/v1/settings/access/invitations/{$id}", ['category' => 'reporter', 'areas' => ['results' => 'view']])->assertOk();
        $this->assertSame('reporter', DB::table('workspace_invitations')->where('id', $id)->value('role_slug'));

        DB::table('workspace_invitations')->where('id', $id)->update(['token_hash' => hash('sha256', 'old-token'), 'expires_at' => now()->subDay()]);
        $this->postJson("/api/v1/settings/access/invitations/{$id}/resend")->assertOk();
        $row = DB::table('workspace_invitations')->where('id', $id)->first();
        $this->assertNotSame(hash('sha256', 'old-token'), $row->token_hash);
        $this->assertTrue(now()->lt($row->expires_at));
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.invitation_resent', 'entity_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.invitation_updated', 'entity_id' => $id]);
    }

    // ── No escalation ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_nobody_switches_on_an_area_they_do_not_hold_or_hands_out_the_team(): void
    {
        $narrow = $this->member('narrow@a.test', $this->role('narrow-admin', ['settings.manage', 'clients.view', 'campaigns.view', 'projects.view', 'projects.view.all']));
        Sanctum::actingAs($narrow);

        // A category wider than the actor.
        $this->postJson('/api/v1/settings/access/invitations', ['email' => 'x@a.test', 'category' => 'finance'])->assertForbidden();
        // An area switched on that the actor does not hold.
        $this->postJson('/api/v1/settings/access/invitations', ['email' => 'x@a.test', 'category' => 'narrow-admin', 'areas' => ['finance' => 'view']])->assertForbidden();
        // The team itself: settings.manage is the owner's to give, even by somebody who holds it.
        $this->postJson('/api/v1/settings/access/invitations', ['email' => 'x@a.test', 'category' => 'narrow-admin'])->assertForbidden();
        $this->postJson('/api/v1/settings/team', ['email' => 'x@a.test', 'role' => 'narrow-admin'])->assertForbidden();
        $this->postJson('/api/v1/settings/access/invitations', ['email' => 'x@a.test', 'category' => 'tenant-owner'])->assertForbidden();
        $this->assertDatabaseMissing('workspace_invitations', ['email' => 'x@a.test']);
    }

    public function test_the_app_team_invitation_path_no_longer_hands_out_any_role(): void
    {
        $inviter = $this->member('inviter@a.test', $this->role('inviter', ['users.invite', 'clients.view', 'projects.view', 'projects.view.all']));
        Sanctum::actingAs($inviter);

        $this->postJson('/api/v1/app/team/invitations', ['email' => 'me2@a.test', 'role_slug' => 'tenant-owner'])->assertForbidden();
        $this->assertDatabaseMissing('workspace_invitations', ['email' => 'me2@a.test']);
    }

    public function test_member_overrides_obey_the_same_walls_and_are_audited(): void
    {
        $buyer = $this->member('buyer@a.test', WorkspaceRolePresets::ensure((string) $this->tenant->id, 'media-buyer'));
        $narrow = $this->member('narrow@a.test', $this->role('narrow-admin', ['settings.manage', 'campaigns.view', 'projects.view', 'projects.view.all']));

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/settings/access/members/{$buyer->uuid}/overrides", ['areas' => ['finance' => 'none'], 'reason' => 'no money'])->assertOk();
        $this->assertNotContains('budget.view', $this->keysIn($buyer));
        $this->putJson("/api/v1/settings/access/members/{$this->owner->uuid}/overrides", ['areas' => ['finance' => 'none']])->assertForbidden(); // self
        $history = $this->getJson("/api/v1/settings/access/history?type=member&id={$buyer->uuid}")->assertOk()->json('data.entries');
        $this->assertSame('access.member_overrides_changed', $history[0]['action']);
        $this->assertSame('no money', $history[0]['reason']);

        Sanctum::actingAs($narrow);
        $this->putJson("/api/v1/settings/access/members/{$buyer->uuid}/overrides", ['areas' => ['finance' => 'manage']])->assertForbidden();
        $this->putJson("/api/v1/settings/access/members/{$this->owner->uuid}/overrides", ['areas' => ['finance' => 'none']])->assertStatus(422);
        $this->putJson("/api/v1/settings/access/members/{$narrow->uuid}/overrides", ['areas' => ['finance' => 'manage']])->assertForbidden();

        // Clearing returns the member to exactly their category.
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/settings/access/members/{$buyer->uuid}/overrides", ['areas' => []])->assertOk();
        $this->assertSame(WorkspaceRolePresets::ensure((string) $this->tenant->id, 'media-buyer')->permissions()->pluck('key')->sort()->values()->all(), $this->keysIn($buyer));
    }

    public function test_a_confined_manager_shares_only_their_own_clients(): void
    {
        $mine = $this->client('Mine');
        $other = $this->client('Other');
        $confined = $this->member('c@a.test', $this->role('confined', ['settings.manage', 'clients.view', 'clients.view_all', 'campaigns.view', 'projects.view', 'projects.view.all']), clientIds: [(string) $mine->id]);
        $viewer = $this->role('plain', ['clients.view', 'projects.view', 'projects.view.all']);
        Sanctum::actingAs($confined);

        $this->postJson('/api/v1/settings/access/invitations', ['email' => 'y@a.test', 'category' => 'plain', 'client_ids' => [(string) $other->id]])->assertForbidden();
        $this->postJson('/api/v1/settings/access/invitations', ['email' => 'y@a.test', 'category' => 'plain', 'client_ids' => [(string) $mine->id]])->assertStatus(201);
        $this->assertSame($viewer->slug, DB::table('workspace_invitations')->where('email', 'y@a.test')->value('role_slug'));
        $clients = array_column($this->getJson('/api/v1/settings/access')->assertOk()->json('data.clients'), 'name');
        $this->assertSame(['Mine'], $clients);
    }

    public function test_without_settings_manage_the_access_center_is_closed(): void
    {
        $viewer = $this->member('v@a.test', WorkspaceRolePresets::ensure((string) $this->tenant->id, 'viewer'));
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/settings/access')->assertForbidden();
        $this->postJson('/api/v1/settings/access/invitations', ['email' => 'z@a.test', 'category' => 'viewer'])->assertForbidden();
        $this->getJson('/api/v1/settings/access/history?type=member&id='.$viewer->uuid)->assertForbidden();
    }

    // ── Isolation ──────────────────────────────────────────────────────────────────────────────────────────────

    public function test_another_workspace_sees_and_touches_nothing_of_this_one(): void
    {
        $buyer = $this->member('buyer@a.test', WorkspaceRolePresets::ensure((string) $this->tenant->id, 'media-buyer'));
        Sanctum::actingAs($this->owner);
        $inv = $this->postJson('/api/v1/settings/access/invitations', ['email' => 'p@a.test', 'category' => 'viewer'])->assertStatus(201)->json('data.id');
        $cat = $this->postJson('/api/v1/settings/access/categories', ['name' => 'A only', 'areas' => ['results' => 'view']])->json('data.slug');

        $b = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active', 'account_type' => 'agency']);
        $bOwnerRole = Role::create(['tenant_id' => $b->id, 'name' => 'Tenant Owner', 'slug' => 'tenant-owner', 'is_system' => true]);
        $bOwnerRole->givePermissionTo(...Permission::pluck('key')->all());
        $bOwner = $this->member('owner@b.test', $bOwnerRole, $b);
        $this->holdingTenant((string) $b->id);
        Sanctum::actingAs($bOwner);

        $board = $this->getJson('/api/v1/settings/access')->assertOk()->json('data');
        $this->assertNotContains('buyer@a.test', array_column($board['members'], 'email'));
        $this->assertSame([], $board['invitations']);
        $this->assertNotContains($cat, array_column($board['categories'], 'slug'));

        $this->putJson("/api/v1/settings/access/members/{$buyer->uuid}/overrides", ['areas' => ['finance' => 'none']])->assertNotFound();
        $this->putJson("/api/v1/settings/access/invitations/{$inv}", ['category' => 'viewer'])->assertNotFound();
        $this->postJson("/api/v1/settings/access/invitations/{$inv}/resend")->assertNotFound();
        $this->putJson("/api/v1/settings/access/categories/{$cat}", ['name' => 'stolen'])->assertNotFound();
        $this->deleteJson("/api/v1/settings/access/categories/{$cat}")->assertNotFound();
        $this->postJson('/api/v1/settings/access/invitations', ['email' => 'q@b.test', 'category' => $cat])->assertStatus(422);
        $this->assertSame([], $this->getJson("/api/v1/settings/access/history?type=member&id={$buyer->uuid}")->assertOk()->json('data.entries'));
        $this->assertSame(0, DB::table('member_access_overrides')->where('tenant_id', $b->id)->count());
    }

    public function test_an_override_counts_only_in_its_own_workspace_and_a_deny_counts_when_none_is_bound(): void
    {
        $b = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active', 'account_type' => 'agency']);
        $user = $this->member('both@a.test', WorkspaceRolePresets::ensure((string) $this->tenant->id, 'media-buyer'));
        $this->grantMembership($user, $b, Portal::Agency, 'member');
        $user->roles()->syncWithoutDetaching([WorkspaceRolePresets::ensure((string) $b->id, 'media-buyer')->id]);
        DB::table('member_access_overrides')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'permission_key' => 'budget.view', 'effect' => 'deny', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('member_access_overrides')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'permission_key' => 'leads.export', 'effect' => 'allow', 'created_at' => now(), 'updated_at' => now()]);

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $this->assertFalse($user->fresh()->hasPermission('budget.view'));
        $this->assertTrue($user->fresh()->hasPermission('leads.export'));

        app(TenantContext::class)->setTenantId((string) $b->id);
        $this->assertTrue($user->fresh()->hasPermission('budget.view'));
        $this->assertFalse($user->fresh()->hasPermission('leads.export'));

        app(TenantContext::class)->forget();
        $this->assertNotContains('budget.view', $user->fresh()->permissionKeys()->all());
        $this->assertNotContains('leads.export', $user->fresh()->permissionKeys()->all());
    }

    public function test_changing_a_members_category_clears_their_overrides_and_removing_them_deletes_the_rows(): void
    {
        $buyer = $this->member('buyer@a.test', WorkspaceRolePresets::ensure((string) $this->tenant->id, 'media-buyer'));
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/settings/access/members/{$buyer->uuid}/overrides", ['areas' => ['finance' => 'none']])->assertOk();
        $this->assertGreaterThan(0, DB::table('member_access_overrides')->where('user_id', $buyer->id)->count());

        $this->putJson("/api/v1/settings/team/{$buyer->uuid}/role", ['role' => 'analyst'])->assertOk();
        $this->assertSame(0, DB::table('member_access_overrides')->where('user_id', $buyer->id)->count());

        $this->putJson("/api/v1/settings/access/members/{$buyer->uuid}/overrides", ['areas' => ['results' => 'view']])->assertOk();
        $this->deleteJson("/api/v1/settings/team/{$buyer->uuid}")->assertOk();
        $this->assertSame(0, DB::table('member_access_overrides')->where('user_id', $buyer->id)->count());
    }

    // ── OWNER-DOWNGRADE-001: a mistaken promotion is reversible ───────────────────────────────────────────────

    public function test_a_workspace_manager_moves_another_one_back_to_finance_and_it_is_audited(): void
    {
        $ownerRole = Role::query()->where('tenant_id', $this->tenant->id)->where('slug', 'tenant-owner')->firstOrFail();
        $accountant = $this->member('accounting@a.test', $ownerRole);
        Sanctum::actingAs($this->owner);

        // Still a workspace manager: never narrowed in place.
        $this->putJson("/api/v1/settings/access/members/{$accountant->uuid}", ['areas' => ['finance' => 'none']])->assertStatus(422);

        $this->putJson("/api/v1/settings/access/members/{$accountant->uuid}", ['category' => 'finance', 'reason' => 'promoted by mistake'])
            ->assertOk()->assertJsonPath('data.category', 'finance');

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $this->assertFalse($accountant->fresh()->hasRole('tenant-owner'));
        $this->assertTrue($accountant->fresh()->hasRole('finance'));
        $this->assertNotContains('settings.manage', $this->keysIn($accountant));
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'access.member_updated', 'user_id' => $this->owner->id]);

        // Now an ordinary member: their sections can be chosen from the same screen.
        $this->putJson("/api/v1/settings/access/members/{$accountant->uuid}", ['areas' => ['finance' => 'view']])->assertOk();
    }

    public function test_only_a_workspace_manager_moves_another_and_never_the_last_one(): void
    {
        $ownerRole = Role::query()->where('tenant_id', $this->tenant->id)->where('slug', 'tenant-owner')->firstOrFail();
        $other = $this->member('second@a.test', $ownerRole);
        $admin = $this->member('admin@a.test', $this->role('team-admin', ['settings.manage', 'users.view', 'users.update', 'projects.view']));

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/settings/access/members/{$other->uuid}", ['category' => 'finance'])->assertForbidden();

        // Only one manager left: not even the platform operator moves them out.
        $this->owner->roles()->detach($ownerRole->id);
        $admin->forceFill(['is_platform_admin' => true])->save();
        Sanctum::actingAs($admin->fresh());
        $this->putJson("/api/v1/settings/access/members/{$other->uuid}", ['category' => 'finance'])->assertStatus(422);

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $this->assertTrue($other->fresh()->hasRole('tenant-owner'));
    }
}
