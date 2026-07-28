<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentAttempt;
use App\Domains\Billing\Models\PaymentWebhookEvent;
use App\Domains\Billing\Models\Quote;
use App\Domains\Billing\Providers\PaymentProviderRegistry;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Taxonomy\Services\PaidServiceCatalog;
use App\Domains\Tenancy\Scopes\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The billing lifecycle: quote → approve → invoice → payment → (verified) webhook → paid.
 *
 * Honesty rules enforced here (not by convention):
 *   - startPayment() opens a provider session but NEVER marks anything paid. With the Null provider the payment
 *     stays `pending` and the invoice is untouched.
 *   - ONLY a webhook the adapter verifies (verified=true) can transition Payment→paid and settle the Invoice.
 *   - Idempotency is total: a payment's idempotency_key is unique (a retried startPayment returns the existing
 *     payment) and a webhook's event_id is unique (a re-delivered event is a no-op — no double settlement).
 *   - Every provider round-trip is recorded in payment_attempts.
 *
 * This class integrates NO real gateway and moves NO real money — that is a provider binding.
 */
final class BillingService
{
    public function __construct(
        private readonly PaymentProviderRegistry $providers,
        private readonly PaidServiceCatalog $paidServices,
    ) {}

    /**
     * Create a draft quote. Totals are trusted from the caller (already validated at the boundary); the number
     * is generated when not supplied.
     *
     * @param  array<string,mixed>  $data
     */
    public function createQuote(array $data): Quote
    {
        $subtotal = (float) ($data['subtotal'] ?? 0);
        $tax = (float) ($data['tax'] ?? 0);
        $discount = (float) ($data['discount'] ?? 0);
        $total = array_key_exists('total', $data)
            ? (float) $data['total']
            : max(0, $subtotal + $tax - $discount);

        return Quote::create([
            'tenant_id' => $data['tenant_id'] ?? null,
            'client_workspace_id' => $data['client_workspace_id'] ?? null,
            'request_id' => $data['request_id'] ?? null,
            'external_request_id' => $data['external_request_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'number' => $data['number'] ?? $this->generateNumber('QUO'),
            'currency' => $data['currency'] ?? config('billing.currency', 'SAR'),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => $discount,
            'total' => $total,
            'line_items' => $this->normalizeLineItems($data['line_items'] ?? null),
            'status' => $data['status'] ?? 'draft',
            'valid_until' => $data['valid_until'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
    }

    /**
     * Build a draft quote FROM an external request, carrying its selected paid-media services onto the quote as
     * stable line items (key = request.paid_service option key, label from the taxonomy option, amount null so a
     * human prices it later). Tenant + request linkage are taken from the request; other quote fields (totals,
     * currency, client_workspace_id, notes, valid_until, created_by) may be supplied via $overrides.
     *
     * @param  array<string,mixed>  $overrides
     */
    public function quoteFromRequest(ExternalRequest $request, array $overrides = []): Quote
    {
        // Canonical source: the request_services rows. Fall back to the denormalized `services` jsonb mirror.
        $serviceKeys = $request->requestServices()->orderBy('position')->pluck('service_key')->all();
        if ($serviceKeys === []) {
            $serviceKeys = array_values(array_filter((array) ($request->services ?? []), 'is_string'));
        }

        $lineItems = $this->lineItemsForServices($serviceKeys);

        return $this->createQuote(array_merge([
            'tenant_id' => $request->tenant_id,
            'client_workspace_id' => $request->client_id,
            'external_request_id' => $request->id, // ULID linkage (request_id column is uuid-typed)
            'currency' => $request->currency ?? config('billing.currency', 'SAR'),
            'line_items' => $lineItems,
        ], $overrides));
    }

    /**
     * Turn a list of request.paid_service option keys into stable quote/invoice line items. Amount is null
     * (priced later); the key is preserved verbatim so pricing stays attached to a stable service key.
     *
     * @param  list<string>  $serviceKeys
     * @return list<array{key:string, label:string, label_ar:string|null, amount:float|null}>
     */
    public function lineItemsForServices(array $serviceKeys): array
    {
        $resolved = $this->paidServices->resolve(array_values(array_filter($serviceKeys, 'is_string')));

        $items = [];
        foreach ($resolved as $service) {
            $items[] = [
                'key' => (string) $service['key'],
                'label' => (string) ($service['label_en'] ?? $service['key']),
                'label_ar' => isset($service['label_ar']) ? (string) $service['label_ar'] : null,
                'amount' => null,
            ];
        }

        return $items;
    }

    /**
     * The client approves a quote → we mark it approved and generate the issued invoice. A quote can only be
     * approved once (a subsequent call returns the existing invoice) and must not already be terminal.
     */
    public function approveQuote(Quote $quote): Invoice
    {
        if (in_array($quote->status, ['rejected', 'expired'], true)) {
            throw new InvalidArgumentException("Quote [{$quote->number}] cannot be approved from status [{$quote->status}].");
        }

        $existing = Invoice::where('quote_id', $quote->getKey())->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($quote): Invoice {
            $quote->update(['status' => 'approved']);

            $invoice = Invoice::create([
                'tenant_id' => $quote->tenant_id,
                'client_workspace_id' => $quote->client_workspace_id,
                'quote_id' => $quote->getKey(),
                'number' => $this->generateNumber('INV'),
                'currency' => $quote->currency,
                'subtotal' => $quote->subtotal,
                'tax' => $quote->tax,
                'discount' => $quote->discount,
                'total' => $quote->total,
                'line_items' => $quote->line_items, // services carry from the quote onto the issued invoice
                'amount_paid' => 0,
                'status' => 'draft',
            ]);

            return $this->issueInvoice($invoice);
        });
    }

    /** Move a draft invoice to issued (stamping issued_at). Idempotent for already-issued invoices. */
    public function issueInvoice(Invoice $invoice): Invoice
    {
        if (in_array($invoice->status, ['void', 'refunded'], true)) {
            throw new InvalidArgumentException("Invoice [{$invoice->number}] cannot be issued from status [{$invoice->status}].");
        }

        if ($invoice->status === 'draft') {
            $invoice->update([
                'status' => 'issued',
                'issued_at' => $invoice->issued_at ?? Carbon::now(),
            ]);
        }

        return $invoice;
    }

    /**
     * Open a payment for an issued invoice: create a pending Payment (idempotency_key unique) and ask the
     * provider to open a checkout session. NOTHING is marked paid here. With the Null provider the payment stays
     * pending and the session reports `awaiting_credentials`.
     *
     * @param  array<string,mixed>  $options  provider?: string, idempotency_key?: string, payload?: array
     */
    public function startPayment(Invoice $invoice, array $options = []): Payment
    {
        if (! in_array($invoice->status, ['issued', 'partially_paid'], true)) {
            throw new InvalidArgumentException("Invoice [{$invoice->number}] is not payable from status [{$invoice->status}].");
        }

        $idempotencyKey = (string) ($options['idempotency_key'] ?? Str::uuid());

        // A retried call with the same key returns the existing payment — no second provider round-trip.
        $existing = Payment::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $providerKey = (string) ($options['provider'] ?? $this->providers->defaultKey());
        $adapter = $this->providers->for($providerKey);

        $outstanding = max(0, (float) $invoice->total - (float) $invoice->amount_paid);

        $payment = Payment::create([
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->getKey(),
            'provider' => $providerKey,
            'amount' => $outstanding,
            'currency' => $invoice->currency,
            'status' => 'pending',
            'idempotency_key' => $idempotencyKey,
        ]);

        /** @var array<string,mixed> $payload */
        $payload = $options['payload'] ?? [];
        $session = $adapter->createSession(array_merge([
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->number,
            'amount' => $outstanding,
            'currency' => $invoice->currency,
            'idempotency_key' => $idempotencyKey,
        ], $payload));

        $payment->forceFill([
            'provider_session_id' => $session['session_id'] ?? null,
            'error' => $session['error'] ?? null,
        ])->save();

        $this->recordAttempt($payment, $session['status'] ?? 'unknown', $session);

        // Deliberately no state change beyond `pending`: a session is not a settlement.
        return $payment;
    }

    /**
     * Ingest a provider webhook. Runs cross-tenant (no request tenant context), so all reads bypass the tenant
     * global scope. Persists the event under its unique event_id (re-delivery → no-op) and — ONLY when the
     * adapter verifies the payload — settles the matching payment and invoice.
     *
     * @param  array<string,string>  $headers
     */
    public function handleWebhook(string $provider, string $rawBody, array $headers): PaymentWebhookEvent
    {
        $adapter = $this->providers->for($provider);
        $result = $adapter->verifyWebhook($rawBody, $headers);

        $verified = (bool) ($result['verified'] ?? false);
        $eventId = (string) ($result['event_id'] ?? 'evt_'.hash('sha256', $provider.'|'.$rawBody));

        // Idempotency: a re-delivered event is a no-op — return the already-stored ledger row untouched.
        $already = PaymentWebhookEvent::withoutGlobalScope(TenantScope::class)
            ->where('event_id', $eventId)
            ->first();
        if ($already !== null) {
            return $already;
        }

        $event = new PaymentWebhookEvent;
        $event->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'provider' => $provider,
            'event_id' => $eventId,
            'type' => $result['type'] ?? null,
            'verified' => $verified,
            'payload' => $this->decodeBody($rawBody),
            'processed_at' => Carbon::now(),
        ]);

        if (! $verified) {
            // Unverified payloads never move money — recorded for forensics and dropped.
            $event->save();

            return $event;
        }

        $paymentRef = $result['payment_id'] ?? null;
        $payment = $this->matchPayment($provider, $paymentRef !== null ? (string) $paymentRef : null);

        if ($payment !== null) {
            $event->tenant_id = $payment->tenant_id;
            $this->applyVerifiedTransition($payment, (string) ($result['status'] ?? 'processing'), $result);
        }

        $event->save();

        return $event;
    }

    /**
     * Apply a verified webhook's status to the payment (and, when paid/refunded, the invoice). Guarded so a
     * duplicate paid signal settles at most once.
     *
     * @param  array<string,mixed>  $result
     */
    private function applyVerifiedTransition(Payment $payment, string $status, array $result): void
    {
        $providerPaymentId = isset($result['payment_id']) ? (string) $result['payment_id'] : $payment->provider_payment_id;

        DB::transaction(function () use ($payment, $status, $providerPaymentId, $result): void {
            switch ($status) {
                case 'paid':
                    if ($payment->status === 'paid') {
                        break; // already settled — never double-count
                    }
                    $payment->forceFill([
                        'status' => 'paid',
                        'provider_payment_id' => $providerPaymentId,
                        'paid_at' => Carbon::now(),
                        'error' => null,
                    ])->save();
                    $this->settleInvoice($payment);
                    break;

                case 'refunded':
                    $payment->forceFill(['status' => 'refunded', 'provider_payment_id' => $providerPaymentId])->save();
                    $invoice = $this->invoiceOf($payment);
                    $invoice?->forceFill(['status' => 'refunded'])->save();
                    break;

                case 'failed':
                    $payment->forceFill(['status' => 'failed', 'provider_payment_id' => $providerPaymentId])->save();
                    break;

                case 'pending':
                case 'processing':
                    $payment->forceFill(['status' => $status, 'provider_payment_id' => $providerPaymentId])->save();
                    break;

                default:
                    // Unknown status: record it on the payment but do not settle.
                    $payment->forceFill(['status' => 'processing', 'provider_payment_id' => $providerPaymentId])->save();
            }

            $this->recordAttempt($payment, $status, $result);
        });
    }

    /** Add a settled payment to its invoice and flip the invoice to paid / partially_paid. */
    private function settleInvoice(Payment $payment): void
    {
        $invoice = $this->invoiceOf($payment);
        if ($invoice === null) {
            return;
        }

        $paid = (float) $invoice->amount_paid + (float) $payment->amount;
        $total = (float) $invoice->total;
        $fullyPaid = $paid + 0.0001 >= $total;

        $invoice->forceFill([
            'amount_paid' => $paid,
            'status' => $fullyPaid ? 'paid' : 'partially_paid',
            'paid_at' => $fullyPaid ? ($invoice->paid_at ?? Carbon::now()) : $invoice->paid_at,
        ])->save();
    }

    private function invoiceOf(Payment $payment): ?Invoice
    {
        return Invoice::withoutGlobalScope(TenantScope::class)
            ->whereKey($payment->invoice_id)
            ->first();
    }

    /** Locate the payment a verified webhook refers to, across tenants (provider session or payment id). */
    private function matchPayment(string $provider, ?string $reference): ?Payment
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        return Payment::withoutGlobalScope(TenantScope::class)
            ->where('provider', $provider)
            ->where(function ($query) use ($reference): void {
                $query->where('provider_session_id', $reference)
                    ->orWhere('provider_payment_id', $reference);
            })
            ->first();
    }

    /** @param  array<string,mixed>  $response */
    private function recordAttempt(Payment $payment, string $status, array $response): void
    {
        $attempt = new PaymentAttempt;
        $attempt->forceFill([
            'tenant_id' => $payment->tenant_id,
            'payment_id' => $payment->getKey(),
            'status' => $status,
            'provider_response' => $response,
            'created_at' => Carbon::now(),
        ])->save();
    }

    /** @return array<string,mixed>|null */
    private function decodeBody(string $rawBody): ?array
    {
        if ($rawBody === '') {
            return null;
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : ['raw' => $rawBody];
    }

    /**
     * Normalize a caller-supplied line_items value to a clean list of associative arrays, or null when none.
     * Keeps the caller's shape (key/label/amount…) verbatim — this is display/pricing data, not validated here.
     *
     * @return list<array<string,mixed>>|null
     */
    private function normalizeLineItems(mixed $lineItems): ?array
    {
        if (! is_array($lineItems) || $lineItems === []) {
            return null;
        }

        $items = [];
        foreach ($lineItems as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items === [] ? null : $items;
    }

    private function generateNumber(string $prefix): string
    {
        for ($i = 0; $i < 5; $i++) {
            $candidate = $prefix.'-'.date('Y').'-'.strtoupper(Str::random(8));
            $model = $prefix === 'INV' ? Invoice::class : Quote::class;
            if (! $model::where('number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique billing number.');
    }
}
