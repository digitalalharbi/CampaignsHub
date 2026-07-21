<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    public function test_registration_provisions_tenant_and_returns_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'tenant_name' => 'Acme Media',
            'name' => 'Sara',
            'email' => 'sara@acme.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['user' => ['id', 'email', 'tenant_id'], 'token']]);

        $this->assertDatabaseHas('tenants', ['name' => 'Acme Media']);
        $this->assertDatabaseHas('users', ['email' => 'sara@acme.test']);
        $this->assertNotNull(User::where('email', 'sara@acme.test')->first()->tenant_id);
    }

    public function test_registration_validates_input(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors(['tenant_name', 'name', 'email', 'password']);
    }

    public function test_login_succeeds_and_writes_audit_log(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Ali',
            'email' => 'ali@t.test',
            'password' => 'secret123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ali@t.test',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['data' => ['user', 'token']]);

        $this->assertTrue(
            AuditLog::where('action', 'user.login')->where('user_id', $user->id)->exists(),
            'login should be audited',
        );
    }

    public function test_login_rejects_bad_credentials(): void
    {
        User::create(['name' => 'X', 'email' => 'x@t.test', 'password' => 'secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'x@t.test', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()
            ->assertJson(['success' => false]);
    }

    public function test_me_returns_current_user_when_authenticated(): void
    {
        $user = User::create(['name' => 'Z', 'email' => 'z@t.test', 'password' => 'secret123']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJson(['data' => ['user' => ['email' => 'z@t.test']]]);
    }
}
