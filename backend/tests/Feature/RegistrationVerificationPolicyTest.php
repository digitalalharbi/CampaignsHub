<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Models\RegistrationSendLog;
use App\Domains\Accounts\Models\RegistrationVerification;
use App\Domains\Accounts\Services\AdvanceRegistration;
use App\Domains\Accounts\Services\RegistrationVerificationSettings;
use App\Domains\Audit\AuditLogger;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Actions\OAuthOutcome;
use App\Domains\Identity\Actions\ResolveOAuthIdentity;
use App\Domains\Identity\Services\InvitationService;
use App\Domains\Identity\Services\PasswordResetService;
use App\Domains\Requests\Models\ClientPortalToken;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * SIGNUP-VERIFY-POLICY-001 — the two switches, through the real endpoints.
 *
 * What these hold: OFF means skipped and UNVERIFIED, never a fake timestamp; approval and payment are untouched by either
 * switch; an application keeps the policy it was opened under; and an address nobody proved opens nothing.
 */
final class RegistrationVerificationPolicyTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->assertingAcrossTenants();
    }

    private function admin(string $email = 'owner@platform.test'): User
    {
        $user = User::create(['name' => 'Owner', 'email' => $email, 'password' => 'secret1234']);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $user->refresh();
    }

    /** Set the live switches as an administrator would, through the service that endpoint uses. */
    private function switches(bool $email, bool $mobile, array $more = []): void
    {
        $settings = app(RegistrationVerificationSettings::class);

        $settings->update(
            ['email_enabled' => $email, 'mobile_enabled' => $mobile] + $more,
            (int) $settings->current()['version'],
            'Test decision',
            User::where('is_platform_admin', true)->first() ?? $this->admin(),
            app(AuditLogger::class),
        );
    }

    private function application(string $email): RegistrationRequest
    {
        return RegistrationRequest::query()->whereRaw('lower(email) = ?', [$email])->firstOrFail();
    }

    private function settle(RegistrationRequest $request): RegistrationRequest
    {
        $payment = new SubscriptionPayment;
        $payment->forceFill([
            'registration_request_id' => $request->getKey(),
            'purpose' => 'subscription',
            'plan_code' => $request->plan_code,
            'billing_interval' => 'monthly',
            'provider' => 'sandbox',
            'idempotency_key' => 'test:'.$request->getKey(),
            'amount' => '99.00',
            'currency' => 'SAR',
            'status' => 'paid',
            'paid_at' => now(),
        ])->save();

        return app(AdvanceRegistration::class)->paymentConfirmed($request);
    }

    private function sends(string $channel): int
    {
        return RegistrationVerification::query()->where('channel', $channel)->count()
            + RegistrationSendLog::query()->where('channel', $channel)->count();
    }

    // ── The four combinations, through the state machine ──────────────────────────────────────

    public function test_both_on_is_todays_path_and_nothing_is_skipped(): void
    {
        Mail::fake();

        $res = $this->apply(['email' => 'both-on@a.test'])->assertStatus(202)
            ->assertJsonPath('data.registration.state', 'email_verification_required')
            ->assertJsonPath('data.policy.requires_email', true)
            ->assertJsonPath('data.policy.requires_mobile', true);

        $this->assertSame(1, RegistrationVerification::query()->where('channel', 'email')->count());
        $this->assertSame(0, RegistrationVerification::query()->where('channel', 'mobile')->count());

        $this->postJson('/api/v1/auth/registration/verify-email', ['token' => $this->verificationTokenFrom($res)])
            ->assertOk()->assertJsonPath('data.registration.state', 'mobile_verification_required');

        $this->assertSame(1, RegistrationVerification::query()->where('channel', 'mobile')->count());
        $this->assertSame(0, Tenant::count());
    }

    public function test_email_only_never_sends_a_code_and_does_not_need_a_number(): void
    {
        $this->switches(true, false);

        $res = $this->apply(['email' => 'email-only@a.test', 'phone' => null])->assertStatus(202)
            ->assertJsonPath('data.registration.state', 'email_verification_required')
            ->assertJsonPath('data.policy.requires_mobile', false);

        $this->postJson('/api/v1/auth/registration/verify-email', ['token' => $this->verificationTokenFrom($res)])
            ->assertOk()->assertJsonPath('data.registration.state', 'approved_awaiting_payment')
            ->assertJsonPath('data.registration.verification.mobile', 'not_required_by_policy');

        $this->assertSame(0, $this->sends('mobile'));
        $this->assertNotNull($this->application('email-only@a.test')->email_verified_at);
    }

    public function test_sms_only_sends_no_email_and_records_the_address_as_unproved(): void
    {
        $this->switches(false, true);

        $this->apply(['email' => 'sms-only@a.test'])->assertStatus(202)
            ->assertJsonPath('data.registration.state', 'mobile_verification_required')
            ->assertJsonPath('data.verification.delivery_status', 'not_required_by_policy')
            ->assertJsonPath('data.registration.email_verified', false)
            ->assertJsonPath('data.registration.verification.email', 'not_required_by_policy');

        $this->assertSame(0, $this->sends('email'));

        $code = RegistrationVerification::query()->where('channel', 'mobile')->count();
        $this->assertSame(1, $code);

        $application = $this->application('sms-only@a.test');
        $this->assertNull($application->email_verified_at);
        $this->assertNull($application->mobile_verified_at);
        $this->assertSame(0, Tenant::count());
    }

    public function test_both_off_moves_straight_to_the_payment_it_still_owes_and_proves_nothing(): void
    {
        $this->switches(false, false);

        $this->apply(['email' => 'both-off@a.test', 'phone' => null])->assertStatus(202)
            ->assertJsonPath('data.registration.state', 'approved_awaiting_payment')
            ->assertJsonPath('data.registration.provisioned', false)
            ->assertJsonPath('data.user', null);

        $this->assertSame(0, $this->sends('email'));
        $this->assertSame(0, $this->sends('mobile'));
        $this->assertSame(0, Tenant::count());
        $this->assertSame(0, User::where('email', 'both-off@a.test')->count());

        $application = $this->application('both-off@a.test');
        $this->assertNull($application->email_verified_at);
        $this->assertNull($application->mobile_verified_at);
    }

    // ── OFF is not verified ───────────────────────────────────────────────────────────────────

    public function test_a_workspace_opened_with_both_off_carries_no_fake_verification(): void
    {
        $this->switches(false, false);
        $this->apply(['email' => 'unproved@a.test'])->assertStatus(202);

        $application = $this->settle($this->application('unproved@a.test'));
        $this->assertSame(AccountState::Active, $application->state);

        $user = User::where('email', 'unproved@a.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->phone_verified_at, 'a number that was typed and never challenged is not proved');
        $this->assertTrue($user->emailUnprovenByPolicy());

        $trace = AuditLog::query()->where('action', 'registration.verification_not_required_by_policy')->firstOrFail();
        $this->assertSame('verification_not_required_by_policy', $trace->reason);
        $this->assertSame(['email', 'mobile'], $trace->after['channels']);
    }

    public function test_a_gate_that_really_ran_is_recorded_with_its_real_time(): void
    {
        $this->switches(false, true);
        $this->apply(['email' => 'mobile-proved@a.test'])->assertStatus(202);

        $application = $this->application('mobile-proved@a.test');
        $proved = now()->subHour()->startOfSecond();
        $application->forceFill(['mobile_verified_at' => $proved])->save();

        $this->settle(app(AdvanceRegistration::class)->reconcile($application));

        $user = User::where('email', 'mobile-proved@a.test')->firstOrFail();
        $this->assertTrue($proved->equalTo($user->phone_verified_at));
        $this->assertNull($user->email_verified_at);
    }

    public function test_a_reviewers_excused_mobile_gate_does_not_make_the_number_proved(): void
    {
        config(['accounts.registration.default' => ['requires_mobile' => false, 'requires_approval' => false, 'requires_payment' => false]]);

        $res = $this->apply(['email' => 'excused@a.test'])->assertStatus(202);
        $this->postJson('/api/v1/auth/registration/verify-email', ['token' => $this->verificationTokenFrom($res)])->assertOk();

        $user = User::where('email', 'excused@a.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->phone);
        $this->assertNull($user->phone_verified_at);
    }

    public function test_with_every_gate_off_a_workspace_appears_but_no_session_is_opened(): void
    {
        config(['accounts.registration.default' => ['requires_mobile' => true, 'requires_approval' => false, 'requires_payment' => false]]);
        $this->switches(false, false);

        $this->apply(['email' => 'open@a.test', 'phone' => null])->assertStatus(202)
            ->assertJsonPath('data.registration.provisioned', true)
            ->assertJsonPath('data.registration.state', 'active');

        $this->assertSame(1, Membership::count());
        $this->assertGuest('web');
    }

    // ── Approval and payment do not move ──────────────────────────────────────────────────────

    public function test_turning_both_off_does_not_open_the_approval_gate(): void
    {
        config(['accounts.registration.default' => ['requires_mobile' => true, 'requires_approval' => true, 'requires_payment' => true]]);
        $this->switches(false, false);

        $this->apply(['email' => 'review@a.test', 'phone' => null])->assertStatus(202)
            ->assertJsonPath('data.registration.state', 'pending_approval')
            ->assertJsonPath('data.policy.requires_approval', true)
            ->assertJsonPath('data.policy.requires_payment', true);

        $this->assertSame(0, Tenant::count());
    }

    public function test_paid_registration_still_needs_a_settled_charge_with_both_off(): void
    {
        $this->switches(false, false);
        $this->apply(['email' => 'still-owes@a.test'])->assertStatus(202);

        // Every applicant-reachable endpoint, tried in turn: none of them is a payment.
        $application = $this->application('still-owes@a.test');
        $this->postJson("/api/v1/auth/registration/{$application->getKey()}/resend", ['channel' => 'email'])->assertOk()
            ->assertJsonPath('data.verification.delivery_status', 'not_required_by_policy');
        app(AdvanceRegistration::class)->reconcile($application);

        $this->assertSame(AccountState::ApprovedAwaitingPayment, $application->refresh()->state);
        $this->assertSame(0, Tenant::count());
        $this->assertSame(0, SubscriptionPayment::query()->where('status', 'paid')->count());
        $this->assertSame(0, DB::table('registration_requests')->whereNotNull('email_verified_at')->count());
    }

    // ── Changing the policy while somebody is in the queue ────────────────────────────────────

    public function test_turning_a_gate_off_does_not_move_an_application_already_at_it(): void
    {
        $res = $this->apply(['email' => 'in-flight@a.test'])->assertStatus(202);
        $this->switches(false, false);

        $application = $this->application('in-flight@a.test');
        $this->assertSame(AccountState::EmailVerificationRequired, $application->state);

        // Anything that would ask «what now?» still says the same, and nothing became a workspace.
        app(AdvanceRegistration::class)->reconcile($application);
        $this->assertSame(AccountState::EmailVerificationRequired, $application->refresh()->state);
        $this->assertSame(0, Tenant::count());

        // The challenge they were sent still works, so nobody is stranded by the change.
        $this->postJson('/api/v1/auth/registration/verify-email', ['token' => $this->verificationTokenFrom($res)])
            ->assertOk()->assertJsonPath('data.registration.state', 'mobile_verification_required');
    }

    public function test_turning_a_gate_on_does_not_catch_an_application_opened_without_it(): void
    {
        $this->switches(false, false);
        $this->apply(['email' => 'opened-off@a.test'])->assertStatus(202);

        $this->switches(true, true);

        $application = $this->application('opened-off@a.test');
        app(AdvanceRegistration::class)->reconcile($application);

        $this->assertSame(AccountState::ApprovedAwaitingPayment, $application->refresh()->state);
    }

    public function test_an_application_from_before_the_switches_keeps_its_gates(): void
    {
        $this->apply(['email' => 'legacy@a.test'])->assertStatus(202);
        $application = $this->application('legacy@a.test');
        $application->forceFill(['effective_policy' => null])->save();

        $this->switches(false, false);

        app(AdvanceRegistration::class)->reconcile($application);
        $this->assertSame(AccountState::EmailVerificationRequired, $application->refresh()->state);
        $this->getJson("/api/v1/auth/registration/{$application->getKey()}")->assertOk()
            ->assertJsonPath('data.policy.requires_email', true);
    }

    public function test_an_administrator_can_move_one_application_explicitly_and_it_is_audited(): void
    {
        $admin = $this->admin();
        $this->apply(['email' => 'moved@a.test'])->assertStatus(202);
        $this->apply(['email' => 'not-moved@a.test'])->assertStatus(202);
        $this->switches(false, false);

        $application = $this->application('moved@a.test');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$application->getKey()}/reevaluate-verification", [])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$application->getKey()}/reevaluate-verification", ['reason' => 'Mail provider outage'])
            ->assertOk()->assertJsonPath('data.registration.state', 'approved_awaiting_payment')
            ->assertJsonPath('data.registration.email_verified', false);

        $this->assertSame(AccountState::EmailVerificationRequired, $this->application('not-moved@a.test')->state);

        $log = AuditLog::query()->where('action', 'registration.verification_reevaluated')->firstOrFail();
        $this->assertSame((int) $admin->getKey(), (int) $log->user_id);
        $this->assertSame('Mail provider outage', $log->reason);
        $this->assertTrue($log->before['effective_policy']['switches']['email']);
        $this->assertFalse($log->after['effective_policy']['switches']['email']);
        $this->assertSame(0, Tenant::count());
    }

    public function test_tightening_one_application_keeps_what_it_proved_and_its_approval(): void
    {
        config(['accounts.registration.default' => ['requires_mobile' => true, 'requires_approval' => true, 'requires_payment' => true]]);
        $admin = $this->admin();
        $this->switches(false, false);
        $this->apply(['email' => 'tightened@a.test'])->assertStatus(202);

        $application = $this->application('tightened@a.test');
        $application = app(AdvanceRegistration::class)->approved($application, (int) $admin->getKey());
        $this->assertSame(AccountState::ApprovedAwaitingPayment, $application->state);

        $this->switches(true, false);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$application->getKey()}/reevaluate-verification", ['reason' => 'Asked to prove it after all'])
            ->assertOk()->assertJsonPath('data.registration.state', 'email_verification_required');

        // Moving back to the gate sent the challenge; a resend is how a test can read one.
        $this->assertSame(1, RegistrationVerification::query()->where('channel', 'email')->count());
        $token = $this->verificationTokenFrom(
            $this->postJson("/api/v1/auth/registration/{$application->getKey()}/resend", ['channel' => 'email'])->assertOk()
        );
        $this->postJson('/api/v1/auth/registration/verify-email', ['token' => $token])
            ->assertOk()->assertJsonPath('data.registration.state', 'approved_awaiting_payment');
    }

    public function test_a_reviewer_cannot_create_a_mobile_gate_with_no_number_to_send_to(): void
    {
        $admin = $this->admin();
        $this->switches(true, false);
        $this->apply(['email' => 'no-number@a.test', 'phone' => null])->assertStatus(202);
        $application = $this->application('no-number@a.test');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/registrations/{$application->getKey()}", ['requires_mobile' => true, 'reason' => 'Asking for it'])
            ->assertStatus(422);

        $this->switches(true, true);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$application->getKey()}/reevaluate-verification", ['reason' => 'Asking for it'])
            ->assertStatus(422);

        $this->assertSame(AccountState::EmailVerificationRequired, $application->refresh()->state);
    }

    // ── Who may change it ─────────────────────────────────────────────────────────────────────

    private function validPolicy(array $overrides = []): array
    {
        return array_merge([
            'email_enabled' => false,
            'mobile_enabled' => false,
            'email_daily_cap' => null,
            'sms_daily_cap' => null,
            'per_application_daily_cap' => null,
            'email_unit_cost' => null,
            'sms_unit_cost' => null,
            'cost_currency' => null,
            'budget_alert_percent' => 80,
            'unverified_signups_daily_cap' => 200,
            'reason' => 'Cost control decision',
            'expected_version' => 0,
            'confirm_risk' => true,
        ], $overrides);
    }

    public function test_only_a_platform_administrator_reaches_the_policy(): void
    {
        $url = '/api/v1/admin/registration-verification-policy';

        $this->getJson($url)->assertUnauthorized();
        $this->putJson($url, $this->validPolicy())->assertUnauthorized();

        // A workspace owner holds every permission in their own tenant, and none of the platform's.
        $this->apply(['email' => 'tenant-owner@a.test'])->assertStatus(202);
        $tenantAdmin = User::create(['name' => 'Tenant Admin', 'email' => 'tenant-admin@a.test', 'password' => 'secret1234']);
        $tenantAdmin->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($tenantAdmin, 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs($tenantAdmin, 'sanctum')->putJson($url, $this->validPolicy())->assertForbidden();

        $this->assertTrue(app(RegistrationVerificationSettings::class)->emailEnabled());
        $this->assertSame(0, AuditLog::query()->where('action', RegistrationVerificationSettings::AUDIT_ACTION)->count());
    }

    public function test_a_change_records_who_when_old_new_and_why(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/registration-verification-policy', $this->validPolicy())
            ->assertOk()
            ->assertJsonPath('data.policy.email_enabled', false)
            ->assertJsonPath('data.policy.version', 1)
            ->assertJsonPath('data.channels.email.gate', 'disabled')
            ->assertJsonCount(1, 'data.changes');

        $log = AuditLog::query()->where('action', RegistrationVerificationSettings::AUDIT_ACTION)->firstOrFail();
        $this->assertSame((int) $admin->getKey(), (int) $log->user_id);
        $this->assertSame('Cost control decision', $log->reason);
        $this->assertTrue($log->before['email_enabled']);
        $this->assertFalse($log->after['email_enabled']);
        $this->assertNotNull($log->created_at);
    }

    public function test_disabling_needs_a_reason_and_a_confirmation(): void
    {
        $admin = $this->admin();
        $url = '/api/v1/admin/registration-verification-policy';

        $this->actingAs($admin, 'sanctum')->putJson($url, $this->validPolicy(['confirm_risk' => false]))->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson($url, array_diff_key($this->validPolicy(), ['reason' => 1]))->assertStatus(422);

        $this->assertTrue(app(RegistrationVerificationSettings::class)->emailEnabled());
    }

    public function test_a_stale_decision_is_refused_rather_than_overwriting_a_newer_one(): void
    {
        $admin = $this->admin();
        $url = '/api/v1/admin/registration-verification-policy';

        $this->actingAs($admin, 'sanctum')->putJson($url, $this->validPolicy())->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->putJson($url, $this->validPolicy(['email_enabled' => true, 'mobile_enabled' => true, 'expected_version' => 0]))
            ->assertStatus(409);

        $this->assertFalse(app(RegistrationVerificationSettings::class)->emailEnabled());
    }

    public function test_saving_the_same_policy_twice_is_one_decision(): void
    {
        $admin = $this->admin();
        $url = '/api/v1/admin/registration-verification-policy';

        $this->actingAs($admin, 'sanctum')->putJson($url, $this->validPolicy())->assertOk();
        $this->actingAs($admin, 'sanctum')->putJson($url, $this->validPolicy(['expected_version' => 1]))
            ->assertOk()->assertJsonPath('data.policy.version', 1);

        $this->assertSame(1, AuditLog::query()->where('action', RegistrationVerificationSettings::AUDIT_ACTION)->count());
    }

    public function test_no_policy_payload_can_set_anything_but_the_policy(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/registration-verification-policy', $this->validPolicy(['is_platform_admin' => true, 'requires_payment' => false]))
            ->assertOk();

        $stored = app(RegistrationVerificationSettings::class)->current();
        $this->assertArrayNotHasKey('requires_payment', $stored);
        $this->assertArrayNotHasKey('is_platform_admin', $stored);
    }

    public function test_a_fresh_install_is_both_on_with_no_invented_price(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/registration-verification-policy')->assertOk()
            ->assertJsonPath('data.policy.email_enabled', true)
            ->assertJsonPath('data.policy.mobile_enabled', true)
            ->assertJsonPath('data.channels.email.cost_known', false)
            ->assertJsonPath('data.channels.mobile.cost_known', false)
            ->assertJsonPath('data.channels.mobile.estimated_cost_30d', null)
            ->assertJsonPath('data.channels.mobile.cap', null)
            ->assertJsonPath('data.channels.mobile.alert', 'no_cap');

        $this->assertNull($res->json('data.policy.sms_unit_cost'));

        $this->getJson('/api/v1/auth/registration-policy')->assertOk()
            ->assertJsonPath('data.requires_email', true)
            ->assertJsonPath('data.requires_mobile', true);
    }

    // ── Limits, providers and cost ────────────────────────────────────────────────────────────

    public function test_the_sms_cap_refuses_before_the_provider_is_called_and_says_so(): void
    {
        config([
            'services.twilio.account_sid' => 'AC_test',
            'services.twilio.auth_token' => 'test-token',
            'services.twilio.verify_service_sid' => 'VA_test',
        ]);
        Http::fake(fn () => Http::response(['status' => 'pending'], 201));

        $this->switches(false, true, ['sms_daily_cap' => 1]);

        $this->apply(['email' => 'first@a.test'])->assertStatus(202)
            ->assertJsonPath('data.registration.mobile_delivery_status', 'sent');
        Http::assertSentCount(1);

        $this->apply(['email' => 'second@a.test'])->assertStatus(202)
            ->assertJsonPath('data.registration.state', 'mobile_verification_required')
            ->assertJsonPath('data.registration.mobile_delivery_status', 'blocked_by_send_limit');
        Http::assertSentCount(1);

        $this->assertSame(1, RegistrationVerification::query()->where('channel', 'mobile')->count());
    }

    public function test_a_refused_resend_leaves_the_code_already_held(): void
    {
        config(['accounts.mobile_resend_cooldown_seconds' => 0]);
        $this->switches(false, true, ['per_application_daily_cap' => 1]);

        $this->apply(['email' => 'held@a.test'])->assertStatus(202);
        $application = $this->application('held@a.test');
        $held = RegistrationVerification::query()->where('channel', 'mobile')->firstOrFail();

        $this->postJson("/api/v1/auth/registration/{$application->getKey()}/resend", ['channel' => 'mobile'])
            ->assertOk()->assertJsonPath('data.verification.delivery_status', 'blocked_by_send_limit')
            ->assertJsonPath('data.verification.dev_code', null);

        $this->assertTrue(RegistrationVerification::query()->whereKey($held->getKey())->exists());
    }

    public function test_the_email_cap_is_checked_before_a_message_is_composed(): void
    {
        Mail::fake();
        $this->switches(true, true, ['email_daily_cap' => 0]);

        $this->apply(['email' => 'over@a.test'])->assertStatus(202)
            ->assertJsonPath('data.verification.delivery_status', 'blocked_by_send_limit')
            ->assertJsonPath('data.verification.dev_link', null);

        $this->assertSame(0, RegistrationVerification::query()->where('channel', 'email')->count());
        $this->assertSame(RegistrationSendLog::BLOCKED_GLOBAL, RegistrationSendLog::query()->value('outcome'));
        Mail::assertNothingOutgoing();
    }

    public function test_a_missing_provider_is_told_apart_from_a_disabled_gate(): void
    {
        $admin = $this->admin();
        config(['services.twilio.account_sid' => null]);

        // Gate on, no provider: the attempt is honest and nothing claims a delivery.
        $this->switches(false, true);
        $this->apply(['email' => 'no-provider@a.test'])->assertStatus(202)
            ->assertJsonPath('data.registration.mobile_delivery_status', 'awaiting_provider_credentials');

        $overview = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/registration-verification-policy')->assertOk();
        $overview->assertJsonPath('data.channels.mobile.gate', 'enabled')
            ->assertJsonPath('data.channels.mobile.provider', 'not_configured')
            ->assertJsonPath('data.channels.mobile.last_24h.delivered', 0)
            ->assertJsonPath('data.channels.mobile.last_24h.attempted', 1);

        // Gate off, no provider: still «not configured», and separately «disabled».
        $this->switches(false, false);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/registration-verification-policy')->assertOk()
            ->assertJsonPath('data.channels.mobile.gate', 'disabled')
            ->assertJsonPath('data.channels.mobile.provider', 'not_configured');
    }

    public function test_cost_is_delivered_times_an_entered_rate_and_otherwise_unknown(): void
    {
        $admin = $this->admin();
        config([
            'services.twilio.account_sid' => 'AC_test',
            'services.twilio.auth_token' => 'test-token',
            'services.twilio.verify_service_sid' => 'VA_test',
        ]);
        Http::fake(fn (HttpRequest $r) => Http::response(['status' => 'pending'], 201));

        $this->switches(false, true);
        $this->apply(['email' => 'priced@a.test'])->assertStatus(202);

        $url = '/api/v1/admin/registration-verification-policy';
        $this->actingAs($admin, 'sanctum')->getJson($url)
            ->assertJsonPath('data.channels.mobile.last_24h.delivered', 1)
            ->assertJsonPath('data.channels.mobile.estimated_cost_30d', null)
            ->assertJsonPath('data.channels.mobile.cost_known', false);

        $this->switches(false, true, ['sms_unit_cost' => '0.25', 'cost_currency' => 'USD']);
        $this->actingAs($admin, 'sanctum')->getJson($url)
            ->assertJsonPath('data.channels.mobile.estimated_cost_30d', '0.2500')
            ->assertJsonPath('data.channels.mobile.currency', 'USD')
            ->assertJsonPath('data.channels.mobile.cost_known', true);
    }

    public function test_a_rate_without_a_currency_is_refused(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/registration-verification-policy', $this->validPolicy(['sms_unit_cost' => 0.2]))
            ->assertStatus(422)->assertJsonValidationErrors('cost_currency');
    }

    public function test_the_alert_says_near_and_at_the_cap(): void
    {
        $admin = $this->admin();
        $this->switches(false, true, ['sms_daily_cap' => 2, 'budget_alert_percent' => 50]);
        $url = '/api/v1/admin/registration-verification-policy';

        $this->apply(['email' => 'one@a.test'])->assertStatus(202);
        $this->actingAs($admin, 'sanctum')->getJson($url)->assertJsonPath('data.channels.mobile.alert', 'near_cap');

        $this->apply(['email' => 'two@a.test'])->assertStatus(202);
        $this->actingAs($admin, 'sanctum')->getJson($url)->assertJsonPath('data.channels.mobile.alert', 'at_cap');
    }

    public function test_with_the_email_gate_off_the_daily_ceiling_refuses_more_applications(): void
    {
        $this->switches(false, false, ['unverified_signups_daily_cap' => 1]);

        $this->apply(['email' => 'first-in@a.test'])->assertStatus(202);
        $this->apply(['email' => 'second-in@a.test'])->assertStatus(429);

        $this->assertSame(1, RegistrationRequest::count());
    }

    public function test_the_ceiling_never_applies_while_the_email_gate_is_on(): void
    {
        $this->switches(true, true, ['unverified_signups_daily_cap' => 0]);

        $this->apply(['email' => 'gate-on@a.test'])->assertStatus(202);
    }

    public function test_the_hidden_field_turns_a_bot_away_only_while_the_gate_is_off(): void
    {
        $this->apply(['email' => 'bot-on@a.test', 'website' => 'http://spam.test'])->assertStatus(202);

        $this->switches(false, false);
        $this->apply(['email' => 'bot-off@a.test', 'website' => 'http://spam.test'])
            ->assertStatus(422)->assertJsonValidationErrors('website');
    }

    public function test_the_address_gets_an_hourly_ceiling_only_while_the_gate_is_off(): void
    {
        config(['accounts.unverified_registration_per_hour_local' => 1]);

        $this->apply(['email' => 'limit-a@a.test'])->assertStatus(202);
        $this->apply(['email' => 'limit-b@a.test'])->assertStatus(202);

        $this->switches(false, false);
        $this->apply(['email' => 'limit-c@a.test'])->assertStatus(202);
        $this->apply(['email' => 'limit-d@a.test'])->assertStatus(429);
    }

    // ── An unproved address opens nothing ─────────────────────────────────────────────────────

    private function unprovedUser(string $email = 'typed@a.test'): User
    {
        $this->switches(false, false);
        $this->apply(['email' => $email])->assertStatus(202);
        $this->settle($this->application($email));

        return User::where('email', $email)->firstOrFail();
    }

    public function test_an_unproved_address_cannot_recover_the_account(): void
    {
        Mail::fake();
        $user = $this->unprovedUser();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        Mail::assertNothingOutgoing();

        $this->assertSame('ignored', app(PasswordResetService::class)->request((string) $user->email));
    }

    public function test_recovery_opens_once_the_address_is_really_proved(): void
    {
        $user = $this->unprovedUser();
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->assertNotSame('ignored', app(PasswordResetService::class)->request((string) $user->email));
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
    }

    public function test_a_stale_reset_link_cannot_be_used_on_an_unproved_address(): void
    {
        $user = $this->unprovedUser();
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email, 'token' => hash('sha256', 'stale'), 'created_at' => now(), 'expires_at' => now()->addHour(),
        ]);

        $this->expectException(ValidationException::class);
        app(PasswordResetService::class)->reset((string) $user->email, 'stale', 'newsecret123');
    }

    public function test_an_unproved_number_cannot_sign_in_by_code(): void
    {
        $user = $this->unprovedUser();
        $this->assertNull($user->phone_verified_at);

        $start = $this->withHeaders($this->spaOrigin)->postJson('/api/v1/auth/phone/start', ['phone' => $user->phone])->assertOk();
        $code = $start->json('data.dev_code');

        if ($code !== null) {
            $this->withHeaders($this->spaOrigin)->postJson('/api/v1/auth/phone/verify', [
                'verification_id' => $start->json('data.verification_id'), 'code' => $code,
            ])->assertStatus(422);
        }

        $this->assertGuest('web');
    }

    public function test_an_unproved_address_cannot_sign_in_by_email_code(): void
    {
        $user = $this->unprovedUser();
        $tenant = Tenant::query()->firstOrFail();
        // A client-portal membership is what a token for this address would resolve to, so it is the case that must hold.
        Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'portal' => Portal::ClientPortal->value, 'status' => 'active']);

        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => $user->email])->assertCreated();

        $wrong = $this->postJson('/api/v1/client/login/verify', ['verification_id' => $start->json('data.verification_id'), 'code' => $start->json('data.dev_code')])
            ->assertStatus(422);

        $this->assertSame(__('auth.failed'), $wrong->json('errors.code.0'), 'The same answer a spent code gets, so this is no oracle.');
        $this->assertGuest('web');
        $this->assertSame(0, ClientPortalToken::query()->withoutGlobalScopes()->count(), 'A token for this address would reach the membership.');
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.portal_code.unproven_address')->count());
    }

    public function test_a_contact_setup_link_cannot_open_an_account_whose_address_is_unproved(): void
    {
        $user = $this->unprovedUser();
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email, 'token' => hash('sha256', 'setup'), 'purpose' => 'contact_setup',
            'created_at' => now(), 'expires_at' => now()->addHour(),
        ]);

        $this->expectException(ValidationException::class);
        app(PasswordResetService::class)->reset((string) $user->email, 'setup', 'newsecret123');
    }

    public function test_an_unproved_address_cannot_accept_an_invitation_meant_for_it(): void
    {
        $user = $this->unprovedUser();
        $tenant = Tenant::query()->firstOrFail();
        DB::table('workspace_invitations')->insert([
            'id' => (string) Str::ulid(),
            'role_slug' => 'viewer',
            'tenant_id' => $tenant->getKey(),
            'email' => $user->email,
            'token_hash' => hash('sha256', 'invite-token'),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('verified email address');
        app(InvitationService::class)->acceptExisting('invite-token', $user);
    }

    public function test_a_provider_account_with_the_same_address_is_not_linked_to_it(): void
    {
        $user = $this->unprovedUser();

        $this->expectException(OAuthOutcome::class);
        $this->expectExceptionMessage('already uses this email');

        app(ResolveOAuthIdentity::class)->execute('google', [
            'sub' => 'google-1', 'email' => (string) $user->email, 'email_verified' => true, 'name' => 'Holder', 'avatar' => null,
        ]);
    }

    public function test_the_account_can_still_use_its_password_and_finish_onboarding(): void
    {
        $user = $this->unprovedUser();

        $this->withHeaders($this->spaOrigin)->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret1234'])
            ->assertOk()->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.user.email_verification_not_required', true);

        $this->assertNotSame(403, $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/workspace', ['name' => 'Named'])->status());
    }

    public function test_an_account_with_neither_timestamp_keeps_todays_treatment(): void
    {
        $legacy = User::create(['name' => 'Legacy', 'email' => 'legacy-user@a.test', 'password' => 'secret1234']);

        $this->assertFalse($legacy->emailUnprovenByPolicy());
        $this->assertNotSame('ignored', app(PasswordResetService::class)->request((string) $legacy->email));
    }

    // ── A second form cannot replace a credential ─────────────────────────────────────────────

    public function test_with_the_gate_off_a_second_form_cannot_replace_the_password_or_number(): void
    {
        $this->switches(false, true);
        $this->apply(['email' => 'victim@a.test'])->assertStatus(202);
        $application = $this->application('victim@a.test');
        $password = $application->password;
        $phone = $application->phone;

        $this->apply([
            'email' => 'victim@a.test', 'password' => 'attacker123', 'password_confirmation' => 'attacker123', 'tenant_name' => 'Attacker',
        ])->assertStatus(202);

        $application->refresh();
        $this->assertSame($password, $application->password);
        $this->assertSame($phone, $application->phone);
        $this->assertSame('New Workspace', $application->tenant_name);
    }

    public function test_after_the_address_is_proved_a_second_form_cannot_replace_the_password(): void
    {
        $res = $this->apply(['email' => 'proved@a.test'])->assertStatus(202);
        $this->postJson('/api/v1/auth/registration/verify-email', ['token' => $this->verificationTokenFrom($res)])->assertOk();

        $application = $this->application('proved@a.test');
        $password = $application->password;

        $this->apply(['email' => 'proved@a.test', 'password' => 'attacker123', 'password_confirmation' => 'attacker123'])->assertStatus(202);

        $this->assertSame($password, $application->refresh()->password);
    }

    public function test_before_anything_is_proved_a_second_form_still_corrects_the_details(): void
    {
        $this->apply(['email' => 'typo@a.test', 'tenant_name' => 'Tpyo'])->assertStatus(202);
        $this->apply(['email' => 'typo@a.test', 'tenant_name' => 'Typo'])->assertStatus(202);

        $this->assertSame('Typo', $this->application('typo@a.test')->tenant_name);
    }

    public function test_a_payment_that_cleared_before_the_phone_did_is_not_asked_for_again(): void
    {
        $res = $this->apply(['email' => 'paid-first@a.test'])->assertStatus(202);
        $this->postJson('/api/v1/auth/registration/verify-email', ['token' => $this->verificationTokenFrom($res)])->assertOk();

        $application = $this->application('paid-first@a.test');
        $this->settle($application);
        $this->assertSame(AccountState::MobileVerificationRequired, $application->refresh()->state);

        $application = app(AdvanceRegistration::class)->mobileVerified($application);

        $this->assertSame(AccountState::Active, $application->state);
    }
}
