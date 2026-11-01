<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Billing\Providers\TapPaymentProvider;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * PAY-TAP-001 — Tap Payments behind the same port: nothing is claimed beyond what is tested here with
 * the gateway faked, and no Tap account was reached. The state without credentials is
 * `awaiting_credentials`; a webhook counts only with a valid `hashstring`; and an account activates only
 * after Tap itself, asked backend-to-backend, says the charge was captured for the quoted amount.
 */
final class TapPaymentTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->offerLaunchCatalogue();

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

    private const SECRET = 'sk_test_tapSecret';

    private function configureTap(): void
    {
        config(['subscriptions.default' => 'tap', 'services.tap.secret_key' => self::SECRET]);
    }

    private function sign(array $charge): string
    {
        $currency = strtoupper((string) $charge['currency']);
        $amount = number_format((float) $charge['amount'], in_array($currency, ['BHD', 'KWD', 'OMR', 'JOD'], true) ? 3 : 2, '.', '');
        $string = 'x_id'.$charge['id'].'x_amount'.$amount.'x_currency'.$currency
            .'x_gateway_reference'.($charge['reference']['gateway'] ?? '').'x_payment_reference'.($charge['reference']['payment'] ?? '')
            .'x_status'.$charge['status'].'x_created'.($charge['transaction']['created'] ?? '');

        return hash_hmac('sha256', $string, self::SECRET);
    }

    private function charge(SubscriptionPayment $payment, string $status = 'CAPTURED', ?string $amount = null, ?string $currency = null): array
    {
        return [
            'id' => 'chg_TS01', 'status' => $status,
            'amount' => (float) ($amount ?? $payment->amount), 'currency' => $currency ?? (string) $payment->currency,
            'reference' => ['gateway' => 'gw_1', 'payment' => 'pay_1', 'transaction' => (string) $payment->idempotency_key],
            'transaction' => ['created' => '1700000000000'],
            'metadata' => ['reference' => (string) $payment->idempotency_key],
        ];
    }

    private function openCheckout(): array
    {
        $this->configureTap();
        Http::fake(['api.tap.company/v2/charges' => Http::response(['id' => 'chg_TS01', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://checkout.payments.tap.company?mode=page&token=abc']], 200)]);

        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();

        return [$request, SubscriptionPayment::query()->firstOrFail()];
    }

    public function test_without_a_secret_key_nothing_opens_and_nothing_verifies(): void
    {
        $tap = new TapPaymentProvider;

        $this->assertFalse($tap->isConfigured());
        $this->assertSame('awaiting_credentials', $tap->createSession(['amount' => '9.00', 'currency' => 'SAR'])['status']);
        $this->assertSame(['verified' => false], $tap->verifyWebhook('{"id":"chg_1"}', ['hashstring' => 'x']));
        $this->assertNull($tap->fetchPayment('chg_1'));
    }

    public function test_opening_a_checkout_sends_the_customer_the_reference_and_returns_the_hosted_page(): void
    {
        [, $payment] = $this->openCheckout();

        $this->assertSame('chg_TS01', $payment->provider_session_id);
        $this->assertStringStartsWith('https://checkout.payments.tap.company', (string) $payment->checkout_url);
        Http::assertSent(function ($request) use ($payment) {
            $b = $request->data();

            return $request->url() === 'https://api.tap.company/v2/charges'
                && $request->hasHeader('Authorization', 'Bearer '.self::SECRET)
                && $b['customer']['email'] === 'owing@a.test'
                && $b['customer']['first_name'] !== ''
                && $b['metadata']['reference'] === $payment->idempotency_key
                && $b['currency'] === $payment->currency
                && (float) $b['amount'] === (float) $payment->amount;
        });
    }

    public function test_a_charge_with_no_addressable_customer_is_refused_rather_than_invented(): void
    {
        $this->configureTap();
        Http::fake();

        $out = (new TapPaymentProvider)->createSession(['amount' => '9.00', 'currency' => 'SAR', 'customer' => ['name' => 'X', 'email' => '']]);

        $this->assertSame('failed', $out['status']);
        Http::assertNothingSent();
    }

    public function test_a_webhook_without_a_valid_hashstring_is_not_verified(): void
    {
        $this->configureTap();
        $tap = new TapPaymentProvider;
        $charge = ['id' => 'chg_1', 'status' => 'CAPTURED', 'amount' => 9, 'currency' => 'SAR', 'reference' => [], 'transaction' => ['created' => '1']];
        $body = json_encode($charge);

        $this->assertFalse($tap->verifyWebhook($body, [])['verified'], 'no header');
        $this->assertFalse($tap->verifyWebhook($body, ['hashstring' => 'deadbeef'])['verified'], 'wrong signature');

        // A body altered after signing: the amount changed, the signature did not.
        $signed = $this->sign($charge);
        $this->assertFalse($tap->verifyWebhook(json_encode(['amount' => 1] + $charge), ['Hashstring' => $signed])['verified']);

        $ok = $tap->verifyWebhook($body, ['Hashstring' => $signed]);
        $this->assertTrue($ok['verified']);
        $this->assertSame('paid', $ok['status']);
        $this->assertSame('9.00', $ok['amount']);
        $this->assertSame('chg_1:CAPTURED', $ok['event_id']);
        $this->assertFalse($tap->confirmsPayloadIntegrity(), 'the body is re-read from Tap before anything settles');
    }

    public function test_three_decimal_currencies_are_signed_and_read_at_three_decimals(): void
    {
        $this->configureTap();
        $charge = ['id' => 'chg_k', 'status' => 'CAPTURED', 'amount' => 2.5, 'currency' => 'KWD', 'reference' => [], 'transaction' => ['created' => '1']];

        $out = (new TapPaymentProvider)->verifyWebhook(json_encode($charge), ['hashstring' => $this->sign($charge)]);

        $this->assertTrue($out['verified']);
        $this->assertSame('2.500', $out['amount']);
    }

    public function test_statuses_map_to_the_ports_vocabulary(): void
    {
        $this->configureTap();
        $tap = new TapPaymentProvider;
        $map = static fn (string $s) => $tap->verifyWebhook(json_encode($c = ['id' => 'c', 'status' => $s, 'amount' => 1, 'currency' => 'SAR', 'reference' => [], 'transaction' => ['created' => '1']]), ['hashstring' => hash_hmac('sha256', 'x_idcx_amount1.00x_currencySARx_gateway_referencex_payment_referencex_status'.$s.'x_created1', self::SECRET)])['status'];

        $this->assertSame('paid', $map('CAPTURED'));
        foreach (['FAILED', 'DECLINED', 'CANCELLED', 'ABANDONED', 'TIMEDOUT'] as $s) {
            $this->assertSame('failed', $map($s), $s);
        }
        $this->assertSame('pending', $map('INITIATED'));
        $this->assertSame('processing', $map('IN_PROGRESS'));
    }

    public function test_a_captured_charge_tap_confirms_activates_once_and_a_replay_does_nothing(): void
    {
        [$request, $payment] = $this->openCheckout();
        $charge = $this->charge($payment);
        Http::fake(['api.tap.company/v2/charges/*' => Http::response($charge, 200)]);

        $headers = ['hashstring' => $this->sign($charge)];
        $this->postJson('/api/v1/payments/webhook/tap', $charge, $headers)->assertOk()->assertJsonPath('data.verified', true);

        $this->assertSame('paid', $payment->refresh()->status);
        $this->assertSame(AccountState::Active, $request->refresh()->state);

        $this->postJson('/api/v1/payments/webhook/tap', $charge, $headers)->assertOk();
        $this->assertSame(1, Tenant::count(), 'a replay must not mint a second workspace');
    }

    public function test_a_signed_webhook_does_not_settle_when_tap_says_otherwise(): void
    {
        [$request, $payment] = $this->openCheckout();
        $claimed = $this->charge($payment);
        // What Tap itself reports when asked: still not captured.
        Http::fake(['api.tap.company/v2/charges/*' => Http::response($this->charge($payment, 'INITIATED'), 200)]);

        $this->postJson('/api/v1/payments/webhook/tap', $claimed, ['hashstring' => $this->sign($claimed)])->assertOk();

        $this->assertNotSame('paid', $payment->refresh()->status);
        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->refresh()->state);
        $this->assertNothingGranted();
    }

    public function test_a_capture_for_less_than_the_quoted_amount_does_not_settle(): void
    {
        [$request, $payment] = $this->openCheckout();
        $short = $this->charge($payment, 'CAPTURED', '1.00');
        Http::fake(['api.tap.company/v2/charges/*' => Http::response($short, 200)]);

        $this->postJson('/api/v1/payments/webhook/tap', $short, ['hashstring' => $this->sign($short)])->assertOk();

        $this->assertSame('failed', $payment->refresh()->status);
        $this->assertNothingGranted();
    }

    public function test_an_unreachable_tap_settles_nothing(): void
    {
        [$request, $payment] = $this->openCheckout();
        $charge = $this->charge($payment);
        Http::fake(['api.tap.company/v2/charges/*' => Http::response(null, 500)]);

        $this->postJson('/api/v1/payments/webhook/tap', $charge, ['hashstring' => $this->sign($charge)])->assertOk();

        $this->assertNotSame('paid', $payment->refresh()->status);
        $this->assertNothingGranted();
    }

    public function test_saved_card_renewal_is_honestly_unsupported(): void
    {
        $tap = new TapPaymentProvider;

        $this->assertFalse($tap->supportsUnattendedCharge());
        $this->assertSame('unsupported', $tap->chargeStoredMethod('tok', ['amount' => '9.00'])['status']);
        $this->assertNull($tap->savedPaymentMethodFrom(['id' => 'chg_1']));
    }

    private function assertNothingGranted(): void
    {
        $this->assertSame(0, Tenant::count(), 'no workspace may exist');
        $this->assertSame(0, User::count(), 'no sign-in account may exist');
        $this->assertSame(0, Membership::count(), 'no membership may exist');
    }
}
