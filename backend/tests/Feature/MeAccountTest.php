<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class MeAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $tenant = Tenant::create(['name' => 'Demo Agency', 'slug' => 'demo', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create([
            'name' => 'Sara Ali', 'email' => 'sara@demo.test',
            'password' => 'secret123',
        ]);
        $this->grantMembership($this->user, $tenant);
        // email_verified_at is guarded (not fillable); set it directly so status resolves to "active".
        $this->user->forceFill(['email_verified_at' => now()])->save();
        $this->user->assignRole($role);
    }

    public function test_me_returns_profile_and_menu_header_fields(): void
    {
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'sara@demo.test')
            ->assertJsonPath('data.role', 'Owner')
            ->assertJsonPath('data.workspace_name', 'Demo Agency')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.initials', 'SA')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.two_factor_secret');
    }

    public function test_updating_profile_persists_audits_and_reflects_in_me(): void
    {
        $this->actingAs($this->user, 'sanctum')->patchJson('/api/v1/me/profile', [
            'name' => 'Sara A. Ali', 'job_title' => 'Media Buyer', 'locale' => 'en', 'number_format' => 'arabic',
        ])->assertOk()->assertJsonPath('data.name', 'Sara A. Ali')->assertJsonPath('data.job_title', 'Media Buyer');

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/me')
            ->assertJsonPath('data.name', 'Sara A. Ali')
            ->assertJsonPath('data.locale', 'en');

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.profile_updated', 'user_id' => $this->user->id]);
    }

    public function test_profile_rejects_invalid_enum_values(): void
    {
        $this->actingAs($this->user, 'sanctum')->patchJson('/api/v1/me/profile', ['locale' => 'fr'])
            ->assertStatus(422)->assertJsonValidationErrors('locale');
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $this->actingAs($this->user, 'sanctum')->patchJson('/api/v1/me/password', [
            'current_password' => 'wrong-password', 'password' => 'newsecret123', 'password_confirmation' => 'newsecret123',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        // Password must be unchanged.
        $this->assertTrue(Hash::check('secret123', $this->user->fresh()->password));
    }

    public function test_password_change_succeeds_audits_without_secrets_and_leaks_nothing(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->patchJson('/api/v1/me/password', [
            'current_password' => 'secret123', 'password' => 'newsecret123', 'password_confirmation' => 'newsecret123',
            'logout_other_devices' => true,
        ])->assertOk();

        $this->assertTrue(Hash::check('newsecret123', $this->user->fresh()->password));
        $this->assertStringNotContainsString('newsecret123', $response->getContent());
        $this->assertStringNotContainsString('secret123', $response->getContent());

        $audit = AuditLog::where('action', 'user.password_changed')->firstOrFail();
        $this->assertStringNotContainsString('secret', json_encode($audit->after ?? []));
    }

    public function test_new_password_must_differ_from_current(): void
    {
        $this->actingAs($this->user, 'sanctum')->patchJson('/api/v1/me/password', [
            'current_password' => 'secret123', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_account_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->patchJson('/api/v1/me/profile', ['name' => 'x'])->assertUnauthorized();
        $this->patchJson('/api/v1/me/password', [])->assertUnauthorized();
    }

    public function test_sessions_returns_current_device_summary(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/me/sessions')
            ->assertOk()
            ->assertJsonPath('data.others_available', false)
            ->assertJsonStructure(['data' => ['current' => ['ip', 'browser', 'platform', 'last_active_at']]]);
    }
}
