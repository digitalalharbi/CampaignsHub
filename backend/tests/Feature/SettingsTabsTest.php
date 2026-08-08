<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Settings\Services\Totp;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Team, notification-preferences, security (password/MFA/policy) and branding settings. */
final class SettingsTabsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Role $ownerRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $this->ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($this->ownerRole);
    }

    public function test_team_list_and_last_owner_guards(): void
    {
        Sanctum::actingAs($this->owner);
        Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'slug' => 'analyst']);

        $this->getJson('/api/v1/settings/team')->assertOk()->assertJsonPath('data.members.0.is_owner', true);

        // Cannot change the last owner's role, disable, or remove themselves / last owner.
        $this->putJson("/api/v1/settings/team/{$this->owner->uuid}/role", ['role' => 'analyst'])->assertStatus(422);
        $this->postJson("/api/v1/settings/team/{$this->owner->uuid}/toggle")->assertStatus(422);
        $this->deleteJson("/api/v1/settings/team/{$this->owner->uuid}")->assertStatus(422);
    }

    /**
     * Inviting somebody creates an INVITATION, not an account — TEAM-INVITE-001.
     *
     * This used to provision the user immediately with a random 24-character password, which is why
     * the assertion below changed: a mistyped address became a real account holding that email
     * forever, that nobody could sign into and that appeared in the team list as a colleague. The
     * membership is now granted at acceptance, by `InvitationService`, which the workspace
     * invitation tests cover end to end.
     */
    public function test_invite_creates_an_invitation_and_not_an_account(): void
    {
        Sanctum::actingAs($this->owner);
        Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'slug' => 'analyst']);

        $this->postJson('/api/v1/settings/team', ['email' => 'new@a.test', 'role' => 'analyst'])
            ->assertStatus(201)
            ->assertJsonPath('data.delivery_status', 'awaiting_provider_credentials');

        $this->assertNull(User::where('email', 'new@a.test')->first(), 'an account was created before acceptance');
        $this->assertDatabaseHas('workspace_invitations', [
            'tenant_id' => $this->tenant->id, 'email' => 'new@a.test', 'role_slug' => 'analyst', 'accepted_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.team.invited']);
    }

    /** The team screen shows who has been invited, or «I invited Sara last week» has no answer. */
    public function test_the_team_listing_shows_invitations_nobody_has_accepted(): void
    {
        Sanctum::actingAs($this->owner);
        Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'slug' => 'analyst']);
        $this->postJson('/api/v1/settings/team', ['email' => 'pending@a.test', 'role' => 'analyst'])->assertStatus(201);

        $this->getJson('/api/v1/settings/team')->assertOk()
            ->assertJsonPath('data.invitations.0.email', 'pending@a.test')
            ->assertJsonPath('data.invitations.0.expired', false);
    }

    /**
     * Withdrawing an invitation removes the capability, not just the row from a screen.
     *
     * The token in somebody's inbox works until the row is gone, so a «revoked» flag that left the
     * row in place would be a button that says it did something it did not.
     */
    public function test_withdrawing_an_invitation_makes_the_link_stop_working(): void
    {
        Sanctum::actingAs($this->owner);
        Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'slug' => 'analyst']);
        $id = $this->postJson('/api/v1/settings/team', ['email' => 'gone@a.test', 'role' => 'analyst'])
            ->assertStatus(201)->json('data.id');

        $this->deleteJson("/api/v1/settings/team/invitations/{$id}")->assertOk();

        $this->assertDatabaseMissing('workspace_invitations', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.team.invitation_revoked']);
    }

    /** Another workspace's invitation is not this workspace's to withdraw. */
    public function test_an_invitation_from_another_workspace_cannot_be_withdrawn(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-invite', 'status' => 'active']);
        // The column is char(26): these ids are ULIDs, not UUIDs.
        $id = (string) Str::ulid();
        DB::table('workspace_invitations')->insert([
            'id' => $id, 'tenant_id' => $other->id, 'email' => 'x@other.test', 'role_slug' => 'analyst',
            'token_hash' => hash('sha256', 'x'), 'delivery_status' => 'awaiting_provider_credentials',
            'invited_by' => $this->owner->id, 'expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/v1/settings/team/invitations/{$id}")->assertStatus(404);
        $this->assertDatabaseHas('workspace_invitations', ['id' => $id]);
    }

    public function test_team_requires_permission(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'viewer2']);
        $viewer = User::create(['name' => 'V', 'email' => 'v@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $viewer->assignRole($role);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/settings/team')->assertForbidden();
    }

    public function test_notification_preferences_roundtrip(): void
    {
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/settings/notifications')->assertOk()->assertJsonPath('data.frequency', 'realtime');

        $this->putJson('/api/v1/settings/notifications', [
            'channels' => ['in_app' => true, 'email' => false],
            'categories' => ['budget' => ['in_app' => true, 'email' => false]],
            'frequency' => 'daily',
        ])->assertOk()->assertJsonPath('data.frequency', 'daily');
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $this->owner->id, 'frequency' => 'daily']);
    }

    public function test_change_password_verifies_current(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/settings/security/password', [
            'current_password' => 'wrong', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertStatus(422);

        $this->postJson('/api/v1/settings/security/password', [
            'current_password' => 'secret123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk();
    }

    public function test_mfa_setup_confirm_and_disable(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/settings/security/mfa/setup')->assertOk()->assertJsonStructure(['data' => ['secret', 'otpauth_uri']]);

        $secret = $this->owner->fresh()->two_factor_secret;
        $this->postJson('/api/v1/settings/security/mfa/confirm', ['code' => '000000'])->assertStatus(422);

        $totp = app(Totp::class);
        $ref = new \ReflectionMethod($totp, 'at');
        $ref->setAccessible(true);
        $code = $ref->invoke($totp, $secret, intdiv(time(), 30));
        $this->postJson('/api/v1/settings/security/mfa/confirm', ['code' => $code])->assertOk();
        $this->assertTrue($this->owner->fresh()->two_factor_enabled);

        $this->postJson('/api/v1/settings/security/mfa/disable', ['password' => 'secret123'])->assertOk();
        $this->assertFalse($this->owner->fresh()->two_factor_enabled);
    }

    public function test_branding_and_security_policy_gated_and_persisted(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/settings/branding', [
            'branding' => ['primary_color' => '#123456', 'report_accent' => '#654321', 'portal_name' => 'MyPortal'],
        ])->assertOk()->assertJsonPath('data.branding.portal_name', 'MyPortal');

        $this->putJson('/api/v1/settings/security/policy', [
            'policy' => ['session_timeout_minutes' => 30, 'alert_new_device' => true, 'alert_failed_logins' => false],
        ])->assertOk()->assertJsonPath('data.policy.session_timeout_minutes', 30);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.branding.updated']);
    }
}
