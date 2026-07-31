<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentWebhookEvent;
use App\Domains\Billing\Models\Quote;
use App\Domains\Billing\Providers\PaymentProvider;
use App\Domains\Billing\Providers\PaymentProviderRegistry;
use App\Domains\Billing\Services\BillingService;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The billing domain is honest by construction: quotes become invoices on approval, opening a payment never
 * settles anything, and ONLY a verified webhook can mark a payment/invoice paid — exactly once, idempotently.
 */
final class BillingTest extends TestCase
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

        // Register the fake configured gateway used to prove the verified-webhook path.
        config()->set('billing.providers.fake', ConfiguredFakePaymentProvider::class);
    }

    private function billing(): BillingService
    {
        return app(BillingService::class);
    }

    /** An owner in the primary tenant, holding every permission (billing.manage included). */
    private function owner(): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create([
            'name' => 'Owner', 'email' => 'owner-'.uniqid().'@a.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $this->tenant, Portal::Agency);
        $user->assignRole($role);

        return $user;
    }

    private function draftQuote(float $total = 1000): Quote
    {
        return $this->billing()->createQuote([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->client->id,
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    public function test_default_provider_is_the_null_adapter_and_is_not_configured(): void
    {
        $this->assertSame('null', app(PaymentProviderRegistry::class)->defaultKey());
        $provider = app(PaymentProviderRegistry::class)->default();
        $this->assertFalse($provider->isConfigured());
        $this->assertSame('awaiting_credentials', $provider->createSession([])['status']);
        $this->assertFalse($provider->verifyWebhook('{}', [])['verified']);
    }

    public function test_quote_approval_generates_an_issued_invoice(): void
    {
        $quote = $this->draftQuote(1500);

        $invoice = $this->billing()->approveQuote($quote);

        $this->assertSame('approved', $quote->refresh()->status);
        $this->assertSame('issued', $invoice->status);
        $this->assertNotNull($invoice->issued_at);
        $this->assertSame($quote->id, $invoice->quote_id);
        $this->assertSame('1500.00', (string) $invoice->total);
        $this->assertSame($this->tenant->id, $invoice->tenant_id);

        // Approving again is idempotent — no second invoice.
        $again = $this->billing()->approveQuote($quote->refresh());
        $this->assertSame($invoice->id, $again->id);
        $this->assertSame(1, Invoice::count());
    }

    public function test_tax_treatment_derives_tax_and_carries_onto_the_invoice(): void
    {
        // basic_15 → tax is 15% of subtotal and total is recomputed, even if the caller sends a bogus tax.
        $quote = $this->billing()->createQuote([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->client->id,
            'subtotal' => 1000,
            'tax' => 999, // ignored — derived from the treatment
            'total' => 1, // ignored — recomputed
            'tax_treatment' => 'basic_15',
        ]);
        $this->assertSame('150.00', (string) $quote->tax);
        $this->assertSame('1150.00', (string) $quote->total);
        $this->assertSame('basic_15', $quote->tax_treatment);

        // The treatment (and derived amounts) carry onto the issued invoice.
        $invoice = $this->billing()->approveQuote($quote);
        $this->assertSame('basic_15', $invoice->tax_treatment);
        $this->assertSame('150.00', (string) $invoice->tax);

        // Non-standard treatments zero the tax.
        foreach (['zero_rated', 'exempt', 'out_of_scope'] as $t) {
            $q = $this->billing()->createQuote([
                'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id,
                'subtotal' => 1000, 'tax_treatment' => $t,
            ]);
            $this->assertSame('0.00', (string) $q->tax, "tax for {$t}");
            $this->assertSame('1000.00', (string) $q->total, "total for {$t}");
        }
    }

    public function test_store_quote_endpoint_rejects_an_unknown_tax_treatment(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/billing/quotes', ['subtotal' => 500, 'tax_treatment' => 'bogus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tax_treatment');

        $ok = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/billing/quotes', ['subtotal' => 500, 'tax_treatment' => 'basic_15'])
            ->assertCreated();
        $this->assertSame('75.00', (string) $ok->json('data.tax'));
        $this->assertSame('basic_15', $ok->json('data.tax_treatment'));
    }

    public function test_start_payment_with_null_provider_stays_pending_and_settles_nothing(): void
    {
        $invoice = $this->billing()->approveQuote($this->draftQuote(1000));

        $payment = $this->billing()->startPayment($invoice); // default = null provider

        $this->assertSame('pending', $payment->status);
        $this->assertSame('null', $payment->provider);
        $this->assertNull($payment->paid_at);

        // The invoice is untouched — nothing was marked paid.
        $this->assertSame('issued', $invoice->refresh()->status);
        $this->assertSame('0.00', (string) $invoice->amount_paid);

        // The provider round-trip was recorded honestly as awaiting_credentials.
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id, 'status' => 'awaiting_credentials',
        ]);
    }

    public function test_start_payment_is_idempotent_on_the_idempotency_key(): void
    {
        $invoice = $this->billing()->approveQuote($this->draftQuote(1000));

        $a = $this->billing()->startPayment($invoice, ['idempotency_key' => 'key-1']);
        $b = $this->billing()->startPayment($invoice, ['idempotency_key' => 'key-1']);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Payment::count());
    }

    public function test_a_verified_webhook_marks_paid_exactly_once(): void
    {
        $invoice = $this->billing()->approveQuote($this->draftQuote(1000));
        $payment = $this->billing()->startPayment($invoice, ['provider' => 'fake']);
        $this->assertSame('pending', $payment->status);

        $body = json_encode([
            'sig' => 'good', 'event_id' => 'evt_paid_1', 'type' => 'payment.paid',
            'payment_id' => $payment->provider_session_id, 'status' => 'paid',
        ]);

        $event = $this->billing()->handleWebhook('fake', (string) $body, []);
        $this->assertTrue($event->verified);

        $this->assertSame('paid', $payment->refresh()->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame('1000.00', (string) $invoice->amount_paid);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_a_re_delivered_webhook_event_is_idempotent(): void
    {
        $invoice = $this->billing()->approveQuote($this->draftQuote(1000));
        $payment = $this->billing()->startPayment($invoice, ['provider' => 'fake']);

        $body = (string) json_encode([
            'sig' => 'good', 'event_id' => 'evt_dup', 'type' => 'payment.paid',
            'payment_id' => $payment->provider_session_id, 'status' => 'paid',
        ]);

        $this->billing()->handleWebhook('fake', $body, []);
        $this->billing()->handleWebhook('fake', $body, []); // re-delivery
        $this->billing()->handleWebhook('fake', $body, []); // and again

        // Recorded once; invoice paid once — no double settlement.
        $this->assertSame(1, PaymentWebhookEvent::where('event_id', 'evt_dup')->count());
        $this->assertSame('1000.00', (string) $invoice->refresh()->amount_paid);
        $this->assertSame('paid', $invoice->status);
    }

    public function test_an_unverified_webhook_settles_nothing(): void
    {
        $invoice = $this->billing()->approveQuote($this->draftQuote(1000));
        $payment = $this->billing()->startPayment($invoice, ['provider' => 'fake']);

        $body = (string) json_encode([
            'sig' => 'bad', 'event_id' => 'evt_bad', 'type' => 'payment.paid',
            'payment_id' => $payment->provider_session_id, 'status' => 'paid',
        ]);

        $event = $this->billing()->handleWebhook('fake', $body, []);

        $this->assertFalse($event->verified);
        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertSame('issued', $invoice->refresh()->status);
        $this->assertSame('0.00', (string) $invoice->amount_paid);
    }

    public function test_public_webhook_endpoint_verifies_and_settles(): void
    {
        $invoice = $this->billing()->approveQuote($this->draftQuote(1000));
        $payment = $this->billing()->startPayment($invoice, ['provider' => 'fake']);

        $body = (string) json_encode([
            'sig' => 'good', 'event_id' => 'evt_http', 'type' => 'payment.paid',
            'payment_id' => $payment->provider_session_id, 'status' => 'paid',
        ]);

        // No auth, no tenant header — trust is established by the adapter verifying the payload.
        $this->postJson('/api/v1/billing/webhook/fake', [], ['Content-Type' => 'application/json'])
            ->assertOk();

        // Send the real signed body via a raw call.
        $this->call('POST', '/api/v1/billing/webhook/fake', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertSame('paid', $payment->refresh()->status);
        $this->assertSame('paid', $invoice->refresh()->status);
    }

    public function test_tenant_isolation_on_quotes_invoices_and_actions(): void
    {
        // Tenant A: a quote + its issued invoice.
        $quoteA = $this->draftQuote(1000);
        $invoiceA = $this->billing()->approveQuote($quoteA);

        // Tenant B with a full-permission owner.
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenantB->id);
        $role = Role::create(['tenant_id' => $tenantB->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $ownerB = User::create(['name' => 'O', 'email' => 'o@b.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($ownerB, $tenantB, Portal::Agency);
        $ownerB->assignRole($role);

        // B cannot see A's quote or invoice.
        $quotes = $this->actingAs($ownerB, 'sanctum')->getJson('/api/v1/billing/quotes')->assertOk();
        $this->assertNotContains($quoteA->number, array_column((array) $quotes->json('data'), 'number'));

        $invoices = $this->actingAs($ownerB, 'sanctum')->getJson('/api/v1/billing/invoices')->assertOk();
        $this->assertNotContains($invoiceA->number, array_column((array) $invoices->json('data'), 'number'));

        // B cannot approve A's quote or pay A's invoice — fail-closed route-model binding → 404.
        $this->actingAs($ownerB, 'sanctum')->postJson("/api/v1/billing/quotes/{$quoteA->id}/approve")->assertNotFound();
        $this->actingAs($ownerB, 'sanctum')->postJson("/api/v1/billing/invoices/{$invoiceA->id}/pay")->assertNotFound();

        // A's records are untouched.
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->assertSame('issued', $invoiceA->refresh()->status);
    }

    /**
     * FINANCE-001: the overview must consolidate what is really owed, and it must never present pending
     * or failed money as collected. Outstanding is derived from total - amount_paid, never a stored field.
     */
    public function test_finance_overview_consolidates_outstanding_aging_and_never_counts_pending_money(): void
    {
        $owner = $this->owner();
        $svc = $this->billing();

        // Invoice 1: 1000 total, 400 paid, due 45 days ago → 600 outstanding in the 31–60 bucket.
        $q1 = $this->draftQuote(1000);
        $svc->approveQuote($q1);
        $inv1 = Invoice::query()->where('quote_id', $q1->id)->firstOrFail();
        $inv1->forceFill(['amount_paid' => 400, 'status' => 'partially_paid', 'due_date' => now()->subDays(45)->toDateString()])->save();

        // Invoice 2: 500 total, nothing paid, not yet due → current bucket.
        $q2 = $this->draftQuote(500);
        $svc->approveQuote($q2);
        $inv2 = Invoice::query()->where('quote_id', $q2->id)->firstOrFail();
        $inv2->forceFill(['amount_paid' => 0, 'status' => 'issued', 'due_date' => now()->addDays(10)->toDateString()])->save();

        // A pending payment must NOT be treated as money in.
        Payment::create([
            'tenant_id' => $this->tenant->id, 'invoice_id' => $inv2->id, 'provider' => 'fake',
            'amount' => 500, 'currency' => 'SAR', 'status' => 'pending', 'idempotency_key' => 'k-'.uniqid(),
        ]);

        $res = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/billing/overview')->assertOk();

        $this->assertEqualsWithDelta(1100.0, $res->json('data.invoices.outstanding'), 0.01);  // 600 + 500
        $this->assertEqualsWithDelta(600.0, $res->json('data.aging.d31_60'), 0.01);
        $this->assertEqualsWithDelta(500.0, $res->json('data.aging.current'), 0.01);
        $this->assertSame(1, $res->json('data.invoices.overdue_count'));
        // 400 collected out of 1500 invoiced.
        $this->assertEqualsWithDelta(400.0, $res->json('data.invoices.collected'), 0.01);
        $this->assertEqualsWithDelta(0.2667, $res->json('data.invoices.collection_rate'), 0.001);
        // The pending payment is visible in its own bucket but contributes nothing to succeeded money.
        $this->assertSame(1, $res->json('data.payments.by_status.pending.count'));
        $this->assertEqualsWithDelta(0.0, $res->json('data.payments.succeeded_total'), 0.01);
    }

    /** The receivables worklist is ordered by lateness and never lists a fully paid invoice. */
    public function test_receivables_lists_only_what_is_still_owed(): void
    {
        $owner = $this->owner();
        $svc = $this->billing();

        $paidQuote = $this->draftQuote(300);
        $svc->approveQuote($paidQuote);
        $paid = Invoice::query()->where('quote_id', $paidQuote->id)->firstOrFail();
        $paid->forceFill(['amount_paid' => 300, 'status' => 'paid'])->save();

        $openQuote = $this->draftQuote(800);
        $svc->approveQuote($openQuote);
        $open = Invoice::query()->where('quote_id', $openQuote->id)->firstOrFail();
        $open->forceFill(['amount_paid' => 100, 'status' => 'overdue', 'due_date' => now()->subDays(12)->toDateString()])->save();

        $rows = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/billing/receivables')->assertOk()->json('data');

        $this->assertCount(1, $rows, 'a fully paid invoice is not a receivable');
        $this->assertEqualsWithDelta(700.0, $rows[0]['due'], 0.01);
        $this->assertSame(12, $rows[0]['days_late']);
    }

    public function test_finance_reads_require_billing_view(): void
    {
        $viewer = User::create([
            'name' => 'V', 'email' => 'v-'.uniqid().'@a.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($viewer, $this->tenant, Portal::Agency);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/billing/overview')->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/billing/payments')->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/billing/receivables')->assertForbidden();
    }
}

/**
 * A stand-in for a real, credentialed gateway — used only to prove the honest settlement path. It opens a
 * deterministic session and verifies a webhook when the body carries the expected signature.
 */
final class ConfiguredFakePaymentProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @param  array<string,mixed>  $payload */
    public function createSession(array $payload): array
    {
        return [
            'status' => 'created',
            'session_id' => 'sess_'.($payload['idempotency_key'] ?? 'x'),
            'checkout_url' => 'https://pay.test/checkout',
            'error' => null,
        ];
    }

    /** @param  array<string,string>  $headers */
    public function verifyWebhook(string $rawBody, array $headers): array
    {
        /** @var array<string,mixed> $data */
        $data = json_decode($rawBody, true) ?: [];
        $verified = ($data['sig'] ?? null) === 'good';

        if (! $verified) {
            return ['verified' => false];
        }

        return [
            'verified' => true,
            'event_id' => (string) ($data['event_id'] ?? ''),
            'type' => (string) ($data['type'] ?? ''),
            'payment_id' => (string) ($data['payment_id'] ?? ''),
            'status' => (string) ($data['status'] ?? 'processing'),
        ];
    }
}
