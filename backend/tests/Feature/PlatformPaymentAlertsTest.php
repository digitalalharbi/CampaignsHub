<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Models\RegistrationVerification;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Billing\Models\PaymentWebhookEvent;
use App\Domains\Platform\Alerts\PlatformAlertInbox;
use App\Domains\Platform\Alerts\PlatformAlertPresenter;
use App\Domains\Platform\Alerts\SettlementSource;
use App\Domains\Platform\Models\PlatformAlert;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Services\ApplySubscriptionPaymentEvent;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PLATFORM-PAYMENT-ALERTS-001 — the platform owner hears about customers stuck at payment or sign-up.
 *
 * OWNER_NOTIFICATIONS_RULES_2026-11-12 §11: an abandoned checkout, a payment Moyasar took that was not confirmed, money
 * and activation that disagree, a confirmed payment missing from a settlement, and the technical errors that stop a
 * customer — each from a recorded fact, with its time, drawn, in plain words, once per attempt, and only to the
 * platform's own administrators. Nothing here moves money.
 *
 * Every test was written against the spec before the sweep existed; on `origin/main` they fail because neither the
 * `platform:payment-alerts` command nor the `platform_alerts` table is there.
 */
final class PlatformPaymentAlertsTest extends TestCase
{
    use RefreshDatabase;

    /** Wednesday 11 November 2026, 14:05 in Riyadh. */
    private Carbon $t0;

    private User $admin;

    private Tenant $agency;

    private User $agencyOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertingAcrossTenants();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        Http::preventStrayRequests();

        $this->t0 = Carbon::parse('2026-11-11 11:05:00', 'UTC');
        $this->travelTo($this->t0);

        $this->admin = User::create(['name' => 'Platform Owner', 'email' => 'owner@campaignshub.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->admin->forceFill(['is_platform_admin' => true])->save();

        $this->agency = Tenant::create(['name' => 'متجر الورد', 'slug' => 'ward-store', 'status' => 'active']);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->agencyOwner = User::create(['name' => 'Ward Owner', 'email' => 'owner@ward.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->agencyOwner, $this->agency, Portal::Agency);
        $this->agencyOwner->assignRole($role);
    }

    // ── 1. Abandoned checkout ────────────────────────────────────────────────────────────────

    public function test_an_abandoned_checkout_is_raised_once_after_the_window_with_who_plan_amount_and_last_step(): void
    {
        $registration = $this->registration('Ward Co', AccountState::PaymentPending);
        $payment = $this->payment(['registration_request_id' => $registration->getKey(), 'amount' => '199.00']);

        $this->travelTo($this->t0->copy()->addMinutes(20));
        $this->sweep();
        $this->assertSame(0, PlatformAlert::query()->count(), 'inside the 30-minute window nothing is said yet');

        $this->travelTo($this->t0->copy()->addMinutes(31));
        $this->sweep();

        $alert = PlatformAlert::query()->sole();
        $this->assertSame(PlatformAlert::ABANDONED, $alert->kind);
        $this->assertSame('abandoned:'.$payment->getKey(), $alert->dedup_key);
        $this->assertSame('Ward Co', $alert->facts['who']);
        $this->assertSame('199.00', $alert->facts['amount']);
        $this->assertSame('growth', $alert->facts['plan_code']);
        $this->assertSame('checkout', $alert->facts['last_step']);

        $card = app(PlatformAlertPresenter::class)->present($alert, true);
        $this->assertStringContainsString('الأربعاء 11 نوفمبر 14:05', $card['line'], 'the time is explicit, in Riyadh');
        $this->assertStringContainsString('30 دقيقة', $card['line']);
        $this->assertSame('تواصل معه ← أعد إرسال رابط الدفع', $card['steps']);
        $this->assertSame('steps', $card['viz']['type'], 'the funnel is drawn');
        $this->assertSame(['ok', 'ok', 'watch', 'none'], array_column($card['viz']['steps'], 'state'));
        $this->assertStringContainsString('199.00 SAR', implode(' ', $card['more']));
        $this->assertStringContainsString('آخر خطوة وصلها: صفحة الدفع', implode(' ', $card['more']));

        // Once per attempt: the next sweeps change nothing and send nothing.
        $this->sweep();
        $this->travelTo($this->t0->copy()->addMinutes(60));
        $this->sweep();
        $this->assertSame(1, PlatformAlert::query()->count());
        $this->assertSame(1, $this->alertMails()->count());

        // The customer paid after all: the alert closes itself.
        $payment->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        $this->sweep();
        $this->assertNotNull($alert->refresh()->resolved_at);
    }

    public function test_nothing_older_than_the_lookback_is_raised_on_the_first_run(): void
    {
        $registration = $this->registration('Old Co', AccountState::PaymentPending);
        $this->payment(['registration_request_id' => $registration->getKey()]);

        $this->travelTo($this->t0->copy()->addDays(5));
        $this->sweep();

        $this->assertSame(0, PlatformAlert::query()->count(), 'history is not an alert');
    }

    // ── 2. Paid but not verified ─────────────────────────────────────────────────────────────

    public function test_a_payment_moyasar_reports_paid_but_not_confirmed_here_quotes_moyasar_verbatim(): void
    {
        $registration = $this->registration('Ward Co', AccountState::PaymentPending);
        $payment = $this->payment(['registration_request_id' => $registration->getKey()]);
        $this->moyasarEvent($payment, 'paid');
        AuditLog::create(['action' => 'subscription.payment.unconfirmed', 'entity_type' => SubscriptionPayment::class, 'entity_id' => (string) $payment->getKey(), 'after' => ['provider' => 'moyasar']]);

        $this->travelTo($this->t0->copy()->addMinutes(31));
        $this->sweep();

        $alert = PlatformAlert::query()->sole();
        $this->assertSame(PlatformAlert::UNVERIFIED, $alert->kind, 'a customer who paid is not reported as having walked away');
        $this->assertSame('paid', $alert->facts['gateway_status']);
        $this->assertSame('pending', $alert->facts['our_status']);
        $this->assertSame('critical', $alert->severity);

        $card = app(PlatformAlertPresenter::class)->present($alert, true);
        $this->assertStringContainsString('«paid»', $card['line']);
        $this->assertStringContainsString('«pending»', $card['line']);
        $this->assertStringContainsString('حاولنا نسأل ميسر للتأكيد', implode(' ', $card['more']));
    }

    public function test_a_charge_left_at_moyasars_initiated_status_is_reported_with_that_word(): void
    {
        $payment = $this->payment(['registration_request_id' => $this->registration('Ward Co', AccountState::PaymentPending)->getKey()]);
        $this->moyasarEvent($payment, 'initiated');
        $payment->forceFill(['status' => 'processing', 'provider_payment_id' => 'pay_'.$payment->getKey()])->save();

        $this->travelTo($this->t0->copy()->addMinutes(31));
        $this->sweep();

        $alert = PlatformAlert::query()->sole();
        $this->assertSame(PlatformAlert::UNVERIFIED, $alert->kind);
        $this->assertSame('initiated', $alert->facts['gateway_status']);
        $this->assertStringContainsString('«initiated»', app(PlatformAlertPresenter::class)->present($alert, true)['line']);
    }

    public function test_the_payment_path_keeps_moyasars_own_status_when_it_contradicts_a_paid_event(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.publishable_key' => 'pk_test_x', 'services.moyasar.webhook_token' => 'tok', 'subscriptions.default' => 'moyasar']);
        $payment = $this->payment(['registration_request_id' => $this->registration('Ward Co', AccountState::PaymentPending)->getKey()]);
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response(['id' => 'pay_77', 'status' => 'authorized', 'amount' => 19900, 'currency' => 'SAR', 'metadata' => ['reference' => $payment->idempotency_key]], 200)]);

        app(ApplySubscriptionPaymentEvent::class)->handle('moyasar', (string) json_encode([
            'id' => 'evt_77', 'type' => 'payment_paid', 'secret_token' => 'tok',
            'data' => ['id' => 'pay_77', 'status' => 'paid', 'amount' => 19900, 'currency' => 'SAR', 'metadata' => ['reference' => $payment->idempotency_key]],
        ]), []);

        $this->assertSame('pending', $payment->refresh()->status, 'nothing settles on a contradicted event — unchanged');
        $log = AuditLog::query()->where('action', 'subscription.payment.event_contradicted')->sole();
        $this->assertSame('authorized', $log->after['gateway_raw_status'], 'Moyasar\'s own word is kept, not only our mapping');

        $this->travelTo($this->t0->copy()->addMinutes(31));
        $this->sweep();
        $alert = PlatformAlert::query()->sole();
        $this->assertSame(PlatformAlert::UNVERIFIED, $alert->kind);
        $this->assertStringContainsString('«authorized»', implode(' ', app(PlatformAlertPresenter::class)->present($alert, true)['more']));
    }

    // ── 3. Money and activation disagree ─────────────────────────────────────────────────────

    public function test_a_confirmed_payment_with_no_workspace_is_a_mismatch_and_nothing_moves_money(): void
    {
        $registration = $this->registration('Ward Co', AccountState::PaymentPending);
        $payment = $this->payment(['registration_request_id' => $registration->getKey()]);
        $payment->forceFill(['status' => 'paid', 'paid_at' => now(), 'provider_payment_id' => 'pay_1'])->save();

        $this->travelTo($this->t0->copy()->addMinutes(10));
        $this->sweep();
        $this->assertSame(0, PlatformAlert::query()->count(), 'activation gets its grace first');

        $this->travelTo($this->t0->copy()->addMinutes(16));
        $this->sweep();

        $alert = PlatformAlert::query()->sole();
        $this->assertSame(PlatformAlert::MISMATCH, $alert->kind);
        $this->assertSame('not_provisioned', $alert->facts['variant']);
        $this->assertSame('paid', $payment->refresh()->status, 'the sweep changes no payment');
        $this->assertNull($registration->refresh()->tenant_id, 'and provisions nothing');
        Http::assertNothingSent();
    }

    public function test_money_owed_back_is_reported_with_the_recorded_reason_and_no_refund_is_attempted(): void
    {
        $payment = $this->payment(['registration_request_id' => $this->registration('Ward Co', AccountState::PaymentPending)->getKey()]);
        $payment->forceFill(['status' => 'refund_due', 'paid_at' => now(), 'error' => 'This workspace has complimentary access, so this payment is owed back.'])->save();

        $this->travelTo($this->t0->copy()->addMinutes(16));
        $this->sweep();

        $alert = PlatformAlert::query()->sole();
        $this->assertSame('refund_due', $alert->facts['variant']);
        $card = app(PlatformAlertPresenter::class)->present($alert, true);
        $this->assertStringContainsString('complimentary access', implode(' ', $card['more']));
        $this->assertStringContainsString('ما فيه أي إجراء مالي تلقائي', implode(' ', $card['more']));
        $this->assertSame('refund_due', $payment->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_an_invoice_marked_paid_without_a_settled_payment_is_a_mismatch(): void
    {
        $payment = $this->payment(['tenant_id' => $this->agency->getKey()]);
        $invoiceId = (string) Str::uuid();
        DB::table('subscription_invoices')->insert([
            'id' => $invoiceId, 'number' => 'CH-2026-0042', 'tenant_id' => $this->agency->getKey(), 'subscription_payment_id' => $payment->getKey(),
            'bill_to_name' => 'Ward', 'bill_to_email' => 'billing@ward.test', 'currency' => 'SAR', 'total' => 199, 'status' => 'paid',
            'issued_at' => now(), 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->travelTo($this->t0->copy()->addMinutes(16));
        $this->sweep();

        $alert = PlatformAlert::query()->where('kind', PlatformAlert::MISMATCH)->sole();
        $this->assertSame('invoice_paid_without_payment', $alert->facts['variant']);
        $this->assertSame('متجر الورد', $alert->facts['who']);
        $this->assertStringContainsString('CH-2026-0042', app(PlatformAlertPresenter::class)->present($alert, true)['line']);
    }

    // ── 4. Settlement ────────────────────────────────────────────────────────────────────────

    public function test_the_settlement_check_is_blocked_without_a_source_and_raises_nothing(): void
    {
        $payment = $this->payment(['tenant_id' => $this->agency->getKey()]);
        $payment->forceFill(['status' => 'paid', 'paid_at' => now(), 'provider_payment_id' => 'pay_s1'])->save();
        $this->settledInvoice($payment);

        $this->travelTo($this->t0->copy()->addDays(9));
        $this->artisan('platform:payment-alerts')->expectsOutputToContain('Settlement check: BLOCKED_EXTERNAL_CREDENTIALS')->assertSuccessful();

        $this->assertSame(0, PlatformAlert::query()->where('kind', PlatformAlert::UNSETTLED)->count());
    }

    public function test_behind_its_interface_the_settlement_check_reports_a_confirmed_payment_missing_from_settlements(): void
    {
        $payment = $this->payment(['tenant_id' => $this->agency->getKey()]);
        $payment->forceFill(['status' => 'paid', 'paid_at' => now(), 'provider_payment_id' => 'pay_s2'])->save();
        $this->settledInvoice($payment);
        $source = new class implements SettlementSource
        {
            /** @var list<string>|null */
            public ?array $settled = [];

            public function state(): string
            {
                return self::AVAILABLE;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function settled(string $provider, array $providerPaymentIds, Carbon $since): ?array
            {
                return $this->settled;
            }

            public function coverage(): array
            {
                // A history that covers the payment's period: a settlement exists after it.
                return ['settlements' => 1, 'latest_at' => now()->toIso8601String(), 'since' => now()->subDays(9)->toIso8601String()];
            }
        };
        $this->app->instance(SettlementSource::class, $source);

        $this->travelTo($this->t0->copy()->addDays(6));
        $this->sweep();
        $this->assertSame(0, PlatformAlert::query()->where('kind', PlatformAlert::UNSETTLED)->count(), 'before the expected period, nothing');

        $source->settled = null;
        $this->travelTo($this->t0->copy()->addDays(8));
        $this->sweep();
        $this->assertSame(0, PlatformAlert::query()->where('kind', PlatformAlert::UNSETTLED)->count(), 'an unreadable source is not «unsettled»');

        $source->settled = [];
        $this->sweep();
        $alert = PlatformAlert::query()->where('kind', PlatformAlert::UNSETTLED)->sole();
        $this->assertSame('unsettled:'.$payment->getKey(), $alert->dedup_key);
    }

    // ── 5. Blocking technical errors ─────────────────────────────────────────────────────────

    public function test_codes_that_never_reached_customers_are_one_grouped_alert_that_grows_without_repeating_anyone(): void
    {
        $a = $this->registration('Alpha', AccountState::MobileVerificationRequired);
        $b = $this->registration('Beta', AccountState::MobileVerificationRequired);
        $this->verification($a, 'mobile', 'failed');
        $this->verification($b, 'mobile', 'failed');

        $this->sweep();
        $alert = PlatformAlert::query()->sole();
        $this->assertSame(PlatformAlert::BLOCKING, $alert->kind);
        $this->assertSame('otp_not_delivered', $alert->facts['type']);
        $this->assertSame(2, $alert->occurrences);
        $card = app(PlatformAlertPresenter::class)->present($alert, true);
        $this->assertSame('count', $card['viz']['type']);
        $this->assertSame(2, $card['viz']['n']);
        $this->assertStringContainsString('الحالة «failed»', implode(' ', $card['more']));

        $this->sweep();
        $this->assertSame(2, $alert->refresh()->occurrences, 'the same customers are not counted twice');
        $this->assertSame(1, $this->alertMails()->count());

        // A third customer, hours later the same day: the group grows and the owner is told again.
        $this->travelTo($this->t0->copy()->addHours(4));
        $this->verification($this->registration('Gamma', AccountState::MobileVerificationRequired), 'mobile', 'failed');
        $this->sweep();
        $this->assertSame(1, PlatformAlert::query()->count());
        $this->assertSame(3, $alert->refresh()->occurrences);
        $this->assertSame(2, $this->alertMails()->count());

        // A customer who got through after all is not reported.
        $this->travelTo($this->t0->copy()->addHours(5));
        $late = $this->registration('Delta', AccountState::MobileVerificationRequired);
        $this->verification($late, 'mobile', 'failed');
        $late->forceFill(['mobile_verified_at' => now(), 'state' => AccountState::PendingApproval->value])->save();
        $this->sweep();
        $this->assertSame(3, $alert->refresh()->occurrences);
    }

    public function test_an_expired_code_a_payment_error_and_a_failed_invitation_are_each_reported(): void
    {
        $stuck = $this->registration('Stuck Co', AccountState::MobileVerificationRequired);
        $this->verification($stuck, 'mobile', 'sent', expiresAt: $this->t0->copy()->addMinutes(10));

        $failed = $this->payment(['registration_request_id' => $this->registration('Declined Co', AccountState::PaymentPending)->getKey()]);
        $failed->forceFill(['status' => 'failed', 'error' => 'Moyasar refused the invoice: 422 for card 4111111111111111 with sk_live_abc123'])->save();

        DB::table('mail_deliveries')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->agency->getKey(), 'kind' => 'invitation', 'recipient' => 'newmember@ward.test',
            'locale' => 'ar', 'template' => 'mail.invitation', 'status' => 'failed', 'transport' => 'smtp', 'attempts' => 1,
            'error' => 'Connection refused', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->travelTo($this->t0->copy()->addMinutes(45));
        $this->sweep();

        $types = PlatformAlert::query()->where('kind', PlatformAlert::BLOCKING)->get()->mapWithKeys(fn (PlatformAlert $a) => [$a->facts['type'] => $a]);
        $this->assertEqualsCanonicalizing(['otp_expired', 'payment_error', 'invitation_failed'], $types->keys()->all());

        $payment = $types['payment_error']->facts['items'][0];
        $this->assertStringNotContainsString('4111111111111111', (string) $payment['recorded_error'], 'no card number');
        $this->assertStringNotContainsString('sk_live_abc123', (string) $payment['recorded_error'], 'no key');
        $this->assertStringContainsString('422', (string) $payment['recorded_error']);

        $invite = $types['invitation_failed']->facts['items'][0];
        $this->assertSame('ne***@ward.test', $invite['recipient'], 'the address is recognisable, not copied whole');
        $this->assertSame('متجر الورد', $invite['who']);
    }

    // ── Review findings: the healthy flows that must stay quiet, and the gaps that must not ───

    public function test_a_renewal_page_opened_by_the_lifecycle_sweep_is_not_a_customer_who_walked_away(): void
    {
        $subscriptionId = $this->subscription('active');
        $this->payment(['tenant_id' => $this->agency->getKey(), 'subscription_id' => $subscriptionId, 'purpose' => 'subscription']);

        $this->travelTo($this->t0->copy()->addHours(2));
        $this->sweep();

        $this->assertSame(0, PlatformAlert::query()->count(), 'nobody reached a page the system opened');
    }

    public function test_a_customer_who_switched_term_and_paid_is_not_reported_for_the_first_attempt(): void
    {
        $registration = $this->registration('Ward Co', AccountState::PaymentPending);
        $monthly = $this->payment(['registration_request_id' => $registration->getKey()]);

        $this->travelTo($this->t0->copy()->addMinutes(31));
        $this->sweep();
        $alert = PlatformAlert::query()->sole();
        $this->assertSame('abandoned:'.$monthly->getKey(), $alert->dedup_key);

        // They came back, chose annual, and that attempt is the one that counts.
        $this->payment(['registration_request_id' => $registration->getKey(), 'billing_interval' => 'annual', 'amount' => '1990.00']);
        $this->sweep();
        $this->assertNotNull($alert->refresh()->resolved_at, 'the old attempt no longer stands for the customer');
    }

    public function test_a_refunded_payment_is_not_reported_as_unconfirmed_and_a_started_charge_is_not_called_paid(): void
    {
        $refunded = $this->payment(['registration_request_id' => $this->registration('Refunded Co', AccountState::Active)->getKey()]);
        $this->moyasarEvent($refunded, 'paid');
        $refunded->forceFill(['status' => 'refunded', 'paid_at' => now(), 'refunded_at' => now()])->save();

        $started = $this->payment(['registration_request_id' => $this->registration('Started Co', AccountState::PaymentPending)->getKey()]);
        $this->moyasarEvent($started, 'initiated');
        $started->forceFill(['status' => 'processing'])->save();

        $this->travelTo($this->t0->copy()->addMinutes(31));
        $this->sweep();

        $alert = PlatformAlert::query()->sole();
        $this->assertSame('unverified:'.$started->getKey(), $alert->dedup_key);
        $this->assertSame('warning', $alert->severity);
        $this->assertSame('بدأ الدفع في ميسر وما اكتمل', app(PlatformAlertPresenter::class)->present($alert, true)['title']);

        // Moyasar then fails it: the alert clears and the same attempt is not reported again as a payment error.
        $started->forceFill(['status' => 'failed'])->save();
        $this->sweep();
        $this->assertNotNull($alert->refresh()->resolved_at);
        $this->assertSame(0, PlatformAlert::query()->where('kind', PlatformAlert::BLOCKING)->count());
    }

    public function test_a_saved_card_charge_with_no_word_from_moyasar_is_reported(): void
    {
        $subscriptionId = $this->subscription('active');
        $payment = $this->payment(['tenant_id' => $this->agency->getKey(), 'subscription_id' => $subscriptionId, 'checkout_url' => null, 'provider_session_id' => null]);
        $payment->forceFill(['provider_payment_id' => 'pay_saved_1'])->save();

        $this->travelTo($this->t0->copy()->addMinutes(31));
        $this->sweep();

        $alert = PlatformAlert::query()->sole();
        $this->assertSame(PlatformAlert::UNVERIFIED, $alert->kind);
        $this->assertNull($alert->facts['gateway_status']);
        $this->assertStringContainsString('ما وصلنا من ميسر إشعار', app(PlatformAlertPresenter::class)->present($alert, true)['line']);
    }

    public function test_money_owed_back_is_one_alert_even_when_its_invoice_was_marked_paid(): void
    {
        $payment = $this->payment(['registration_request_id' => $this->registration('Ward Co', AccountState::PaymentPending)->getKey()]);
        $payment->forceFill(['status' => 'refund_due', 'paid_at' => now(), 'error' => 'A trial has already been used by this payment method.'])->save();
        $this->settledInvoice($payment);

        $this->travelTo($this->t0->copy()->addMinutes(16));
        $this->sweep();

        $this->assertSame(['mismatch:refund_due:'.$payment->getKey()], PlatformAlert::query()->pluck('dedup_key')->all());
        $this->assertSame(1, $this->alertMails()->count());
    }

    public function test_a_subscription_cancelled_after_it_began_is_a_later_decision_not_a_mismatch(): void
    {
        $registration = $this->registration('Ward Co', AccountState::Active);
        $registration->forceFill(['tenant_id' => $this->agency->getKey()])->save();
        $payment = $this->payment(['registration_request_id' => $registration->getKey()]);
        $payment->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        $this->subscription('canceled');

        $this->travelTo($this->t0->copy()->addMinutes(16));
        $this->sweep();

        $this->assertSame(0, PlatformAlert::query()->count());
    }

    public function test_customers_added_to_a_group_inside_the_window_are_mailed_once_the_window_passes(): void
    {
        $this->verification($this->registration('Alpha', AccountState::MobileVerificationRequired), 'mobile', 'failed');
        $this->sweep();
        $this->assertSame(1, $this->alertMails()->count());

        $this->travelTo($this->t0->copy()->addMinutes(22));
        $this->verification($this->registration('Beta', AccountState::MobileVerificationRequired), 'mobile', 'failed');
        $this->sweep();
        $this->assertSame(2, PlatformAlert::query()->sole()->occurrences);
        $this->assertSame(1, $this->alertMails()->count(), 'inside the window the owner is not mailed again');

        $this->travelTo($this->t0->copy()->addHours(3)->addMinutes(7));
        $this->sweep();
        $this->assertSame(2, $this->alertMails()->count(), 'the new customer is not dropped: named once the window passes');
        $this->sweep();
        $this->assertSame(2, $this->alertMails()->count());
    }

    // ── Isolation: the platform's, and only the platform's ───────────────────────────────────

    public function test_workspace_users_never_see_or_receive_platform_alerts_even_about_their_own_workspace(): void
    {
        $payment = $this->payment(['tenant_id' => $this->agency->getKey(), 'purpose' => 'plan_change']);
        $this->travelTo($this->t0->copy()->addMinutes(31));
        $this->sweep();
        $alert = PlatformAlert::query()->sole();
        $this->assertSame($this->agency->getKey(), $alert->subject_tenant_id);

        // Mail: platform administrators only, no workspace on the ledger row.
        $this->assertSame([$this->admin->email], $this->alertMails()->pluck('recipient')->all());
        $this->assertSame(0, DB::table('mail_deliveries')->where('recipient', $this->agencyOwner->email)->count());
        $this->assertSame(0, $this->alertMails()->whereNotNull('tenant_id')->count());

        // API: a workspace session of any role is refused.
        $this->actingAs($this->agencyOwner)->getJson('/api/v1/admin/platform-alerts')->assertForbidden();
        $this->actingAs($this->agencyOwner)->patchJson("/api/v1/admin/platform-alerts/{$alert->getKey()}", ['acknowledged' => true])->assertForbidden();
        $this->assertNull($alert->refresh()->acknowledged_at);

        // The platform owner reads it, drawn and worded, and marks it handled — audited.
        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/platform-alerts')->assertOk();
        $res->assertJsonPath('data.alerts.0.id', $alert->getKey());
        $res->assertJsonPath('data.alerts.0.card.title', 'عميل وصل للدفع وما كمّل');
        $res->assertJsonPath('data.settlement_check', 'BLOCKED_EXTERNAL_CREDENTIALS');
        $this->actingAs($this->admin)->patchJson("/api/v1/admin/platform-alerts/{$alert->getKey()}", ['acknowledged' => true])->assertOk();
        $this->assertNotNull($alert->refresh()->acknowledged_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'platform.alert.acknowledged')->count());
        $this->actingAs($this->admin)->getJson('/api/v1/admin/platform-alerts')->assertOk()->assertJsonCount(0, 'data.alerts');
    }

    public function test_a_disabled_administrator_is_not_told(): void
    {
        $gone = User::create(['name' => 'Former Admin', 'email' => 'former@campaignshub.test', 'password' => 'secret123']);
        $gone->forceFill(['is_platform_admin' => true, 'disabled_at' => now()])->save();
        $this->payment(['registration_request_id' => $this->registration('Ward Co', AccountState::PaymentPending)->getKey()]);

        $this->travelTo($this->t0->copy()->addMinutes(31));
        $this->sweep();

        $this->assertSame([$this->admin->email], $this->alertMails()->pluck('recipient')->all());
    }

    // ── The email ────────────────────────────────────────────────────────────────────────────

    public function test_the_email_is_drawn_dated_in_latin_digits_and_carries_no_card_data_or_secret(): void
    {
        $payment = $this->payment(['registration_request_id' => $this->registration('Ward Co', AccountState::PaymentPending)->getKey()]);
        $this->moyasarEvent($payment, 'paid', source: ['type' => 'creditcard', 'number' => '4111-11XX-XXXX-1111', 'token' => 'token_secret_abc', 'message' => 'APPROVED']);

        $this->travelTo($this->t0->copy()->addMinutes(31));
        $this->sweep();
        $alert = PlatformAlert::query()->sole();

        $card = app(PlatformAlertPresenter::class)->present($alert, true);
        $html = app(PlatformAlertInbox::class)->mail($alert, $card, true, 'محمد')->render();

        $this->assertStringContainsString('data-viz="steps"', $html, 'every card carries a drawing');
        $this->assertStringContainsString('الأربعاء 11 نوفمبر 14:05', $html);
        $this->assertStringContainsString('/admin/requests?alert='.$alert->getKey(), $html);
        $this->assertDoesNotMatchRegularExpression('/[٠-٩]/u', $html, 'Latin digits only');
        $this->assertStringNotContainsString('token_secret_abc', $html);
        $this->assertStringNotContainsString('4111-11XX', $html);
        $this->assertStringNotContainsString('token_secret_abc', (string) json_encode($alert->facts));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    private function sweep(): void
    {
        app(PlatformAlertInbox::class)->sweep();
    }

    private function alertMails(): Builder
    {
        return DB::table('mail_deliveries')->where('kind', PlatformAlertInbox::MAIL_KIND);
    }

    private function registration(string $company, AccountState $state): RegistrationRequest
    {
        $request = new RegistrationRequest;
        $request->forceFill([
            'email' => Str::slug($company).'@customer.test',
            'name' => $company.' Owner',
            'tenant_name' => $company,
            'account_type' => 'brand',
            'requested_portal' => 'app',
            'plan_code' => 'growth',
            'billing_interval' => 'monthly',
            'phone' => '+9665000000'.random_int(10, 99),
            'password' => Hash::make('secret1234'),
            'state' => $state->value,
            'email_verified_at' => now(),
            // Past verification unless the state says the customer is still on it.
            'mobile_verified_at' => in_array($state, [AccountState::MobileVerificationRequired, AccountState::EmailVerificationRequired], true) ? null : now(),
        ])->save();

        return $request->refresh();
    }

    /** @param  array<string,mixed>  $attributes */
    private function payment(array $attributes): SubscriptionPayment
    {
        $payment = new SubscriptionPayment;
        $payment->forceFill($attributes + [
            'purpose' => 'subscription',
            'plan_code' => 'growth',
            'billing_interval' => 'monthly',
            'provider' => 'moyasar',
            'provider_session_id' => 'inv_'.Str::random(6),
            'checkout_url' => 'https://checkout.moyasar.com/invoices/inv_x',
            'amount' => '199.00',
            'currency' => 'SAR',
            'status' => 'pending',
            'idempotency_key' => 'subscription:'.Str::uuid(),
        ])->save();

        return $payment->refresh();
    }

    /** @param  array<string,mixed>  $source */
    private function moyasarEvent(SubscriptionPayment $payment, string $status, array $source = []): void
    {
        $event = new PaymentWebhookEvent;
        $event->forceFill([
            'provider' => 'moyasar',
            'event_id' => 'evt_'.Str::random(8),
            'type' => 'payment_'.$status,
            'verified' => true,
            'payload' => ['id' => 'evt', 'type' => 'payment_'.$status, 'secret_token' => 'tok', 'data' => array_filter([
                'id' => 'pay_'.$payment->getKey(), 'status' => $status, 'amount' => 19900, 'currency' => 'SAR',
                'metadata' => ['reference' => $payment->idempotency_key], 'source' => $source ?: null,
            ])],
            'processed_at' => now(),
        ])->save();
    }

    private function verification(RegistrationRequest $registration, string $channel, string $status, ?Carbon $expiresAt = null): void
    {
        $v = new RegistrationVerification;
        $v->forceFill([
            'registration_request_id' => $registration->getKey(),
            'channel' => $channel,
            'provider' => 'local',
            'token_hash' => hash('sha256', Str::random(16)),
            'delivery_status' => $status,
            'expires_at' => $expiresAt ?? now()->addMinutes(10),
        ])->save();
    }

    private function subscription(string $status): string
    {
        $id = (string) Str::uuid();
        DB::table('subscriptions')->insert([
            'id' => $id, 'tenant_id' => $this->agency->getKey(), 'plan_id' => DB::table('subscription_plans')->where('code', 'growth')->value('id'),
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function settledInvoice(SubscriptionPayment $payment): void
    {
        DB::table('subscription_invoices')->insert([
            'id' => (string) Str::uuid(), 'number' => 'CH-'.Str::random(6), 'tenant_id' => $payment->tenant_id, 'subscription_payment_id' => $payment->getKey(),
            'bill_to_name' => 'Ward', 'bill_to_email' => 'billing@ward.test', 'currency' => 'SAR', 'total' => 199, 'status' => 'paid',
            'issued_at' => now(), 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
