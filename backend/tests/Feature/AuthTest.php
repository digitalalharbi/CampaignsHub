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
            ->assertJsonStructure(['data' => ['user' => ['id', 'email', 'tenant_ids']]])
            ->assertJsonMissingPath('data.token'); // SPA never receives a token

        $this->assertDatabaseHas('tenants', ['name' => 'Acme Media']);
        // Registration puts them IN the workspace it just created — a membership, not a column.
        $this->assertTrue(User::where('email', 'sara@acme.test')->firstOrFail()->memberships()->exists());
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
            'name' => 'Ali',
            'email' => 'ali@t.test',
            'password' => 'secret123',
        ]);
        $this->grantMembership($user, $tenant);

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

    /**
     * "Keep me signed in" must actually do something. The flag reaches Auth::login($user, $remember),
     * which mints the long-lived remember cookie and persists a remember_token on the user.
     */
    public function test_remember_me_issues_a_persistent_login(): void
    {
        $tenant = Tenant::create(['name' => 'R', 'slug' => 'r', 'status' => 'active']);
        $user = User::create([
            'name' => 'Rem', 'email' => 'rem@t.test', 'password' => 'secret123',
        ]);
        $this->grantMembership($user, $tenant);

        $this->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/login', ['email' => 'rem@t.test', 'password' => 'secret123', 'remember' => true])
            ->assertOk();

        $this->assertNotNull($user->refresh()->remember_token, 'remember=true should persist a remember token');
    }

    /** Signing in without the flag must NOT leave a persistent login behind. */
    public function test_login_without_remember_does_not_persist(): void
    {
        $tenant = Tenant::create(['name' => 'N', 'slug' => 'n', 'status' => 'active']);
        $user = User::create([
            'name' => 'NoRem', 'email' => 'norem@t.test', 'password' => 'secret123',
        ]);
        $this->grantMembership($user, $tenant);

        $this->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/login', ['email' => 'norem@t.test', 'password' => 'secret123'])
            ->assertOk();

        $this->assertNull($user->refresh()->remember_token);
    }
}
