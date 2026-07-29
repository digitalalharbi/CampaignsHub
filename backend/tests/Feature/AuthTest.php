<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** Requests from the SPA origin are treated as stateful (cookie session) by Sanctum. */
    private array $spaHeaders = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_registration_provisions_tenant_and_starts_session(): void
    {
        $response = $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/register', [
            'tenant_name' => 'Acme Media',
            'name' => 'Sara',
            'email' => 'sara@acme.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['user' => ['id', 'email', 'tenant_id']]])
            ->assertJsonMissingPath('data.token'); // SPA never receives a token

        $this->assertDatabaseHas('tenants', ['name' => 'Acme Media']);
        $this->assertNotNull(User::where('email', 'sara@acme.test')->first()->tenant_id);
    }

    public function test_registration_validates_input(): void
    {
        $this->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/register', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors(['tenant_name', 'name', 'email', 'password']);
    }

    public function test_login_authenticates_session_and_writes_audit_log(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Ali',
            'email' => 'ali@t.test',
            'password' => 'secret123',
        ]);

        $this->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/login', ['email' => 'ali@t.test', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonMissingPath('data.token');

        // The session established above authenticates the follow-up request.
        $this->withHeaders($this->spaHeaders)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJson(['data' => ['user' => ['email' => 'ali@t.test']]]);

        $this->assertTrue(
            AuditLog::where('action', 'user.login')->where('user_id', $user->id)->exists(),
            'login should be audited',
        );
    }

    public function test_login_rejects_bad_credentials(): void
    {
        User::create(['name' => 'X', 'email' => 'x@t.test', 'password' => 'secret123']);

        $this->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/login', ['email' => 'x@t.test', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()
            ->assertJson(['success' => false]);
    }

    public function test_token_endpoint_issues_pat_for_api_clients(): void
    {
        $user = User::create(['name' => 'Api', 'email' => 'api@t.test', 'password' => 'secret123']);

        $response = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'api@t.test',
            'password' => 'secret123',
            'device_name' => 'mobile',
        ])->assertOk()->assertJsonStructure(['data' => ['user', 'token']]);

        $token = $response->json('data.token');

        // The PAT authenticates a stateless API request.
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJson(['data' => ['user' => ['email' => 'api@t.test']]]);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_forgot_password_returns_generic_success_without_enumeration(): void
    {
        User::factory()->create(['email' => 'known@a.test']);

        // Known and unknown emails return the SAME 200 + message (no account enumeration).
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@a.test'])->assertOk()->json('message');
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@a.test'])->assertOk()->json('message');
        $this->assertSame($known, $unknown);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])->assertStatus(422);
    }
}
