<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * PAY-RECONCILE-001 — a payment Moyasar took is settled even when its webhook never arrived.
 *
 * Moyasar retries a refused or unanswered webhook five more times over about 3h40m and then drops it (docs.moyasar.com, webhook
 * reference). A customer who paid during an outage, or while the webhook token was mistyped, stayed «pending» for ever: nothing
 * asked the gateway again. The sweep asks Moyasar for the invoice the checkout opened, and only a `paid` invoice goes on — through
 * the SAME settlement as a webhook, which re-reads the payment and checks its reference, amount and currency. It opens nothing,
 * charges nothing and never trusts its own question as proof.
 */
final class PaymentReconcileTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    /** What Moyasar answers for the invoice and the payment; read at request time. */
    private array $invoice = ['id' => 'inv_1', 'status' => 'initiated', 'payments' => []];

    private ?array $paymentBody = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->offerLaunchCatalogue();
        config(['accounts.registration.plans.growth' => ['requires_payment' => true]]);
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.publishable_key' => 'pk_test_x', 'services.moyasar.webhook_token' => 'tok', 'subscriptions.default' => 'moyasar']);

        Http::fake([
            'api.moyasar.com/v1/invoices/*' => fn () => Http::response($this->invoice, 200),
            'api.moyasar.com/v1/invoices' => fn () => Http::response(['id' => 'inv_1', 'url' => 'https://checkout.moyasar.com/invoices/inv_1', 'status' => 'initiated'], 201),
            'api.moyasar.com/v1/payments/*' => fn () => $this->paymentBody === null ? Http::response(null, 404) : Http::response($this->paymentBody, 200),
        ]);
    }

    private function pendingPayment(): array
    {
        $res = $this->apply(['email' => 'owing@a.test', 'plan_code' => 'growth', 'billing_interval' => 'monthly']);
        $this->postJson('/api/v1/auth/registration/verify-email', ['token' => $this->verificationTokenFrom($res)])->assertOk();
        $request = RegistrationRequest::query()->whereRaw('lower(email) = ?', ['owing@a.test'])->firstOrFail();
        $this->verifyMobileFor($request);
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->sole();
        $this->assertSame('inv_1', $payment->provider_session_id);
        $this->assertSame('pending', $payment->status);
        // Old enough to be past the webhook's own window.
        $payment->forceFill(['created_at' => Carbon::now()->subHours(5)])->save();

        return [$request->refresh(), $payment->refresh()];
    }

    private function moyasarSaysPaid(SubscriptionPayment $payment, ?int $minorUnits = null): void
    {
        $this->invoice = ['id' => 'inv_1', 'status' => 'paid', 'payments' => [['id' => 'pay_9', 'status' => 'paid']]];
        $this->paymentBody = [
            'id' => 'pay_9', 'status' => 'paid', 'amount' => $minorUnits ?? (int) round(((float) $payment->amount) * 100),
            'currency' => $payment->currency, 'metadata' => ['reference' => $payment->idempotency_key],
        ];
    }

    public function test_a_paid_invoice_whose_webhook_never_came_is_settled_once(): void
    {
        [$request, $payment] = $this->pendingPayment();
        $this->moyasarSaysPaid($payment);

        $this->artisan('subscriptions:reconcile-payments')->assertSuccessful();

        $this->assertSame('paid', $payment->refresh()->status);
        $this->assertSame('pay_9', $payment->provider_payment_id);
        $this->assertSame(AccountState::Active, $request->refresh()->state);

        $this->artisan('subscriptions:reconcile-payments')->assertSuccessful();
        $this->assertSame(1, Tenant::count(), 'a second sweep mints nothing');
    }

    public function test_an_unpaid_invoice_is_left_alone(): void
    {
        [$request, $payment] = $this->pendingPayment();

        $this->artisan('subscriptions:reconcile-payments')->assertSuccessful();

        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->refresh()->state);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/v1/payments/'));
    }

    public function test_a_paid_invoice_whose_payment_does_not_match_settles_nothing(): void
    {
        [$request, $payment] = $this->pendingPayment();
        $this->moyasarSaysPaid($payment, minorUnits: 100);

        $this->artisan('subscriptions:reconcile-payments')->assertSuccessful();

        $this->assertNotSame('paid', $payment->refresh()->status);
        $this->assertSame(0, Tenant::count());
    }

    public function test_a_recent_payment_is_left_to_its_webhook(): void
    {
        [, $payment] = $this->pendingPayment();
        $payment->forceFill(['created_at' => Carbon::now()->subMinutes(3)])->save();
        $this->moyasarSaysPaid($payment);

        $this->artisan('subscriptions:reconcile-payments')->assertSuccessful();

        $this->assertSame('pending', $payment->refresh()->status);
    }

    public function test_nothing_is_asked_while_moyasar_is_not_configured(): void
    {
        [, $payment] = $this->pendingPayment();
        config(['services.moyasar.secret_key' => null]);
        $this->moyasarSaysPaid($payment);

        $this->artisan('subscriptions:reconcile-payments')->assertSuccessful();

        $this->assertSame('pending', $payment->refresh()->status);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/v1/invoices/inv_1'));
    }
}
