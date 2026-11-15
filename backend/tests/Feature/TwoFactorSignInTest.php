<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Models\OAuthIdentity;
use App\Domains\Requests\Models\ContactVerification;
use App\Domains\Settings\Services\Totp;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Frontend;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TWO-STEP-001 — a second step the account owner turned on is asked for at sign-in, every time.
 *
 * Reproduces the Owner's report: two-factor was switched on in «الأمان والجلسات», and the next sign-in with the right password
 * went straight in. The flag was stored and nothing at sign-in ever read it.
 */
final class TwoFactorSignInTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-123';

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /** @var array<string, string> */
    private array $spa = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function person(array $attributes = []): User
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        $user = User::factory()->create(array_merge([
            'email' => 'twostep@t.test',
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
        ], $attributes));
        $this->grantMembership($user, $tenant);

        return $user;
    }

    private function withTotp(array $attributes = []): User
    {
        return $this->person(array_merge([
            'two_factor_enabled' => true,
            'two_factor_secret' => self::SECRET,
        ], $attributes));
    }

    /** The current code, computed exactly as an authenticator app would. */
    private function totpAt(int $offsetSteps = 0): string
    {
        return (new Totp)->codeAt(self::SECRET, intdiv(time(), 30) + $offsetSteps);
    }

    private function login(): TestResponse
    {
        return $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => 'twostep@t.test', 'password' => self::PASSWORD]);
    }

    public function test_the_right_password_alone_does_not_sign_in_an_account_with_two_step_on(): void
    {
        $this->withTotp();

        $this->login()->assertOk()
            ->assertJsonPath('data.user', null)
            ->assertJsonPath('data.two_factor.required', true)
            ->assertJsonPath('meta.two_factor_required', true);

        $this->assertGuest('web');
        $this->withHeaders($this->spa)->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('meta.two_factor_required', true);
    }

    public function test_the_session_opens_only_after_a_correct_authenticator_code(): void
    {
        $user = $this->withTotp();
        $this->login()->assertOk();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/verify', ['method' => 'totp', 'code' => '000000'])
            ->assertStatus(422);
        $this->assertGuest('web');

        $before = session()->getId();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/verify', ['method' => 'totp', 'code' => $this->totpAt()])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'twostep@t.test');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($before, session()->getId(), 'the session id is regenerated after the second step');
        $this->withHeaders($this->spa)->getJson('/api/v1/auth/me')->assertOk();

        $this->assertTrue(AuditLog::where('action', 'security.two_factor.challenge_failed')->where('user_id', $user->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'security.two_factor.challenge_passed')->where('user_id', $user->id)->exists());
    }

    public function test_an_authenticator_code_cannot_be_used_twice(): void
    {
        $this->withTotp();
        $code = $this->totpAt();

        $this->login();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/verify', ['method' => 'totp', 'code' => $code])->assertOk();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/logout')->assertOk();

        $this->login();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/verify', ['method' => 'totp', 'code' => $code])
            ->assertStatus(422);
        $this->assertGuest('web');
    }

    public function test_repeated_wrong_codes_end_the_attempt_with_a_generic_answer(): void
    {
        $this->withTotp();
        $this->login();

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/verify', ['method' => 'totp', 'code' => '000000']);
        }

        // The pending step is gone: even the right code now needs a fresh sign-in.
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/verify', ['method' => 'totp', 'code' => $this->totpAt()])
            ->assertStatus(410);
        $this->assertGuest('web');
    }

    public function test_an_account_without_two_step_signs_in_as_before(): void
    {
        $this->person();

        $this->login()->assertOk()->assertJsonPath('data.user.email', 'twostep@t.test');
        $this->assertAuthenticated('web');
    }

    public function test_google_sign_in_is_held_at_the_second_step_too(): void
    {
        $user = $this->withTotp();

        $this->googleSignIn($user)->assertRedirect(Frontend::url('/login?two_step=1'));
        $this->assertGuest('web');

        $this->withHeaders($this->spa)->getJson('/api/v1/auth/two-factor')->assertOk()
            ->assertJsonPath('data.two_factor.methods', ['totp']);
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/verify', ['method' => 'totp', 'code' => $this->totpAt()])
            ->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_a_phone_code_is_the_first_step_not_both(): void
    {
        $this->withTotp(['phone' => '+966501234567', 'phone_verified_at' => now()]);

        $start = $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/start', ['phone' => '0501234567'])->assertOk();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/verify', [
            'verification_id' => $start->json('data.verification_id'),
            'code' => $start->json('data.dev_code'),
        ])->assertOk()->assertJsonPath('data.user', null)->assertJsonPath('data.two_factor.required', true);

        $this->assertGuest('web');
    }

    public function test_a_session_opened_without_the_second_step_is_ended(): void
    {
        $user = $this->withTotp();

        // A session that predates the check (or any door that skipped it): signed in, never passed.
        $this->actingAs($user, 'web');
        $this->withHeaders($this->spa)->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('meta.two_factor_required', true);
    }

    public function test_an_email_code_answers_the_challenge_once(): void
    {
        $user = $this->person(['two_factor_enabled' => true, 'two_factor_email_enabled_at' => now(), 'two_factor_default_method' => 'email']);
        $this->login()->assertJsonPath('data.two_factor.methods', ['email']);

        $sent = $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/send', ['method' => 'email'])->assertOk();
        $code = (string) $sent->json('data.dev_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        // Stored only as a hash.
        $this->assertFalse(ContactVerification::query()->where('code_hash', $code)->exists());

        // A second send inside the cooldown is refused.
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/send', ['method' => 'email'])->assertStatus(422);

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/verify', ['method' => 'email', 'code' => $code])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_an_expired_email_code_is_refused(): void
    {
        $this->person(['two_factor_enabled' => true, 'two_factor_email_enabled_at' => now()]);
        $this->login();
        $code = (string) $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/send', ['method' => 'email'])->json('data.dev_code');

        ContactVerification::query()->update(['expires_at' => now()->subMinute()]);

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/verify', ['method' => 'email', 'code' => $code])->assertStatus(422);
        $this->assertGuest('web');
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = $this->withTotp();
        $user->forceFill(['two_factor_recovery_codes' => [hash('sha256', 'abcde12345')]])->save();

        $this->login();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/verify', ['method' => 'recovery', 'code' => 'abcde-12345'])->assertOk();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/logout');

        $this->login();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/verify', ['method' => 'recovery', 'code' => 'abcde-12345'])->assertStatus(422);
        $this->assertTrue(AuditLog::where('action', 'security.two_factor.recovery_used')->where('user_id', $user->id)->exists());
    }

    public function test_a_wrong_password_reveals_nothing_about_the_second_step(): void
    {
        $this->withTotp();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => 'twostep@t.test', 'password' => 'wrong-one'])
            ->assertStatus(422)
            ->assertJsonMissingPath('data.two_factor')
            ->assertJsonMissingPath('meta.two_factor_required');
    }

    public function test_an_api_token_needs_the_second_step_too(): void
    {
        $this->withTotp();
        $body = ['email' => 'twostep@t.test', 'password' => self::PASSWORD, 'device_name' => 'cli'];

        $this->postJson('/api/v1/auth/tokens', $body)->assertStatus(401)->assertJsonPath('meta.two_factor_required', true)->assertJsonMissingPath('data.token');
        $this->postJson('/api/v1/auth/tokens', $body + ['two_factor_code' => '000000'])->assertStatus(422);
        $this->postJson('/api/v1/auth/tokens', $body + ['two_factor_method' => 'totp', 'two_factor_code' => $this->totpAt()])
            ->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_the_audit_trail_carries_no_code(): void
    {
        $this->withTotp();
        $this->login();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/two-factor/verify', ['method' => 'totp', 'code' => '123456']);

        foreach (AuditLog::query()->where('action', 'like', 'security.two_factor.%')->get() as $row) {
            $this->assertStringNotContainsString('123456', json_encode([$row->before, $row->after], JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString(self::SECRET, json_encode([$row->before, $row->after], JSON_THROW_ON_ERROR));
        }
    }

    /** A real Google callback: PKCE start, a signed token, the same exchange the browser's return trip makes. */
    private function googleSignIn(User $user): TestResponse
    {
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);
        OAuthIdentity::create([
            'user_id' => $user->getKey(), 'provider' => 'google', 'provider_user_id' => 'sub-'.$user->getKey(),
            'email' => $user->email, 'email_verified' => true, 'linked_at' => now(),
        ]);

        $url = (string) $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/google/start', [])->assertOk()->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $header = $encode(json_encode(['alg' => 'RS256', 'kid' => 'two-step-key'], JSON_THROW_ON_ERROR));
        $payload = $encode(json_encode([
            'iss' => 'https://accounts.google.com', 'aud' => 'test-id', 'sub' => 'sub-'.$user->getKey(), 'nonce' => $query['nonce'],
            'iat' => time(), 'exp' => time() + 300, 'email' => $user->email, 'email_verified' => true,
        ], JSON_THROW_ON_ERROR));
        openssl_sign($header.'.'.$payload, $signature, $key, OPENSSL_ALGO_SHA256);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['id_token' => $header.'.'.$payload.'.'.$encode($signature)]),
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [['kid' => 'two-step-key', 'kty' => 'RSA', 'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e'])]]]),
        ]);

        return $this->withHeaders($this->spa)->get('/api/v1/auth/oauth/google/callback?'.http_build_query(['code' => 'c', 'state' => $query['state']]));
    }
}
