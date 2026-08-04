<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Subscriptions\Models\SubscriptionInvoice;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Services\SubscriptionInvoicing;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * CampaignsHub's own invoices (SUBINV-001).
 *
 * The claim that matters most is a separation: what an agency invoices its clients and what
 * CampaignsHub invoices the agency are different documents, in different tables, behind different
 * permissions. Everything else here is about a document being honest — issued when the charge is,
 * settled only by a confirmed payment, and never silently rewritten by a later tax change.
 */
final class SubscriptionInvoiceTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->assertingAcrossTenants();

        config([
            'accounts.registration.plans.growth' => ['requires_payment' => true],
            'services.moyasar.secret_key' => 'sk_test',
            'services.moyasar.webhook_token' => 'shared-secret',
        ]);
    }

    /** An application holding an open charge, and therefore an issued invoice. */
    private function chargedApplication(string $email = 'billed@a.test'): SubscriptionPayment
    {
        $res = $this->apply([
            'email' => $email, 'plan_code' => 'growth', 'billing_interval' => 'monthly',
            // A distinct company per applicant: the trial-abuse check counts one trial per company,
            // so reusing a name here would have the second application refused rather than charged.
            'tenant_name' => 'Billed Co '.$email,
        ]);
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($res),
        ])->assertOk();

        $request = RegistrationRequest::query()->whereRaw('lower(email) = ?', [$email])->firstOrFail();

        // The mobile gate, cleared the way an applicant clears it (PHONE-VERIFY-001).

        $this->verifyMobileFor($request);

        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout")->assertOk();

        return SubscriptionPayment::query()->where('registration_request_id', $request->getKey())->firstOrFail();
    }

    private function confirm(SubscriptionPayment $payment, string $status = 'paid', int $amount = 900): void
    {
        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_'.uniqid(), 'type' => 'payment_'.$status, 'secret_token' => 'shared-secret',
            'data' => ['id' => 'pay_'.$payment->getKey(), 'status' => $status, 'amount' => $amount,
                'currency' => 'SAR', 'metadata' => ['reference' => $payment->idempotency_key]],
        ])->assertOk();
    }

    // ── The separation ────────────────────────────────────────────────────────────────────────

    /**
     * A subscription invoice is NOT an agency invoice.
     *
     * Different tables, because whose tax number appears on the document, whose currency governs it
     * and who may read it are all different answers. One table for both would make "revenue" a number
     * that answers neither question.
     */
    public function test_a_subscription_invoice_never_appears_among_the_agencys_client_invoices(): void
    {
        $this->chargedApplication();

        $this->assertSame(1, SubscriptionInvoice::query()->count());
        $this->assertSame(0, Invoice::query()->withoutGlobalScopes()->count(), 'the agency ledger is untouched');
    }

    // ── Issuing ───────────────────────────────────────────────────────────────────────────────

    /**
     * The document exists from the moment the charge does — not from the moment it is paid.
     *
     * A customer is entitled to the invoice that says what they were asked for whether or not they
     * pay it, and one conjured retrospectively from a payment can show no due date and no balance.
     */
    public function test_an_invoice_is_issued_when_the_charge_is_opened_not_when_it_is_paid(): void
    {
        $payment = $this->chargedApplication();

        $invoice = SubscriptionInvoice::query()->firstOrFail();

        $this->assertSame('issued', $invoice->status);
        $this->assertSame((string) $payment->getKey(), $invoice->subscription_payment_id);
        $this->assertNotNull($invoice->due_at);
        // The whole of it, tax included — 9.00 plus 15%.
        $this->assertSame('10.35', $invoice->outstanding());
    }

    /** VAT is computed once and stored — not derived at read time from whatever the rate is now. */
    public function test_the_tax_is_stored_on_the_document_rather_than_recomputed(): void
    {
        $this->chargedApplication();

        $invoice = SubscriptionInvoice::query()->firstOrFail();

        $this->assertSame('basic_15', $invoice->tax_treatment);
        $this->assertSame('1.35', (string) $invoice->tax_total, '15% of 9.00');
        $this->assertSame('10.35', (string) $invoice->total);

        // A later change to the treatment must not rewrite a document already issued.
        config(['billing.tax.default' => 'zero_rated']);
        $this->assertSame('1.35', (string) $invoice->refresh()->tax_total);
    }

    /** A retried checkout resolves to one charge, so it must resolve to ONE document. */
    public function test_a_repeated_checkout_does_not_produce_a_second_invoice(): void
    {
        $payment = $this->chargedApplication();
        $request = $payment->registrationRequest;

        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout")->assertOk();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout")->assertOk();

        $this->assertSame(1, SubscriptionInvoice::query()->count());
    }

    /** Numbers are sequential and human-readable — a uuid is not something anybody can quote back. */
    public function test_invoice_numbers_are_sequential_within_the_year(): void
    {
        $this->chargedApplication('one@a.test');
        $this->chargedApplication('two@a.test');

        $numbers = SubscriptionInvoice::query()->orderBy('number')->pluck('number')->all();
        $year = now()->year;

        $this->assertSame(["CH-{$year}-00001", "CH-{$year}-00002"], $numbers);
    }

    // ── Settlement ────────────────────────────────────────────────────────────────────────────

    /** Only a verified webhook settles the document, exactly as it is the only thing that settles the payment. */
    public function test_a_confirmed_payment_settles_the_invoice(): void
    {
        $payment = $this->chargedApplication();
        $this->confirm($payment);

        $invoice = SubscriptionInvoice::query()->firstOrFail();

        $this->assertSame('paid', $invoice->status);
        $this->assertSame('10.35', (string) $invoice->amount_paid);
        $this->assertSame('0.00', $invoice->outstanding());
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_an_unverified_webhook_leaves_the_invoice_unpaid(): void
    {
        $payment = $this->chargedApplication();

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_forged', 'type' => 'payment_paid', // no secret_token
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'amount' => 900, 'currency' => 'SAR',
                'metadata' => ['reference' => $payment->idempotency_key]],
        ])->assertOk();

        $this->assertSame('issued', SubscriptionInvoice::query()->firstOrFail()->status);
    }

    /** A reversal is recorded ON the document, never by deleting it. */
    public function test_a_refund_is_recorded_on_the_document(): void
    {
        $payment = $this->chargedApplication();
        $this->confirm($payment);
        $this->confirm($payment->refresh(), 'refunded');

        $invoice = SubscriptionInvoice::query()->firstOrFail();

        $this->assertSame('refunded', $invoice->status);
        $this->assertNotNull($invoice->refunded_at);
        // Still there. A tax document that disappears leaves a hole in a sequence somebody has to
        // explain.
        $this->assertSame(1, SubscriptionInvoice::query()->count());
    }

    /** Voiding is for a document that should never have existed — and a settled one is not that. */
    public function test_a_settled_invoice_cannot_be_voided(): void
    {
        $payment = $this->chargedApplication();
        $this->confirm($payment);

        $this->expectException(HttpException::class);

        app(SubscriptionInvoicing::class)->void(SubscriptionInvoice::query()->firstOrFail(), 'a mistake');
    }

    // ── Reading, sharing, downloading ─────────────────────────────────────────────────────────

    public function test_a_customer_reads_and_downloads_their_own_invoices(): void
    {
        $payment = $this->chargedApplication();
        $this->confirm($payment);

        $tenant = Tenant::query()->withoutGlobalScopes()->firstOrFail();
        $user = User::where('email', 'billed@a.test')->firstOrFail();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/subscriptions/invoices')->assertOk()
            ->assertJsonPath('data.invoices.0.status', 'paid')
            ->assertJsonPath('data.invoices.0.total', '10.35')
            ->assertJsonPath('data.invoices.0.tax_treatment', 'basic_15');

        $invoice = SubscriptionInvoice::query()->firstOrFail();

        $download = $this->actingAs($user, 'sanctum')
            ->get("/api/v1/subscriptions/invoices/{$invoice->getKey()}/download")->assertOk();

        // The document names the treatment, not only the amount — `zero_rated` and `exempt` both
        // compute to zero and are different statements to a tax authority.
        $this->assertStringContainsString($invoice->number, $download->getContent());
        $this->assertStringContainsString('basic_15', $download->getContent());
        $this->assertSame($tenant->getKey(), $invoice->tenant_id);
    }

    /**
     * Somebody else's invoice is a 404, not a 403.
     *
     * Telling a customer that an invoice exists but is not theirs is telling them an invoice exists.
     * The stranger here has a workspace of their OWN — a caller with no workspace at all is refused
     * earlier, by the tenant middleware, which is a different (and also correct) answer.
     */
    public function test_another_workspaces_invoice_is_not_found(): void
    {
        $mine = $this->chargedApplication('mine@a.test');
        $this->confirm($mine);
        $invoice = SubscriptionInvoice::query()->firstOrFail();

        $theirs = $this->chargedApplication('theirs@a.test');
        $this->confirm($theirs);

        $stranger = User::where('email', 'theirs@a.test')->firstOrFail();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/subscriptions/invoices/{$invoice->getKey()}")
            ->assertNotFound();
    }

    /** A share link works for somebody with no account — and stops the moment it is revoked. */
    public function test_a_shared_invoice_is_readable_publicly_until_it_is_revoked(): void
    {
        $payment = $this->chargedApplication();
        $this->confirm($payment);

        $invoice = app(SubscriptionInvoicing::class)->share(SubscriptionInvoice::query()->firstOrFail());

        $this->getJson("/api/v1/subscriptions/invoices/shared/{$invoice->share_token}")->assertOk()
            ->assertJsonPath('data.invoice.number', $invoice->number);

        $token = $invoice->share_token;
        app(SubscriptionInvoicing::class)->revokeShare($invoice);

        // Any link already sent stops working immediately.
        $this->getJson("/api/v1/subscriptions/invoices/shared/{$token}")->assertNotFound();
    }

    /** Every document movement is auditable — issuing, settling and sharing all leave a record. */
    public function test_the_document_lifecycle_is_audited(): void
    {
        $payment = $this->chargedApplication();
        $this->confirm($payment);
        app(SubscriptionInvoicing::class)->share(SubscriptionInvoice::query()->firstOrFail());

        foreach (['issued', 'paid', 'shared'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => "subscription.invoice.{$action}"]);
        }
    }
}
