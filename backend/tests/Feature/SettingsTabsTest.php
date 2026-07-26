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
        $this->owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
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

    public function test_invite_creates_scoped_member(): void
    {
        Sanctum::actingAs($this->owner);
        Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'slug' => 'analyst']);

        $this->postJson('/api/v1/settings/team', ['name' => 'New', 'email' => 'new@a.test', 'role' => 'analyst'])
            ->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'new@a.test', 'tenant_id' => $this->tenant->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.team.invited']);
    }

    public function test_team_requires_permission(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'viewer2']);
        $viewer = User::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'email' => 'v@a.test', 'password' => 'secret123']);
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
