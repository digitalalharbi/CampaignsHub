<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Actions\ProvisionWorkspace;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\AdvanceRegistration;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->state, 'the payment gate must be holding it');

        return $request;
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
        $this->postJson("/api/v1/auth/registration/{$id}/checkout")->assertOk();

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
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout")->assertOk();

        $payment = SubscriptionPayment::query()->firstOrFail();

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_forged', 'type' => 'payment_paid',
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'amount' => 900, 'currency' => 'SAR',
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
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout")->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_wrong_secret', 'type' => 'payment_paid', 'secret_token' => 'not-the-real-token',
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'amount' => 900, 'currency' => 'SAR',
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
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout")->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();

        $event = [
            'id' => 'evt_real', 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'amount' => 900, 'currency' => 'SAR',
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
     * A verified event that says a different amount settles nothing.
     *
     * Verification proves the gateway sent it, not that the gateway charged what we asked for.
     */
    public function test_a_verified_event_for_the_wrong_amount_does_not_settle(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'shared-secret']);

        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout")->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();

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
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout")->assertOk();

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

        $first = $this->postJson("/api/v1/auth/registration/{$id}/checkout")->assertOk();
        $second = $this->postJson("/api/v1/auth/registration/{$id}/checkout")->assertOk();
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
        config(['services.moyasar.secret_key' => null, 'services.stripe.secret_key' => null]);

        $providers = collect($this->getJson('/api/v1/payments/providers')->assertOk()->json('data.providers'));

        $this->assertSame(['moyasar', 'stripe'], $providers->pluck('provider')->all());
        $this->assertTrue($providers->every(fn (array $p) => $p['status'] === 'awaiting_credentials'));
        $this->assertTrue($providers->every(fn (array $p) => $p['available'] === false));
        // Moyasar is the official, primary gateway.
        $this->assertTrue($providers->firstWhere('provider', 'moyasar')['is_default']);
    }

    /** An unconfigured checkout says so, and does not pretend a customer is on their way to pay. */
    public function test_a_checkout_with_no_gateway_is_awaiting_credentials_not_pending(): void
    {
        $request = $this->owing();

        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout")->assertOk()
            ->assertJsonPath('data.status', 'awaiting_credentials')
            ->assertJsonPath('data.checkout_url', null);

        $this->assertSame('awaiting_credentials', SubscriptionPayment::query()->firstOrFail()->status);
        $this->assertNothingGranted();
    }
}
