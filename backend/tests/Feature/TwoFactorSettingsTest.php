<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Settings\Services\Totp;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TWO-STEP-001 — the three methods under «الأمان والجلسات»: authenticator app, email code, SMS; recovery codes; turning it off.
 */
final class TwoFactorSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function person(array $attributes = []): User
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        $user = User::factory()->create(array_merge([
            'email' => 'settings@t.test', 'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now(),
        ], $attributes));
        $this->grantMembership($user, $tenant);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function enableTotp(User $user): array
    {
        $setup = $this->postJson('/api/v1/me/two-factor/totp/setup')->assertOk();
        $secret = (string) $setup->json('data.secret');
        $this->assertStringStartsWith('otpauth://totp/', (string) $setup->json('data.otpauth_uri'));

        // Not active until a first code confirms it — and nothing is stored on the account yet.
        $this->assertNull($user->fresh()->two_factor_secret);
        $this->postJson('/api/v1/me/two-factor/totp/confirm', ['code' => '000000'])->assertStatus(422);

        $confirmed = $this->postJson('/api/v1/me/two-factor/totp/confirm', ['code' => (new Totp)->codeAt($secret, intdiv(time(), 30))])
            ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.default', 'totp');

        return ['secret' => $secret, 'codes' => (array) $confirmed->json('data.recovery_codes')];
    }

    public function test_authenticator_app_is_confirmed_by_a_first_code_and_gives_ten_recovery_codes_once(): void
    {
        $user = $this->person();
        ['secret' => $secret, 'codes' => $codes] = $this->enableTotp($user);

        $this->assertCount(10, $codes);
        $user->refresh();
        $this->assertTrue($user->two_factor_enabled);
        // Encrypted at rest; recovery codes hashed.
        $raw = (string) \DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertStringNotContainsString($codes[0], (string) \DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes'));

        // Never returned again.
        $this->getJson('/api/v1/me/two-factor')->assertOk()
            ->assertJsonMissingPath('data.recovery_codes')
            ->assertJsonMissingPath('data.secret')
            ->assertJsonPath('data.recovery_codes_left', 10)
            ->assertJsonPath('data.methods.totp.active', true);
        $this->getJson('/api/v1/auth/me')->assertJsonMissingPath('data.user.two_factor_secret');

        $this->assertTrue(AuditLog::where('action', 'security.two_factor.enabled')->where('user_id', $user->id)->exists());
    }

    public function test_turning_the_first_method_on_ends_every_other_session_and_token(): void
    {
        $user = $this->person();
        $user->createToken('old-cli');
        $this->enableTotp($user);

        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotNull($user->fresh()->sessions_valid_after);
    }

    public function test_setting_up_again_while_active_is_refused(): void
    {
        $user = $this->person();
        $this->enableTotp($user);

        $this->postJson('/api/v1/me/two-factor/totp/setup')->assertStatus(422);
        $this->assertTrue($user->fresh()->two_factor_enabled);
    }

    public function test_email_method_is_confirmed_by_a_code_to_the_verified_address(): void
    {
        $user = $this->person();

        $code = (string) $this->postJson('/api/v1/me/two-factor/email/start')->assertOk()->json('data.dev_code');
        $this->postJson('/api/v1/me/two-factor/email/confirm', ['code' => $code === '000000' ? '111111' : '000000'])->assertStatus(422);
        $this->postJson('/api/v1/me/two-factor/email/confirm', ['code' => $code])->assertOk()
            ->assertJsonPath('data.methods.email.active', true)
            ->assertJsonCount(10, 'data.recovery_codes');

        $this->assertNotNull($user->fresh()->two_factor_email_enabled_at);
    }

    public function test_an_unverified_address_cannot_be_a_method(): void
    {
        $this->person(['email_verified_at' => null]);

        $this->postJson('/api/v1/me/two-factor/email/start')->assertStatus(422);
    }

    public function test_sms_waits_for_a_provider_and_says_so(): void
    {
        config(['services.twilio.account_sid' => '', 'services.twilio.auth_token' => '', 'services.twilio.verify_service_sid' => '']);
        $this->person(['phone' => '+966501234567', 'phone_verified_at' => now()]);

        $this->getJson('/api/v1/me/two-factor')->assertOk()
            ->assertJsonPath('data.methods.sms.provider_ready', false)
            ->assertJsonPath('data.methods.sms.available', false)
            ->assertJsonPath('data.methods.sms.hint', '•••• 4567');
        $this->postJson('/api/v1/me/two-factor/sms/start')->assertStatus(422);
    }

    public function test_sms_through_the_existing_provider_with_a_fake_transport(): void
    {
        config([
            'services.twilio.account_sid' => 'AC_test', 'services.twilio.auth_token' => 'test-token',
            'services.twilio.verify_service_sid' => 'VA_test', 'services.twilio.verify_channel' => 'sms',
        ]);
        Http::fake(fn (HttpRequest $request) => str_ends_with($request->url(), '/Verifications')
            ? Http::response(['status' => 'pending'], 201)
            : Http::response(['status' => 'approved'], 200));

        $user = $this->person(['phone' => '+966501234567', 'phone_verified_at' => now()]);

        $this->postJson('/api/v1/me/two-factor/sms/start')->assertOk()->assertJsonPath('data.dev_code', null);
        $this->postJson('/api/v1/me/two-factor/sms/confirm', ['code' => '123456'])->assertOk()->assertJsonPath('data.methods.sms.active', true);

        $this->assertNotNull($user->fresh()->two_factor_sms_enabled_at);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/Verifications') && $r['To'] === '+966501234567');
    }

    public function test_an_unconfirmed_phone_cannot_be_a_method(): void
    {
        config(['services.twilio.account_sid' => 'AC_test', 'services.twilio.auth_token' => 't', 'services.twilio.verify_service_sid' => 'VA_test']);
        Http::fake();
        $this->person(['phone' => '+966501234567', 'phone_verified_at' => null]);

        $this->postJson('/api/v1/me/two-factor/sms/start')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_turning_it_off_needs_the_password_or_a_code(): void
    {
        $user = $this->person();
        ['codes' => $codes] = $this->enableTotp($user);

        $this->postJson('/api/v1/me/two-factor/disable', ['password' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/v1/me/two-factor/disable', [])->assertStatus(422);
        $this->assertTrue($user->fresh()->two_factor_enabled);

        $this->postJson('/api/v1/me/two-factor/disable', ['code' => $codes[0]])->assertOk()->assertJsonPath('data.enabled', false);

        $user->refresh();
        $this->assertFalse($user->two_factor_enabled);
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertTrue(AuditLog::where('action', 'security.two_factor.disabled')->where('user_id', $user->id)->exists());
    }

    public function test_default_method_switches_and_removing_the_last_method_turns_it_off(): void
    {
        $user = $this->person();
        $this->enableTotp($user);
        $code = (string) $this->postJson('/api/v1/me/two-factor/email/start')->json('data.dev_code');
        $this->postJson('/api/v1/me/two-factor/email/confirm', ['code' => $code])->assertOk()->assertJsonPath('data.recovery_codes', null);

        $this->postJson('/api/v1/me/two-factor/default', ['method' => 'email'])->assertOk()->assertJsonPath('data.default', 'email');
        $this->postJson('/api/v1/me/two-factor/default', ['method' => 'sms'])->assertStatus(422);

        $this->deleteJson('/api/v1/me/two-factor/methods/email', ['password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('data.default', 'totp')->assertJsonPath('data.enabled', true);
        $this->deleteJson('/api/v1/me/two-factor/methods/totp', ['password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('data.enabled', false);
        $this->assertTrue(AuditLog::where('action', 'security.two_factor.default_changed')->where('user_id', $user->id)->exists());
    }

    public function test_new_recovery_codes_replace_the_old_ones(): void
    {
        $user = $this->person();
        ['codes' => $old] = $this->enableTotp($user);

        $new = (array) $this->postJson('/api/v1/me/two-factor/recovery-codes', ['password' => self::PASSWORD])->assertOk()->json('data.recovery_codes');

        $this->assertCount(10, $new);
        $this->assertSame([], array_intersect($old, $new));
        $this->postJson('/api/v1/me/two-factor/disable', ['code' => $old[0]])->assertStatus(422);
    }
}
