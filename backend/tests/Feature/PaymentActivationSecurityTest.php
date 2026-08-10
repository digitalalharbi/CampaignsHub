<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Actions\ProvisionWorkspace;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\AdvanceRegistration;
use App\Domains\Billing\Providers\MoyasarPaymentProvider;
use App\Domains\Billing\Providers\SandboxPaymentProvider;
use App\Domains\Billing\Providers\StripePaymentProvider;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * The one claim the whole payment layer stands on (PAY-002).
 *
 * **An account activates only from a payment the gateway cryptographically confirmed.** Everything
 * else in this file is an attempt to activate some other way, and every one of them has to fail.
 *
 * The attacks are not hypothetical. Each corresponds to a shortcut that would be easy to take and
 * hard to notice: returning from the payment page, replaying a captured webhook, forging one, calling
 * the provisioner directly, or simply asking twice.
 */
final class PaymentActivationSecurityTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        // The gate under test: this plan is only had by paying for it.
        config(['accounts.registration.plans.growth' => ['requires_payment' => true]]);
    }

    /** An application that has cleared verification and is sitting at the payment gate. */
    private function owing(string $email = 'owing@a.test'): RegistrationRequest
    {
        $res = $this->apply(['email' => $email, 'plan_code' => 'growth', 'billing_interval' => 'monthly']);
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($res),
        ])->assertOk();

        $request = RegistrationRequest::query()->whereRaw('lower(email) = ?', [$email])->firstOrFail();

        // The mobile gate comes first since PHONE-VERIFY-001, and these tests are about the one after
        // it — an application still holding at the phone step never reaches a charge to attack.
        $this->verifyMobileFor($request);
        $request = $request->refresh();

        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->state, 'the payment gate must be holding it');

        return $request;
    }

    /**
     * Moyasar's own answer to `GET /v1/payments/{id}` (PAY-CONFIRM-001).
     *
     * Every Moyasar settlement below goes through this, because the product no longer takes a
     * webhook body's word for money: the token that authenticates a Moyasar webhook travels inside
     * the body it is supposed to authenticate, so «verified» proves the sender knew a secret and
     * nothing about the figures beside it. Faking the gateway here is what makes these tests
     * exercise the real path rather than a shortcut round it.
     */
    private function gatewaySays(string $status, string $amount, string $currency, ?string $reference): void
    {
        Http::fake([
            'api.moyasar.com/v1/payments/*' => Http::response([
                'id' => 'pay_1',
                'status' => $status === 'paid' ? 'paid' : $status,
                'amount' => (int) round(((float) $amount) * 100),
                'currency' => $currency,
                'metadata' => $reference === null ? [] : ['reference' => $reference],
            ], 200),
        ]);
    }

    /** The gateway cannot be reached at all — silence, which must never read as consent. */
    private function gatewayIsUnreachable(): void
    {
        Http::fake(['api.moyasar.com/*' => Http::response(null, 500)]);
    }

    private function assertNothingGranted(): void
    {
        $this->assertSame(0, Tenant::count(), 'no workspace may exist');
        $this->assertSame(0, User::count(), 'no sign-in account may exist');
        $this->assertSame(0, Membership::count(), 'no membership may exist');
    }

    // ── The browser is not evidence ───────────────────────────────────────────────────────────

    /**
     * There is NO endpoint a browser can call to declare itself paid.
     *
     * The contract is explicit: «لا تعتبر الرجوع من صفحة الدفع نجاحًا». Returning from a payment page
     * proves nothing — the page can be closed, the URL can be typed, the redirect can be replayed.
     */
    public function test_no_public_endpoint_can_declare_a_payment_made(): void
    {
        $request = $this->owing();
        $id = $request->getKey();

        // Every public thing an applicant can reach, tried in turn.
        $this->postJson("/api/v1/auth/registration/{$id}/resend", ['channel' => 'email'])->assertOk();
        $this->getJson("/api/v1/auth/registration/{$id}")->assertOk()
            ->assertJsonPath('data.registration.state', 'approved_awaiting_payment');

        // …and opening a checkout is not paying for it.
        $this->postJson("/api/v1/auth/registration/{$id}/checkout", ['commitment_agreed' => true])->assertOk();

        $this->assertSame(
            AccountState::ApprovedAwaitingPayment,
            RegistrationRequest::findOrFail($id)->state,
        );
        $this->assertNothingGranted();
    }

    // ── Forged and replayed webhooks ──────────────────────────────────────────────────────────

    /**
     * An unsigned webhook activates nothing.
     *
     * With no credentials configured the adapter cannot verify anything at all, so this is also the
     * shipped state: a public endpoint that anyone may post to and that grants nothing.
     */
    public function test_an_unverified_webhook_activates_nothing(): void
    {
        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();

        $payment = SubscriptionPayment::query()->firstOrFail();

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_forged', 'type' => 'payment_paid',
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'amount' => (int) round((float) $payment->amount * 100), 'currency' => $payment->currency,
                'metadata' => ['reference' => $payment->idempotency_key]],
        ])->assertOk()->assertJsonPath('data.verified', false);

        // Untouched: with no gateway configured the charge never even opened, and a forged event
        // cannot move it.
        $this->assertSame('awaiting_credentials', $payment->refresh()->status);
        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->refresh()->state);
        $this->assertNothingGranted();
    }

    /** Even a correctly-shaped event carrying the wrong secret is refused. */
    public function test_a_webhook_with_the_wrong_secret_is_refused(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'the-real-token']);

        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_wrong_secret', 'type' => 'payment_paid', 'secret_token' => 'not-the-real-token',
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'amount' => (int) round((float) $payment->amount * 100), 'currency' => $payment->currency,
                'metadata' => ['reference' => $payment->idempotency_key]],
        ])->assertOk()->assertJsonPath('data.verified', false);

        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertNothingGranted();
    }

    /**
     * A verified event confirms the payment — and a SECOND delivery of it changes nothing.
     *
     * Gateways retry by design. Without the unique event id, a retry is a second activation, a second
     * trial claim, and on a refund a second reversal.
     */
    public function test_a_verified_webhook_activates_once_and_a_replay_does_nothing(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'shared-secret']);

        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();

        $this->gatewaySays('paid', (string) $payment->amount, (string) $payment->currency, (string) $payment->idempotency_key);

        $event = [
            'id' => 'evt_real', 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'amount' => (int) round((float) $payment->amount * 100), 'currency' => $payment->currency,
                'metadata' => ['reference' => $payment->idempotency_key]],
        ];

        $this->postJson('/api/v1/payments/webhook/moyasar', $event)->assertOk()
            ->assertJsonPath('data.verified', true);

        $this->assertSame('paid', $payment->refresh()->status);
        $this->assertSame(AccountState::Active, $request->refresh()->state);
        $this->assertSame(1, Membership::count(), 'exactly one workspace');

        // The same event again — a retry, or a captured payload replayed.
        $this->postJson('/api/v1/payments/webhook/moyasar', $event)->assertOk();

        $this->assertSame(1, Tenant::count(), 'a replay must not mint a second workspace');
        $this->assertSame(1, Membership::count());
    }

    /**
     * A charge the GATEWAY itself reports short settles nothing.
     *
     * Since PAY-CONFIRM-001 the figure comes from `GET /v1/payments/{id}` rather than from the
     * webhook, so this is now the only way a wrong amount can reach the check at all — a partial
     * capture, or a charge that is genuinely not the one we quoted.
     */
    public function test_a_charge_the_gateway_reports_short_does_not_settle(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'shared-secret']);

        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();

        // One riyal against the real fee — stated by the gateway, not merely claimed by a caller.
        $this->gatewaySays('paid', '1.00', (string) $payment->currency, (string) $payment->idempotency_key);

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_short', 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            // One riyal against a nine-riyal fee.
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'amount' => 100, 'currency' => 'SAR',
                'metadata' => ['reference' => $payment->idempotency_key]],
        ])->assertOk()->assertJsonPath('data.verified', true);

        $this->assertSame('failed', $payment->refresh()->status);
        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->refresh()->state);
        $this->assertNothingGranted();
    }

    /**
     * The right FIGURE in the wrong CURRENCY settles nothing either.
     *
     * This is the case the amount check alone let through, and it is not exotic: subscriptions are
     * sold in USD (PAY-AUDIT-002) while both live adapters default a stated currency to SAR, so a
     * payload reading `49.00 SAR` against a `49.00 USD` invoice compared equal on the only field
     * anybody looked at — and activated the account for roughly a quarter of the price. An amount
     * without its currency is a number, not money.
     */
    public function test_a_verified_event_in_the_wrong_currency_does_not_settle(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'shared-secret']);

        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();

        // The exact figure we asked for — denominated in a currency we did not.
        $this->assertNotSame('SAR', strtoupper((string) $payment->currency));
        $this->gatewaySays('paid', (string) $payment->amount, 'SAR', (string) $payment->idempotency_key);

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_currency', 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            'data' => [
                'id' => 'pay_1', 'status' => 'paid',
                'amount' => (int) round((float) $payment->amount * 100), 'currency' => 'SAR',
                'metadata' => ['reference' => $payment->idempotency_key],
            ],
        ])->assertOk()->assertJsonPath('data.verified', true);

        $this->assertSame('failed', $payment->refresh()->status);
        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->refresh()->state);
        $this->assertNothingGranted();
    }

    /** Case is not a currency: `usd` and `USD` are one currency, and must not read as a mismatch. */
    public function test_the_currency_check_is_not_defeated_by_case(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'shared-secret']);

        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();

        $this->gatewaySays('paid', (string) $payment->amount, strtolower((string) $payment->currency), (string) $payment->idempotency_key);

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_case', 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            'data' => [
                'id' => 'pay_1', 'status' => 'paid',
                'amount' => (int) round((float) $payment->amount * 100),
                'currency' => strtolower((string) $payment->currency),
                'metadata' => ['reference' => $payment->idempotency_key],
            ],
        ])->assertOk()->assertJsonPath('data.verified', true);

        $this->assertSame('paid', $payment->refresh()->status);
    }

    // ── PAY-CONFIRM-001: the gateway is asked, not believed ───────────────────────────────────

    /**
     * A forged body with the right token is neutralised by the gateway's own answer.
     *
     * This is the attack the re-fetch exists for, and it is not theoretical. Moyasar's webhook
     * authenticates with a shared secret carried INSIDE the payload, so anybody who learns that
     * token — a leaked log line, a misconfigured proxy, a former contractor — can post a perfectly
     * «verified» event claiming any amount they like. Before this, that event settled the invoice.
     *
     * Now the figures come from `GET /v1/payments/{id}` over our own connection, so what the caller
     * wrote in the body simply does not matter: they claim one riyal, the gateway states the real
     * fee, and the charge settles for the real fee.
     */
    public function test_a_forged_amount_in_the_body_is_overridden_by_the_gateway(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'shared-secret']);

        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();

        $this->gatewaySays('paid', (string) $payment->amount, (string) $payment->currency, (string) $payment->idempotency_key);

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_forged', 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            // A lie, correctly signed with the shared token.
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'amount' => 100, 'currency' => 'SAR',
                'metadata' => ['reference' => $payment->idempotency_key]],
        ])->assertOk()->assertJsonPath('data.verified', true);

        $this->assertSame('paid', $payment->refresh()->status);
        $this->assertSame(AccountState::Active, $request->refresh()->state);
    }

    /**
     * A «paid» event the gateway does not call paid settles nothing.
     *
     * The status is as forgeable as the amount, and it is the one that grants the workspace.
     */
    public function test_a_paid_event_the_gateway_calls_pending_settles_nothing(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'shared-secret']);

        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();

        $this->gatewaySays('initiated', (string) $payment->amount, (string) $payment->currency, (string) $payment->idempotency_key);

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_claim', 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'amount' => (int) round((float) $payment->amount * 100),
                'currency' => $payment->currency, 'metadata' => ['reference' => $payment->idempotency_key]],
        ])->assertOk();

        $this->assertNotSame('paid', $payment->refresh()->status);
        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->refresh()->state);
        $this->assertNothingGranted();
    }

    /**
     * A gateway that cannot be reached has told us NOTHING, and nothing is not consent.
     *
     * The payment is left where it was rather than marked failed: nothing is known to be wrong with
     * the money, only unconfirmed, and calling it failed would start dunning a customer who has paid.
     * Gateways redeliver, and the next delivery asks again.
     */
    public function test_an_unreachable_gateway_settles_nothing_and_fails_nothing(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'shared-secret']);

        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();
        $before = (string) $payment->status;

        $this->gatewayIsUnreachable();

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_offline', 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'amount' => (int) round((float) $payment->amount * 100),
                'currency' => $payment->currency, 'metadata' => ['reference' => $payment->idempotency_key]],
        ])->assertOk();

        $this->assertSame($before, (string) $payment->refresh()->status, 'the charge must be left exactly as it was');
        $this->assertNotSame('failed', (string) $payment->status);
        $this->assertNothingGranted();

        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.payment.unconfirmed']);
    }

    /**
     * A genuinely paid charge belonging to somebody ELSE does not settle this invoice.
     *
     * The shape this closes: a real, verified, gateway-confirmed payment id pointed at a different
     * reference. The event resolved to our charge, and the gateway attributes the money elsewhere.
     */
    public function test_a_charge_the_gateway_attributes_elsewhere_does_not_settle(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'shared-secret']);

        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();

        $this->gatewaySays('paid', (string) $payment->amount, (string) $payment->currency, 'somebody-elses-reference');

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_other', 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'amount' => (int) round((float) $payment->amount * 100),
                'currency' => $payment->currency, 'metadata' => ['reference' => $payment->idempotency_key]],
        ])->assertOk();

        $this->assertNotSame('paid', $payment->refresh()->status);
        $this->assertNothingGranted();
        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.payment.reference_mismatch']);
    }

    /**
     * The sandbox is NOT re-asked, and must not be.
     *
     * It signs the raw body, so its events are already attested; sending it to a gateway that does
     * not exist would strand every signup on a machine with no credentials — which is the exact
     * situation the sandbox was built for.
     */
    public function test_a_signed_provider_is_not_re_asked(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->assertTrue(app(SandboxPaymentProvider::class)->confirmsPayloadIntegrity());
        $this->assertTrue(app(StripePaymentProvider::class)->confirmsPayloadIntegrity());
        $this->assertFalse(app(MoyasarPaymentProvider::class)->confirmsPayloadIntegrity());
    }

    // ── The provisioner itself ────────────────────────────────────────────────────────────────

    /**
     * `ProvisionWorkspace` refuses an unpaid application even when called directly.
     *
     * This is the hole the ledger check closes. `PaymentPending` is a LEGAL state to provision from —
     * it is the anchor a webhook activates out of — so a caller holding a request in it could
     * previously have got a workspace with no money having moved. "Only one caller does the right
     * thing" is a convention; this is a guarantee.
     */
    public function test_the_provisioner_refuses_an_application_that_owes_money(): void
    {
        $request = $this->owing();

        // Put it in the state a webhook would activate out of, WITHOUT the payment.
        $request->forceFill(['state' => AccountState::PaymentPending->value])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no settled charge exists/');

        app(ProvisionWorkspace::class)->execute($request->refresh());
    }

    /** …and the same is true through `AdvanceRegistration`, which is the ordinary route in. */
    public function test_advancing_a_payment_pending_application_without_a_payment_grants_nothing(): void
    {
        $request = $this->owing();
        $request->forceFill(['state' => AccountState::PaymentPending->value])->save();

        // Re-proving the email is the closest thing to a nudge an applicant has.
        app(AdvanceRegistration::class)->emailVerified($request->refresh());

        $this->assertNothingGranted();
    }

    /**
     * A REFUNDED payment is not a payment.
     *
     * Money that came back is money we do not have, and an application whose fee was reversed must
     * not still be provisionable on the strength of the row that says it once paid.
     */
    public function test_a_refunded_charge_does_not_satisfy_the_payment_gate(): void
    {
        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();

        SubscriptionPayment::query()->firstOrFail()->forceFill([
            'status' => 'paid', 'paid_at' => now(), 'refunded_at' => now(),
        ])->save();

        $request->forceFill(['state' => AccountState::PaymentPending->value])->save();

        $this->expectException(RuntimeException::class);

        app(ProvisionWorkspace::class)->execute($request->refresh());
    }

    // ── Duplicate charges ─────────────────────────────────────────────────────────────────────

    /**
     * Asking for a checkout twice opens ONE charge.
     *
     * The idempotency key is derived from what is being charged, not from when it was asked for, so a
     * double-submitted form, a retried request and an impatient customer all resolve to the same
     * payment — the contract's «منع تكرار الخصم».
     */
    public function test_a_repeated_checkout_never_opens_a_second_charge(): void
    {
        $request = $this->owing();
        $id = $request->getKey();

        $first = $this->postJson("/api/v1/auth/registration/{$id}/checkout", ['commitment_agreed' => true])->assertOk();
        $second = $this->postJson("/api/v1/auth/registration/{$id}/checkout", ['commitment_agreed' => true])->assertOk();
        $third = app(SubscriptionCheckout::class)->startTrial($request->refresh());

        $this->assertSame($first->json('data.payment.id'), $second->json('data.payment.id'));
        $this->assertSame($first->json('data.payment.id'), (string) $third['payment']->getKey());
        $this->assertSame(1, SubscriptionPayment::query()->count());
    }

    // ── The shipped state ─────────────────────────────────────────────────────────────────────

    /**
     * With no credentials, both gateways report Awaiting Credentials and no money can move.
     *
     * This is what an install looks like today, and it is stated rather than implied: the interface
     * that reads this endpoint shows a provider that cannot work as one that cannot work.
     */
    public function test_both_gateways_report_awaiting_credentials_when_unconfigured(): void
    {
        config([
            'services.moyasar.secret_key' => null,
            'services.stripe.secret_key' => null,
            // The sandbox is a separate question, asserted below. Switched off here so this test is
            // about the two real gateways and not about which local aids happen to be enabled.
            'subscriptions.sandbox_secret' => '',
        ]);

        $providers = collect($this->getJson('/api/v1/payments/providers')->assertOk()->json('data.providers'));

        $real = $providers->whereIn('provider', ['moyasar', 'stripe']);
        $this->assertSame(['moyasar', 'stripe'], $real->pluck('provider')->values()->all());
        $this->assertTrue($real->every(fn (array $p) => $p['status'] === 'awaiting_credentials'));
        $this->assertTrue($real->every(fn (array $p) => $p['available'] === false));
        // Moyasar is the official, primary gateway.
        $this->assertTrue($providers->firstWhere('provider', 'moyasar')['is_default']);

        // The sandbox is off, so it reports what every unconfigured adapter reports.
        $this->assertSame('awaiting_credentials', $providers->firstWhere('provider', 'sandbox')['status']);
    }

    /**
     * The sandbox is reported as the sandbox — never as Live (PAY-SANDBOX-001).
     *
     * The contract requires Sandbox, Awaiting Credentials and Live to be told apart, and this is the
     * endpoint every interface reads to tell them apart. `live` here would put a working Pay button
     * under a label claiming real money moves.
     */
    public function test_the_sandbox_gateway_is_never_reported_as_live(): void
    {
        config(['subscriptions.sandbox_secret' => 'local-sandbox-secret']);

        $providers = collect($this->getJson('/api/v1/payments/providers')->assertOk()->json('data.providers'));
        $sandbox = $providers->firstWhere('provider', 'sandbox');

        $this->assertSame('sandbox', $sandbox['status']);
        $this->assertTrue($sandbox['available'], 'it can take a payment — it just is not real money');
        $this->assertNotSame('live', $sandbox['status']);
    }

    /** …and in production it is not configured, whatever the configuration says. */
    public function test_the_sandbox_gateway_is_inert_in_production(): void
    {
        config(['subscriptions.sandbox_secret' => 'local-sandbox-secret']);
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->assertFalse(app(SandboxPaymentProvider::class)->isConfigured());
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    /** An unconfigured checkout says so, and does not pretend a customer is on their way to pay. */
    public function test_a_checkout_with_no_gateway_is_awaiting_credentials_not_pending(): void
    {
        $request = $this->owing();

        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk()
            ->assertJsonPath('data.status', 'awaiting_credentials')
            ->assertJsonPath('data.checkout_url', null);

        $this->assertSame('awaiting_credentials', SubscriptionPayment::query()->firstOrFail()->status);
        $this->assertNothingGranted();
    }
}
