<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    /** Requests from the SPA origin are treated as stateful (cookie session) by Sanctum. */
    private array $spaHeaders = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /**
     * Applying creates an APPLICATION and nothing else (SIGNUP-002).
     *
     * This test used to be called "registration provisions tenant and starts session", and it passed
     * — that was the defect. Submitting the form produced a tenant, a workspace, a user, a membership
     * and a live session in one step, which left no point in the journey at which verification,
     * approval or payment could be required of anyone.
     */
    public function test_applying_creates_no_workspace_no_account_and_no_session(): void
    {
        $response = $this->apply([
            'tenant_name' => 'Acme Media', 'name' => 'Sara', 'email' => 'sara@acme.test',
            'password' => 'secret1234', 'password_confirmation' => 'secret1234',
        ]);

        // 202: received, not created. Nothing exists yet that Sara can use.
        $response->assertStatus(202)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.registration.state', 'email_verification_required')
            ->assertJsonMissingPath('data.token')   // SPA never receives a token
            ->assertJsonMissingPath('data.user');   // …nor an account it does not have

        $this->assertDatabaseMissing('tenants', ['name' => 'Acme Media']);
        $this->assertDatabaseMissing('users', ['email' => 'sara@acme.test']);
        $this->assertGuest();
    }

    /** Proving the email is what creates the workspace, under the default (auto-activate) policy. */
    public function test_verifying_the_email_is_what_creates_the_workspace(): void
    {
        ['user' => $user] = $this->applyAndVerify([
            'tenant_name' => 'Acme Media', 'name' => 'Sara', 'email' => 'sara@acme.test',
        ]);

        $this->assertDatabaseHas('tenants', ['name' => 'Acme Media']);
        // They are IN the workspace that was created for them — a membership, not a column.
        $this->assertTrue($user->memberships()->exists());
        $this->assertNotNull($user->email_verified_at);
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

    /**
     * AUTH-NONSTATEFUL-ORIGIN — an origin that cannot hold a session is refused, not a 500.
     *
     * Reproduced deliberately against a local server: the same credentials answered 200 from a
     * listed origin and 500 from an unlisted one, with `local.ERROR: Session store not set on
     * request` in the log. Sanctum treats an unlisted Origin as non-stateful, so the session
     * middleware never runs and `session()->regenerate()` throws instead of the request being
     * refused.
     *
     * `withoutMiddleware(StartSession::class)` is how that state is reached in a test: it produces
     * exactly the request the controller met — one with no session store — without depending on the
     * suite's Sanctum configuration, which a test that set an Origin would.
     */
    public function test_a_request_that_cannot_hold_a_session_is_refused_rather_than_crashing(): void
    {
        $tenant = Tenant::create(['name' => 'NS', 'slug' => 'ns', 'status' => 'active']);
        $user = User::create(['name' => 'Nas', 'email' => 'nas@t.test', 'password' => 'secret123']);
        $this->grantMembership($user, $tenant);

        $this->withoutMiddleware(StartSession::class)
            ->postJson('/api/v1/auth/login', ['email' => 'nas@t.test', 'password' => 'secret123'])
            ->assertStatus(403);
    }

    /**
     * And it says nothing about the credentials, because it has not looked at them.
     *
     * The refusal runs BEFORE the password is checked: hashing for an origin that can never be
     * answered would make this path measurably slower for a real account than for an unknown one —
     * a timing oracle handed to exactly the caller who should have been turned away at the door.
     */
    public function test_the_refusal_does_not_reveal_whether_the_account_exists(): void
    {
        $tenant = Tenant::create(['name' => 'NS2', 'slug' => 'ns2', 'status' => 'active']);
        $user = User::create(['name' => 'Real', 'email' => 'real@t.test', 'password' => 'secret123']);
        $this->grantMembership($user, $tenant);

        $real = $this->withoutMiddleware(StartSession::class)
            ->postJson('/api/v1/auth/login', ['email' => 'real@t.test', 'password' => 'secret123']);

        $unknown = $this->withoutMiddleware(StartSession::class)
            ->postJson('/api/v1/auth/login', ['email' => 'nobody@t.test', 'password' => 'whatever']);

        $real->assertStatus(403);
        $unknown->assertStatus(403);
        $this->assertSame(
            $unknown->json('message'),
            $real->json('message'),
            'the refusal told a caller whether the account exists',
        );
    }

    /** And a listed origin still signs in — the guard refuses the unlisted case only. */
    public function test_a_stateful_origin_still_signs_in(): void
    {
        $tenant = Tenant::create(['name' => 'ST', 'slug' => 'st', 'status' => 'active']);
        $user = User::create(['name' => 'Sta', 'email' => 'sta@t.test', 'password' => 'secret123']);
        $this->grantMembership($user, $tenant);

        $this->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/login', ['email' => 'sta@t.test', 'password' => 'secret123'])
            ->assertOk();
    }
}
