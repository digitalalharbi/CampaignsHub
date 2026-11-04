<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Services\RegistrationVerificationSettings;
use App\Domains\Audit\AuditLogger;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Services\AuthCodeSwitches;
use App\Domains\Identity\Services\PasswordResetService;
use App\Domains\Legal\Models\PlatformSetting;
use App\Domains\Requests\Models\ContactVerification;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AUTH-CODE-SWITCHES-001 — the Super Admin's switches for one-time codes at sign-in and recovery, through the real endpoints.
 *
 * What these hold: every switch reads as today's behaviour until somebody changes it; OFF means no row, no message and no provider
 * call whichever door was knocked on; password, Google and the reset link stay independent of them; and a combination that would
 * leave somebody with no way in or no way back is refused rather than warned about.
 */
final class AuthCodeSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/auth-code-switches';

    /** @var array<string, string> Sanctum attaches a session only for the SPA's origin. */
    private array $spa = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        $this->assertingAcrossTenants();
    }

    private function admin(string $email = 'owner@platform.test'): User
    {
        $user = User::where('email', $email)->first()
            ?? User::create(['name' => 'Owner', 'email' => $email, 'password' => 'secret1234']);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $user->refresh();
    }

    /** @param array<string, bool> $changes */
    private function set(array $changes): void
    {
        $service = app(AuthCodeSwitches::class);
        $service->update($changes, (int) $service->current()['version'], 'Test decision', $this->admin(), app(AuditLogger::class));
    }

    /** @return array<string, mixed> */
    private function body(array $over = []): array
    {
        return array_merge([
            'sign_in_email_code' => true,
            'sign_in_sms_code' => true,
            'portal_email_code' => true,
            'portal_sms_code' => true,
            'recovery_sms_code' => true,
            'reason' => 'Cost control decision',
            'expected_version' => 0,
            'confirm_risk' => true,
        ], $over);
    }

    private function twilioOn(): void
    {
        config([
            'services.twilio.account_sid' => 'AC_test',
            'services.twilio.auth_token' => 'token_test',
            'services.twilio.verify_service_sid' => 'VA_test',
        ]);
        Http::fake(['verify.twilio.com/*' => Http::response(['status' => 'pending'], 201)]);
    }

    private function contact(string $email, ?string $phone = null): ExternalRequest
    {
        $tenant = Tenant::query()->first() ?? Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.Str::lower(Str::random(6))]);

        return ExternalRequest::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'reference' => 'REQ-2026-'.strtoupper(substr(md5($email.$phone.uniqid()), 0, 6)),
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => 'Client',
            'contact_email' => $email,
            'contact_phone' => $phone,
            'submitted_at' => now(),
        ]);
    }

    private function user(string $email, array $over = []): User
    {
        $user = User::create(['name' => 'Someone', 'email' => $email, 'password' => 'secret1234']);
        $user->forceFill(array_merge(['email_verified_at' => now()], $over))->save();

        return $user->refresh();
    }

    /** An address nobody proved, because signup did not ask — the state `emailUnprovenByPolicy()` names. */
    private function unproved(string $email, array $over = []): User
    {
        return $this->user($email, array_merge(['email_verified_at' => null, 'email_unproven_by_policy_at' => now()], $over));
    }

    private function setSignupEmailGate(bool $on): void
    {
        $settings = app(RegistrationVerificationSettings::class);
        $settings->update(['email_enabled' => $on], (int) $settings->current()['version'], 'Test decision', $this->admin(), app(AuditLogger::class));
    }

    // ── A deploy changes nothing ──────────────────────────────────────────────────────────────────

    public function test_with_no_decision_taken_every_door_behaves_as_it_does_today(): void
    {
        Mail::fake();
        $this->twilioOn();
        $this->assertNull(PlatformSetting::current()->auth_code_switches, 'The migration must write nothing.');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/email-code/start', ['email' => 'a@x.test'])->assertOk();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/start', ['phone' => '+966501234567'])->assertOk();
        $this->withHeaders($this->spa)->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'b@x.test'])->assertCreated();
        $this->withHeaders($this->spa)->postJson('/api/v1/client/login/start', ['channel' => 'sms', 'destination' => '+966501234568'])->assertCreated();
        $this->actingAs($this->user('c@x.test'), 'sanctum')->postJson('/api/v1/me/phone/start', ['phone' => '+966501234569'])->assertOk();

        $this->assertSame(5, ContactVerification::query()->count());
        $this->getJson('/api/v1/auth/methods')->assertOk()->assertJsonPath('data.email_code.available', true);
        $this->assertSame(0, AuditLog::query()->where('action', AuthCodeSwitches::AUDIT_ACTION)->count());
    }

    public function test_every_switch_reads_on_until_somebody_changes_it(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.switches.sign_in_email_code', true)
            ->assertJsonPath('data.switches.sign_in_sms_code', true)
            ->assertJsonPath('data.switches.portal_email_code', true)
            ->assertJsonPath('data.switches.portal_sms_code', true)
            ->assertJsonPath('data.switches.recovery_sms_code', true)
            ->assertJsonPath('data.switches.version', 0);
    }

    // ── The matrix: email ON/OFF × SMS ON/OFF × every operation ───────────────────────────────────

    /** @return array<string, array{bool, bool}> */
    public static function matrix(): array
    {
        return [
            'email on, sms on' => [true, true],
            'email on, sms off' => [true, false],
            'email off, sms on' => [false, true],
            'email off, sms off' => [false, false],
        ];
    }

    #[DataProvider('matrix')]
    public function test_sign_in_sends_only_on_the_channel_that_is_on(bool $email, bool $sms): void
    {
        Mail::fake();
        $this->twilioOn();
        $this->set(['sign_in_email_code' => $email, 'sign_in_sms_code' => $sms]);

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/email-code/start', ['email' => 'a@x.test'])->assertStatus($email ? 200 : 403);
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/start', ['phone' => '+966501234567'])->assertStatus($sms ? 200 : 403);

        $this->assertSame($email ? 1 : 0, ContactVerification::query()->where('purpose', 'email_sign_in')->count(), 'No row when off.');
        $this->assertSame($sms ? 1 : 0, ContactVerification::query()->where('purpose', 'phone_sign_in')->count(), 'No row when off.');

        $sent = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'verify.twilio.com'))->count();
        $this->assertSame($sms ? 1 : 0, $sent, 'The provider is not called when off, so nothing is charged.');

        if (! $email) {
            Mail::assertNothingOutgoing();
        }
    }

    #[DataProvider('matrix')]
    public function test_a_client_contact_is_gated_by_its_own_switches(bool $email, bool $sms): void
    {
        Mail::fake();
        $this->twilioOn();
        $this->set(['portal_email_code' => $email, 'portal_sms_code' => $sms]);

        $this->withHeaders($this->spa)->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'a@x.test'])->assertStatus($email ? 201 : 403);
        $this->withHeaders($this->spa)->postJson('/api/v1/client/login/start', ['channel' => 'sms', 'destination' => '+966501234567'])->assertStatus($sms ? 201 : 403);
        $this->withHeaders($this->spa)->postJson('/api/v1/client/login/start', ['channel' => 'whatsapp', 'destination' => '+966501234567'])->assertStatus($sms ? 201 : 403);

        $this->assertSame(($email ? 1 : 0) + ($sms ? 2 : 0), ContactVerification::query()->where('purpose', 'portal_login')->count());
    }

    #[DataProvider('matrix')]
    public function test_recovery_by_link_and_password_sign_in_never_depend_on_a_switch(bool $email, bool $sms): void
    {
        Mail::fake();
        $this->admin();
        $this->user('member@x.test');
        $this->set([
            'sign_in_email_code' => $email, 'sign_in_sms_code' => $sms,
            'portal_email_code' => $email, 'portal_sms_code' => $sms,
            'recovery_sms_code' => $sms,
        ]);

        foreach (['member@x.test', 'owner@platform.test'] as $address) {
            $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => $address, 'password' => 'secret1234'])->assertOk();
            $this->app['auth']->forgetGuards();

            $this->postJson('/api/v1/auth/forgot-password', ['email' => $address])->assertOk();
            $this->assertTrue(DB::table('password_reset_tokens')->where('email', $address)->exists(), 'The reset link is not a switch.');
        }
    }

    #[DataProvider('matrix')]
    public function test_the_sign_in_page_is_told_only_what_can_work(bool $email, bool $sms): void
    {
        $this->set(['sign_in_email_code' => $email, 'portal_email_code' => $email, 'sign_in_sms_code' => $sms]);

        $this->getJson('/api/v1/auth/methods')->assertOk()
            ->assertJsonPath('data.password.available', true)
            ->assertJsonPath('data.email_code.available', $email)
            ->assertJsonPath('data.sms_code.available', $sms);
    }

    public function test_a_contact_keeps_the_email_code_button_when_only_the_platform_code_is_off(): void
    {
        $this->set(['sign_in_email_code' => false]);

        $this->getJson('/api/v1/auth/methods')->assertOk()->assertJsonPath('data.email_code.available', true);
    }

    public function test_recovery_sms_off_stops_proving_a_number_and_keeps_numbers_already_proved(): void
    {
        Mail::fake();
        $this->twilioOn();
        $holder = $this->user('holder@x.test', ['phone' => '+966501234567', 'phone_verified_at' => now()]);
        $this->set(['recovery_sms_code' => false]);

        foreach (['sms', 'whatsapp'] as $channel) {
            $this->actingAs($holder, 'sanctum')->postJson('/api/v1/me/phone/start', ['phone' => '+966501234567', 'channel' => $channel])->assertForbidden();
        }
        $this->assertSame(0, ContactVerification::query()->count());
        $this->actingAs($holder, 'sanctum')->getJson('/api/v1/me/phone')->assertOk()
            ->assertJsonPath('data.confirmed', true)
            ->assertJsonPath('data.code_available', false);
    }

    // ── No door around the switch ─────────────────────────────────────────────────────────────────

    public function test_the_intake_endpoint_cannot_mint_a_portal_code_past_the_switch(): void
    {
        Mail::fake();
        $this->set(['portal_email_code' => false]);

        $this->withHeaders($this->spa)->postJson('/api/v1/requests/verify/start', [
            'channel' => 'email', 'destination' => 'a@x.test', 'purpose' => 'portal_login',
        ])->assertForbidden();

        $this->assertSame(0, ContactVerification::query()->count());
    }

    public function test_the_intake_contact_check_is_not_a_sign_in_and_is_not_switched(): void
    {
        $this->set(['portal_email_code' => false, 'sign_in_email_code' => false]);

        $this->withHeaders($this->spa)->postJson('/api/v1/requests/verify/start', ['channel' => 'email', 'destination' => 'a@x.test'])->assertCreated();
    }

    public function test_a_code_already_held_stops_working_the_moment_the_switch_goes_off(): void
    {
        Mail::fake();
        $this->user('member@x.test');

        $start = $this->withHeaders($this->spa)->postJson('/api/v1/auth/email-code/start', ['email' => 'member@x.test'])->assertOk();
        $this->set(['sign_in_email_code' => false]);

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/email-code/verify', [
            'verification_id' => $start->json('data.verification_id'), 'code' => $start->json('data.dev_code'),
        ])->assertForbidden();

        $this->assertGuest('web');
    }

    public function test_a_switch_off_answers_a_registered_address_and_a_stranger_identically(): void
    {
        Mail::fake();
        $this->user('member@x.test');
        $this->set(['sign_in_email_code' => false]);

        $known = $this->withHeaders($this->spa)->postJson('/api/v1/auth/email-code/start', ['email' => 'member@x.test']);
        $unknown = $this->withHeaders($this->spa)->postJson('/api/v1/auth/email-code/start', ['email' => 'nobody@x.test']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_the_off_answer_points_at_a_way_that_still_works_in_arabic_and_english(): void
    {
        $this->set(['sign_in_email_code' => false]);

        $ar = $this->withHeaders($this->spa + ['Accept-Language' => 'ar'])->postJson('/api/v1/auth/email-code/start', ['email' => 'a@x.test']);
        $en = $this->withHeaders($this->spa + ['Accept-Language' => 'en'])->postJson('/api/v1/auth/email-code/start', ['email' => 'a@x.test']);

        $this->assertStringContainsString('كلمة المرور', (string) $ar->json('message'));
        $this->assertStringContainsString('password', (string) $en->json('message'));
    }

    // ── Authority and record ──────────────────────────────────────────────────────────────────────

    public function test_only_a_super_admin_reaches_the_switches(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->putJson(self::URL, $this->body(['sign_in_email_code' => false]))->assertUnauthorized();

        $member = $this->user('member@x.test');
        $this->actingAs($member, 'sanctum')->getJson(self::URL)->assertForbidden();
        $this->actingAs($member, 'sanctum')->putJson(self::URL, $this->body(['sign_in_email_code' => false]))->assertForbidden();

        $this->assertTrue(app(AuthCodeSwitches::class)->current()['sign_in_email_code']);
        $this->assertNull(PlatformSetting::current()->auth_code_switches);
        $this->assertSame(0, AuditLog::query()->where('action', AuthCodeSwitches::AUDIT_ACTION)->count());
    }

    public function test_a_change_records_who_when_before_after_and_why(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->putJson(self::URL, $this->body(['sign_in_email_code' => false]))->assertOk()
            ->assertJsonPath('data.switches.sign_in_email_code', false)
            ->assertJsonPath('data.switches.version', 1)
            ->assertJsonCount(1, 'data.changes');

        $log = AuditLog::query()->where('action', AuthCodeSwitches::AUDIT_ACTION)->firstOrFail();
        $this->assertSame((int) $admin->getKey(), (int) $log->user_id);
        $this->assertSame('Cost control decision', $log->reason);
        $this->assertTrue($log->before['sign_in_email_code']);
        $this->assertFalse($log->after['sign_in_email_code']);
        $this->assertNotNull($log->created_at);
    }

    public function test_the_decision_persists_and_saving_the_same_values_records_nothing(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->putJson(self::URL, $this->body(['sign_in_sms_code' => false]))->assertOk();

        $this->assertFalse(PlatformSetting::query()->where('is_singleton', true)->firstOrFail()->auth_code_switches['sign_in_sms_code']);

        $this->actingAs($admin, 'sanctum')->putJson(self::URL, $this->body(['sign_in_sms_code' => false, 'expected_version' => 1]))->assertOk()
            ->assertJsonPath('data.switches.version', 1);
        $this->assertSame(1, AuditLog::query()->where('action', AuthCodeSwitches::AUDIT_ACTION)->count());
    }

    public function test_turning_one_off_needs_a_reason_and_a_confirmation(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->putJson(self::URL, $this->body(['sign_in_email_code' => false, 'confirm_risk' => false]))->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson(self::URL, array_diff_key($this->body(['sign_in_email_code' => false]), ['reason' => 1]))->assertStatus(422);

        $this->assertTrue(app(AuthCodeSwitches::class)->current()['sign_in_email_code']);
    }

    public function test_a_stale_decision_is_refused(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->putJson(self::URL, $this->body(['sign_in_email_code' => false]))->assertOk();

        $this->actingAs($admin, 'sanctum')->putJson(self::URL, $this->body(['expected_version' => 0]))->assertStatus(409);
        $this->assertFalse(app(AuthCodeSwitches::class)->current()['sign_in_email_code']);
    }

    public function test_the_reset_link_cannot_be_switched_through_the_endpoint(): void
    {
        Mail::fake();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->putJson(self::URL, $this->body(['recovery_email_link' => false, 'recovery_email_code' => false]))->assertOk()
            ->assertJsonPath('data.recovery_link.available', true)
            ->assertJsonPath('data.recovery_link.switchable', false)
            ->assertJsonPath('data.recovery_link.open_question', 'LOGIN-CLIENT-CODE-UNIFIED-001');

        $this->assertArrayNotHasKey('recovery_email_link', app(AuthCodeSwitches::class)->current());
    }

    // ── A combination that strands somebody is refused ────────────────────────────────────────────

    public function test_sms_off_is_refused_while_a_number_is_somebodys_only_way_back(): void
    {
        $this->unproved('stranded@x.test', ['phone' => '+966501234567', 'phone_verified_at' => now()]);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->putJson(self::URL, $this->body(['sign_in_sms_code' => false]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['sign_in_sms_code']]);

        $this->assertTrue(app(AuthCodeSwitches::class)->current()['sign_in_sms_code']);
        $this->assertSame(0, AuditLog::query()->where('action', AuthCodeSwitches::AUDIT_ACTION)->count());
    }

    public function test_sms_off_goes_through_once_that_account_has_proved_its_address(): void
    {
        $user = $this->unproved('stranded@x.test', ['phone' => '+966501234567', 'phone_verified_at' => now()]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->admin(), 'sanctum')->putJson(self::URL, $this->body(['sign_in_sms_code' => false]))->assertOk();
    }

    public function test_sms_off_is_refused_while_signup_is_not_proving_addresses(): void
    {
        $this->setSignupEmailGate(false);

        $this->actingAs($this->admin(), 'sanctum')->putJson(self::URL, $this->body(['sign_in_sms_code' => false]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['sign_in_sms_code']]);
    }

    public function test_the_signup_email_gate_cannot_go_off_while_sms_sign_in_is_off(): void
    {
        $this->set(['sign_in_sms_code' => false]);

        try {
            $this->setSignupEmailGate(false);
            $this->fail('The combination must be refused from this side too.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email_enabled', $e->errors());
        }

        $this->assertTrue(app(RegistrationVerificationSettings::class)->emailEnabled());
    }

    public function test_portal_email_off_is_refused_while_a_contact_has_no_other_way_in(): void
    {
        $this->contact('client@x.test');

        $this->actingAs($this->admin(), 'sanctum')->putJson(self::URL, $this->body(['portal_email_code' => false]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['portal_email_code']]);

        $this->assertTrue(app(AuthCodeSwitches::class)->current()['portal_email_code']);
    }

    public function test_both_portal_channels_off_is_refused_for_a_contact_holding_both(): void
    {
        $this->twilioOn();
        $this->contact('client@x.test', '+966501234567');

        $this->actingAs($this->admin(), 'sanctum')->putJson(self::URL, $this->body(['portal_email_code' => false, 'portal_sms_code' => false]))
            ->assertStatus(422);
    }

    public function test_portal_email_off_counts_the_phone_only_when_a_provider_can_reach_it(): void
    {
        $this->contact('client@x.test', '+966501234567');
        $admin = $this->admin();

        // No provider: SMS is on and reaches nobody, so it is not a way in.
        $this->actingAs($admin, 'sanctum')->putJson(self::URL, $this->body(['portal_email_code' => false]))->assertStatus(422);

        $this->twilioOn();
        $this->actingAs($admin, 'sanctum')->putJson(self::URL, $this->body(['portal_email_code' => false]))->assertOk();
    }

    public function test_portal_sms_off_is_refused_for_a_contact_reachable_only_by_phone(): void
    {
        $this->contact('', '+966501234567');

        $this->actingAs($this->admin(), 'sanctum')->putJson(self::URL, $this->body(['portal_sms_code' => false]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['portal_sms_code']]);
    }

    public function test_a_contact_who_holds_a_proved_account_is_not_stranded_by_the_code_going_off(): void
    {
        $this->contact('client@x.test');
        $this->user('client@x.test');

        $this->actingAs($this->admin(), 'sanctum')->putJson(self::URL, $this->body(['portal_email_code' => false]))->assertOk();
    }

    public function test_a_contact_whose_account_address_is_unproved_still_depends_on_the_code(): void
    {
        $this->contact('client@x.test');
        $this->unproved('client@x.test');

        $this->actingAs($this->admin(), 'sanctum')->putJson(self::URL, $this->body(['portal_email_code' => false]))->assertStatus(422);
    }

    public function test_with_the_portal_code_closed_at_the_door_the_switch_strands_nobody(): void
    {
        // What `PORTAL_CODE_ON_LOGIN=false` means once this lands beside it: these contacts recover by link, not by this code.
        config(['requests.verification.portal_code_on_login' => false]);
        $this->contact('client@x.test');

        $this->actingAs($this->admin(), 'sanctum')->putJson(self::URL, $this->body(['portal_email_code' => false]))->assertOk();
    }

    public function test_a_contact_who_arrives_after_the_switch_went_off_is_reported(): void
    {
        $admin = $this->admin();
        $this->set(['portal_email_code' => false]);
        $this->contact('late@x.test');

        $this->actingAs($admin, 'sanctum')->getJson(self::URL)->assertOk()->assertJsonPath('data.lockout.stranded_contacts', 1);
    }

    public function test_turning_a_switch_back_on_is_never_refused(): void
    {
        $this->set(['portal_email_code' => false]);
        $this->contact('late@x.test');

        $this->actingAs($this->admin(), 'sanctum')->putJson(self::URL, $this->body(['expected_version' => 1]))->assertOk()
            ->assertJsonPath('data.switches.portal_email_code', true)
            ->assertJsonPath('data.lockout.stranded_contacts', 0);
    }

    // ── The console tells the switch apart from the provider, and invents nothing ─────────────────

    public function test_an_enabled_channel_with_no_provider_is_reported_as_exactly_that(): void
    {
        config(['services.twilio.account_sid' => null, 'services.twilio.auth_token' => null, 'services.twilio.verify_service_sid' => null]);

        $this->actingAs($this->admin(), 'sanctum')->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.channels.sign_in_sms_code.enabled', true)
            ->assertJsonPath('data.channels.sign_in_sms_code.provider', 'not_configured');
    }

    public function test_cost_is_unknown_until_a_rate_is_entered_and_counts_come_from_the_ledger(): void
    {
        $this->twilioOn();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/start', ['phone' => '+966501234567'])->assertOk();

        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.channels.sign_in_sms_code.last_24h.issued', 1)
            ->assertJsonPath('data.channels.sign_in_sms_code.last_24h.sent', 1)
            ->assertJsonPath('data.channels.sign_in_sms_code.sent_30d', 1)
            ->assertJsonPath('data.channels.sign_in_sms_code.cost_known', false)
            ->assertJsonPath('data.channels.sign_in_sms_code.estimated_cost_30d', null);

        $settings = app(RegistrationVerificationSettings::class);
        $settings->update(['sms_unit_cost' => '0.05', 'cost_currency' => 'USD'], (int) $settings->current()['version'], 'Rate from invoice', $admin, app(AuditLogger::class));

        $this->actingAs($admin, 'sanctum')->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.channels.sign_in_sms_code.cost_known', true)
            ->assertJsonPath('data.channels.sign_in_sms_code.estimated_cost_30d', '0.0500')
            ->assertJsonPath('data.channels.sign_in_sms_code.currency', 'USD');
    }

    public function test_the_limits_shown_are_the_ones_in_force(): void
    {
        config(['requests.verification.code_ttl_minutes' => 7, 'requests.verification.max_attempts' => 4]);

        $this->actingAs($this->admin(), 'sanctum')->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.limits.code_ttl_minutes', 7)
            ->assertJsonPath('data.limits.max_attempts', 4)
            ->assertJsonPath('data.limits.resend_cooldown_seconds', 60);

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/start', ['phone' => '+966501234567'])->assertOk()
            ->assertJsonPath('data.resend_after', 60);
    }

    public function test_a_provider_that_fails_answers_at_once_and_opens_nothing(): void
    {
        config([
            'services.twilio.account_sid' => 'AC_test',
            'services.twilio.auth_token' => 'token_test',
            'services.twilio.verify_service_sid' => 'VA_test',
        ]);
        Http::fake(['verify.twilio.com/*' => Http::response(['code' => 20003], 401)]);
        $this->user('member@x.test');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/phone/start', ['phone' => '+966501234567'])->assertOk()
            ->assertJsonPath('data.delivery_status', 'failed');

        $this->assertGuest('web');
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => 'member@x.test', 'password' => 'secret1234'])->assertOk();
    }

    // ── The last resort has to work ───────────────────────────────────────────────────────────────

    public function test_an_administrators_link_recovers_an_unproved_account_without_mailing_that_address(): void
    {
        Mail::fake();
        $user = $this->unproved('stranded@x.test');

        $issued = app(PasswordResetService::class)->issueForOperator((string) $user->email);

        $this->assertNotNull($issued);
        $this->assertSame('withheld_unproven_address', $issued['delivery']);
        Mail::assertNothingOutgoing();

        parse_str((string) parse_url($issued['link'], PHP_URL_QUERY), $query);
        app(PasswordResetService::class)->reset((string) $user->email, (string) $query['token'], 'a-new-secret-1');

        Mail::assertNothingOutgoing();
        $this->assertTrue(password_verify('a-new-secret-1', (string) $user->refresh()->password));
        $this->assertNull($user->email_verified_at, 'An administrator helping is not proof of the address.');
        $this->assertTrue($user->emailUnprovenByPolicy());
    }

    public function test_an_ordinary_reset_token_stays_refused_on_an_unproved_address(): void
    {
        $user = $this->unproved('stranded@x.test');
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email, 'token' => hash('sha256', 'stale'), 'purpose' => 'password_reset',
            'created_at' => now(), 'expires_at' => now()->addHour(),
        ]);

        $this->expectException(ValidationException::class);
        app(PasswordResetService::class)->reset((string) $user->email, 'stale', 'a-new-secret-1');
    }
}
