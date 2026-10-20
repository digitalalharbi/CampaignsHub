<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Services\BillingService;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PAY-INVOICE-001 — an agency's own invoices through the real Moyasar and Tap adapters.
 *
 * The invoice path sent adapters only `idempotency_key`, while they read `reference`, `description` and
 * `return_url`; Moyasar's paid event carries the PAYMENT id (not the invoice id the session was stored
 * under) so it could not be matched; and unlike the subscription path nothing re-asked the gateway
 * before settling. These tests hold the three corrections with the gateways faked.
 */
final class ClientInvoiceGatewayPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        config([
            'services.moyasar.secret_key' => 'sk_test_m', 'services.moyasar.webhook_token' => 'tok',
            'services.tap.secret_key' => 'sk_test_t',
        ]);
    }

    private function invoice(float $total = 1000): Invoice
    {
        $billing = app(BillingService::class);

        return $billing->approveQuote($billing->createQuote([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'subtotal' => $total, 'total' => $total,
        ]));
    }

    private function moyasarEvent(Payment $p, string $status = 'paid', int $amount = 100000, string $payId = 'pay_M1'): string
    {
        return (string) json_encode([
            'id' => 'evt_'.$payId.$status, 'type' => 'payment_'.$status, 'secret_token' => 'tok',
            'data' => ['id' => $payId, 'status' => $status, 'amount' => $amount, 'currency' => 'SAR', 'metadata' => ['reference' => $p->idempotency_key]],
        ]);
    }

    private function gatewayAnswers(string $status, int $amount, ?string $reference): void
    {
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response(['id' => 'pay_M1', 'status' => $status, 'amount' => $amount, 'currency' => 'SAR', 'metadata' => $reference === null ? [] : ['reference' => $reference]], 200)]);
    }

    private function open(string $provider = 'moyasar'): Payment
    {
        Http::fake([
            'api.moyasar.com/v1/invoices' => Http::response(['id' => 'inv_M1', 'url' => 'https://checkout.moyasar.com/invoices/inv_M1'], 201),
            'api.tap.company/v2/charges' => Http::response(['id' => 'chg_T1', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://checkout.payments.tap.company?token=t']], 200),
        ]);

        $invoice = $this->invoice();

        return app(BillingService::class)->startPayment($invoice, ['provider' => $provider, 'payload' => ['customer' => ['name' => 'Client Person', 'email' => 'client@example.test']]]);
    }

    public function test_the_session_request_carries_the_reference_the_adapters_read(): void
    {
        $payment = $this->open();

        Http::assertSent(function (Request $r) use ($payment): bool {
            $b = $r->data();

            return $r->url() === 'https://api.moyasar.com/v1/invoices'
                && $b['metadata']['reference'] === $payment->idempotency_key
                && str_starts_with((string) $b['description'], 'Invoice ')
                && ($b['success_url'] ?? '') !== '';
        });
    }

    public function test_a_paid_moyasar_event_is_matched_by_reference_and_settled_after_the_gateway_confirms(): void
    {
        $payment = $this->open();
        $this->assertSame('inv_M1', $payment->provider_session_id, 'stored under the INVOICE id, which is not the payment id the event carries');

        $this->gatewayAnswers('paid', 100000, $payment->idempotency_key);
        app(BillingService::class)->handleWebhook('moyasar', $this->moyasarEvent($payment), []);

        $this->assertSame('paid', $payment->refresh()->status);
        $this->assertSame('paid', $payment->invoice()->withoutGlobalScopes()->first()->status);
    }

    public function test_a_paid_event_the_gateway_calls_pending_settles_nothing(): void
    {
        $payment = $this->open();
        $this->gatewayAnswers('initiated', 100000, $payment->idempotency_key);

        app(BillingService::class)->handleWebhook('moyasar', $this->moyasarEvent($payment), []);

        $this->assertNotSame('paid', $payment->refresh()->status);
    }

    public function test_a_charge_for_less_than_the_invoice_is_failed_not_settled(): void
    {
        $payment = $this->open();
        $this->gatewayAnswers('paid', 100, $payment->idempotency_key);

        app(BillingService::class)->handleWebhook('moyasar', $this->moyasarEvent($payment, 'paid', 100), []);

        $payment->refresh();
        $this->assertSame('failed', $payment->status);
        $this->assertSame('issued', $payment->invoice()->withoutGlobalScopes()->first()->status);
    }

    public function test_a_charge_attributed_to_another_reference_settles_nothing(): void
    {
        $payment = $this->open();
        $this->gatewayAnswers('paid', 100000, 'someone-elses-reference');

        app(BillingService::class)->handleWebhook('moyasar', $this->moyasarEvent($payment), []);

        $this->assertNotSame('paid', $payment->refresh()->status);
    }

    public function test_an_unreachable_gateway_settles_nothing(): void
    {
        $payment = $this->open();
        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response(null, 500)]);

        app(BillingService::class)->handleWebhook('moyasar', $this->moyasarEvent($payment), []);

        $this->assertNotSame('paid', $payment->refresh()->status);
    }

    public function test_tap_opens_an_invoice_charge_and_settles_only_after_tap_confirms(): void
    {
        $payment = $this->open('tap');
        $this->assertSame('chg_T1', $payment->provider_session_id);

        $charge = [
            'id' => 'chg_T1', 'status' => 'CAPTURED', 'amount' => 1000.0, 'currency' => (string) $payment->currency,
            'reference' => ['gateway' => 'g', 'payment' => 'p', 'transaction' => $payment->idempotency_key], 'transaction' => ['created' => '1'],
            'metadata' => ['reference' => $payment->idempotency_key],
        ];
        $sign = hash_hmac('sha256', 'x_idchg_T1x_amount1000.00x_currency'.$payment->currency.'x_gateway_referencegx_payment_referencepx_statusCAPTURED'.'x_created1', 'sk_test_t');
        Http::fake(['api.tap.company/v2/charges/*' => Http::response($charge, 200)]);

        $event = app(BillingService::class)->handleWebhook('tap', (string) json_encode($charge), ['hashstring' => $sign]);

        $this->assertTrue($event->verified);
        $this->assertSame('paid', $payment->refresh()->status);
    }

    public function test_an_unsigned_tap_event_moves_nothing(): void
    {
        $payment = $this->open('tap');
        $charge = ['id' => 'chg_T1', 'status' => 'CAPTURED', 'amount' => 1000.0, 'currency' => (string) $payment->currency, 'reference' => [], 'transaction' => ['created' => '1']];

        $event = app(BillingService::class)->handleWebhook('tap', (string) json_encode($charge), ['hashstring' => 'forged']);

        $this->assertFalse($event->verified);
        $this->assertNotSame('paid', $payment->refresh()->status);
    }
}
