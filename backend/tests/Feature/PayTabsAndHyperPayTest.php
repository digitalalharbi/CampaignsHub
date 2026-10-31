<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Billing\Gateways\PaymentGatewaySettings;
use App\Domains\Billing\Providers\HyperPayPaymentProvider;
use App\Domains\Billing\Providers\PayTabsPaymentProvider;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Legal\Models\PlatformSetting;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * PAY-GATEWAY-CONSOLE-001 — the PayTabs and HyperPay adapters, against their documented APIs with the gateways faked.
 *
 * Each: a checkout opens at the gateway's hosted page; a callback that is not the gateway's (bad signature, failed decryption) is
 * refused; a genuine one settles only when the gateway's own server-to-server answer confirms the amount, currency and reference.
 * These are mocks of the documented contracts: they prove the adapter follows them, not that a live account accepts it.
 */
final class PayTabsAndHyperPayTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    private const SERVER_KEY = 'paytabs-server-key-for-tests';

    private const PROFILE = '123456';

    private const HP_TOKEN = 'hyperpay-access-token-for-tests';

    private const HP_ENTITY = '8a8294174b7ecb28014b9699220015ca';

    private const HP_SECRET = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    private array $answer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        config(['accounts.registration.plans.growth' => ['requires_payment' => true], 'ad_platforms.redirect_base' => 'https://api.example.test']);

        Http::fake(fn (HttpRequest $r) => Http::response(...($this->answer[$this->route($r)] ?? [[], 404])));
    }

    private function route(HttpRequest $r): string
    {
        return match (true) {
            str_ends_with($r->url(), '/payment/request') => 'pt.request',
            str_ends_with($r->url(), '/payment/query') => 'pt.query',
            str_contains($r->url(), '/v1/checkouts') => 'hp.checkout',
            str_contains($r->url(), '/v1/query/') => 'hp.query',
            default => 'other',
        };
    }

    private function useGateway(string $gateway, array $credentials, string $mode = 'test'): void
    {
        ProviderConfiguration::query()->create([
            'provider' => PaymentGatewaySettings::storageKey($gateway), 'credentials' => $credentials, 'environment' => $mode, 'enabled' => true,
        ]);
        PlatformSetting::current()->forceFill(['payment_primary_gateway' => $gateway])->save();
    }

    private function owing(): RegistrationRequest
    {
        $res = $this->apply(['email' => 'owing@a.test', 'plan_code' => 'growth', 'billing_interval' => 'monthly']);
        $this->postJson('/api/v1/auth/registration/verify-email', ['token' => $this->verificationTokenFrom($res)])->assertOk();
        $request = RegistrationRequest::query()->whereRaw('lower(email) = ?', ['owing@a.test'])->firstOrFail();
        $this->verifyMobileFor($request);

        return $request->refresh();
    }

    // ── PayTabs ─────────────────────────────────────────────────────────────────────────────────────

    public function test_paytabs_opens_its_hosted_page_with_our_reference_as_the_cart_id(): void
    {
        $this->useGateway('paytabs', ['profile_id' => self::PROFILE, 'server_key' => self::SERVER_KEY]);
        $this->answer['pt.request'] = [['tran_ref' => 'TST2430000000001', 'redirect_url' => 'https://secure.paytabs.sa/payment/page/abc'], 200];
        $request = $this->owing();

        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://secure.paytabs.sa/payment/page/abc');

        $payment = SubscriptionPayment::query()->sole();
        $this->assertSame('paytabs', $payment->provider);
        $this->assertSame('TST2430000000001', $payment->provider_session_id);
        Http::assertSent(function (HttpRequest $r) use ($payment) {
            return str_ends_with($r->url(), '/payment/request')
                && $r->header('authorization')[0] === self::SERVER_KEY
                && $r['cart_id'] === $payment->idempotency_key
                && $r['profile_id'] === (int) self::PROFILE
                && $r['callback'] === 'https://api.example.test/api/v1/payments/webhook/paytabs';
        });
    }

    public function test_a_paytabs_callback_with_a_bad_signature_is_refused(): void
    {
        $this->useGateway('paytabs', ['profile_id' => self::PROFILE, 'server_key' => self::SERVER_KEY]);
        $body = json_encode(['tran_ref' => 'T1', 'cart_id' => 'x', 'payment_result' => ['response_status' => 'A']]);

        $this->call('POST', '/api/v1/payments/webhook/paytabs', [], [], [], ['HTTP_SIGNATURE' => 'deadbeef', 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertUnauthorized();
    }

    public function test_a_signed_paytabs_callback_settles_only_on_paytabs_own_query(): void
    {
        $this->useGateway('paytabs', ['profile_id' => self::PROFILE, 'server_key' => self::SERVER_KEY]);
        $this->answer['pt.request'] = [['tran_ref' => 'TST2430000000001', 'redirect_url' => 'https://secure.paytabs.sa/payment/page/abc'], 200];
        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->sole();

        $callback = json_encode([
            'tran_ref' => 'TST2430000000001', 'tran_type' => 'Sale', 'cart_id' => $payment->idempotency_key,
            'cart_currency' => $payment->currency, 'cart_amount' => (string) $payment->amount,
            'payment_result' => ['response_status' => 'A', 'response_message' => 'Authorised'],
        ]);
        $signed = ['HTTP_SIGNATURE' => hash_hmac('sha256', (string) $callback, self::SERVER_KEY), 'CONTENT_TYPE' => 'application/json'];

        // PayTabs' own record says it is still pending: nothing settles on the callback's word.
        $this->answer['pt.query'] = [['tran_ref' => 'TST2430000000001', 'tran_type' => 'Sale', 'cart_id' => $payment->idempotency_key, 'cart_currency' => $payment->currency, 'cart_amount' => (string) $payment->amount, 'payment_result' => ['response_status' => 'P']], 200];
        $this->call('POST', '/api/v1/payments/webhook/paytabs', [], [], [], $signed, $callback)->assertOk();
        $this->assertNotSame('paid', $payment->refresh()->status);

        // PayTabs confirms later. Its retry of the same callback is a duplicate event, so the reconciliation sweep is what asks
        // PayTabs again and settles it — once.
        $this->answer['pt.query'][0]['payment_result']['response_status'] = 'A';
        $this->call('POST', '/api/v1/payments/webhook/paytabs', [], [], [], $signed, $callback)->assertOk();
        $payment->forceFill(['created_at' => now()->subHour()])->save();
        $this->artisan('subscriptions:reconcile-payments')->assertSuccessful();
        $this->artisan('subscriptions:reconcile-payments')->assertSuccessful();

        $this->assertSame('paid', $payment->refresh()->status);
        $this->assertSame(AccountState::Active, $request->refresh()->state);
        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_a_paytabs_charge_paytabs_reports_short_does_not_settle(): void
    {
        $this->useGateway('paytabs', ['profile_id' => self::PROFILE, 'server_key' => self::SERVER_KEY]);
        $provider = app(PayTabsPaymentProvider::class);
        $this->answer['pt.query'] = [['tran_ref' => 'T9', 'tran_type' => 'Sale', 'cart_id' => 'ref', 'cart_currency' => 'SAR', 'cart_amount' => '1.00', 'payment_result' => ['response_status' => 'A']], 200];

        $this->assertSame(['status' => 'paid', 'amount' => '1.00', 'currency' => 'SAR', 'reference' => 'ref'], $provider->fetchPayment('T9'));
    }

    public function test_paytabs_is_not_configured_without_a_numeric_profile(): void
    {
        $this->useGateway('paytabs', ['profile_id' => 'abc', 'server_key' => self::SERVER_KEY]);

        $this->assertFalse(app(PayTabsPaymentProvider::class)->isConfigured());
    }

    // ── HyperPay ────────────────────────────────────────────────────────────────────────────────────

    private function hyperpay(string $mode = 'test'): void
    {
        $this->useGateway('hyperpay', ['access_token' => self::HP_TOKEN, 'entity_id' => self::HP_ENTITY, 'webhook_secret' => self::HP_SECRET], $mode);
    }

    /** @return array{0: string, 1: array<string, string>} body and headers, as HyperPay sends them */
    private function encrypted(array $event): array
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt((string) json_encode($event), 'aes-256-gcm', (string) hex2bin(self::HP_SECRET), OPENSSL_RAW_DATA, $iv, $tag);

        return [bin2hex((string) $cipher), ['HTTP_X_INITIALIZATION_VECTOR' => bin2hex($iv), 'HTTP_X_AUTHENTICATION_TAG' => bin2hex($tag), 'CONTENT_TYPE' => 'text/plain']];
    }

    public function test_hyperpay_opens_a_checkout_and_hands_the_customer_a_signed_widget_page_on_the_right_host(): void
    {
        $this->hyperpay('live');
        $this->answer['hp.checkout'] = [['id' => 'CHK.1234-abcd', 'result' => ['code' => '000.200.100', 'description' => 'successfully created checkout'], 'integrity' => 'sha384-abc'], 200];
        $request = $this->owing();

        $url = (string) $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk()->json('data.checkout_url');

        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://eu-prod.oppwa.com/v1/checkouts') && $r['entityId'] === self::HP_ENTITY && $r['paymentType'] === 'DB');
        $this->assertStringStartsWith('https://api.example.test/api/v1/payments/hyperpay/checkout?', $url);

        $page = $this->get(substr($url, strlen('https://api.example.test')))->assertOk();
        $this->assertStringContainsString('https://eu-prod.oppwa.com/v1/paymentWidgets.js?checkoutId=CHK.1234-abcd', (string) $page->getContent());
        $this->assertStringContainsString('class="paymentWidgets"', (string) $page->getContent());

        // A tampered page address is refused: the checkout id and the return address cannot be substituted.
        $this->get(str_replace('CHK.1234-abcd', 'CHK.9999-evil', substr($url, strlen('https://api.example.test'))))->assertForbidden();
    }

    public function test_a_hyperpay_webhook_that_does_not_decrypt_is_refused(): void
    {
        $this->hyperpay();
        [$body, $headers] = $this->encrypted(['type' => 'PAYMENT', 'payload' => ['id' => 'P1', 'result' => ['code' => '000.000.000']]]);
        $headers['HTTP_X_AUTHENTICATION_TAG'] = str_repeat('0', 32);

        $this->call('POST', '/api/v1/payments/webhook/hyperpay', [], [], [], $headers, $body)->assertUnauthorized();
    }

    public function test_a_decrypted_hyperpay_payment_settles_only_on_hyperpays_own_query(): void
    {
        $this->hyperpay();
        $this->answer['hp.checkout'] = [['id' => 'CHK.1', 'result' => ['code' => '000.200.100']], 200];
        $request = $this->owing();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->sole();
        $paymentBody = ['id' => '8ac7a4a1-P', 'paymentType' => 'DB', 'amount' => (string) $payment->amount, 'currency' => $payment->currency, 'merchantTransactionId' => $payment->idempotency_key, 'result' => ['code' => '000.000.000']];

        [$body, $headers] = $this->encrypted(['type' => 'PAYMENT', 'payload' => $paymentBody]);

        // The query answers short: the encrypted body's figure is not taken.
        $this->answer['hp.query'] = [[...$paymentBody, 'amount' => '1.00'], 200];
        $this->call('POST', '/api/v1/payments/webhook/hyperpay', [], [], [], $headers, $body)->assertOk();
        $this->assertNotSame('paid', $payment->refresh()->status);
        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_hyperpay_result_codes_map_to_paid_pending_and_failed(): void
    {
        $this->hyperpay();
        $provider = app(HyperPayPaymentProvider::class);

        foreach (['000.000.000' => 'paid', '000.100.110' => 'paid', '000.200.000' => 'pending', '800.100.151' => 'failed'] as $code => $status) {
            $this->answer['hp.query'] = [['id' => 'P', 'paymentType' => 'DB', 'amount' => '10.00', 'currency' => 'SAR', 'result' => ['code' => $code]], 200];
            $this->assertSame($status, $provider->fetchPayment('P')['status'] ?? null, $code);
        }
    }
}
