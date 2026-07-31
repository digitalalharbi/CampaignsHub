<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Support\TaxTreatment;
use App\Domains\Subscriptions\Models\SubscriptionInvoice;
use App\Domains\Subscriptions\Models\SubscriptionInvoiceLine;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issuing and settling CampaignsHub's own invoices (SUBINV-001).
 *
 * An invoice is issued when a CHARGE is opened, not when it is paid. A customer is entitled to the
 * document that says what they were asked for whether or not they went on to pay it — and an invoice
 * conjured retrospectively from a payment cannot show a due date, an unpaid balance, or the fact that
 * a charge existed at all.
 *
 * Settlement is a separate step driven by the same verified webhook that settles the payment. Nothing
 * here can mark an invoice paid on its own.
 */
final class SubscriptionInvoicing
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The document for a charge.
     *
     * Idempotent on the payment: a retried checkout resolves to the same charge, so it must resolve to
     * the same invoice. Two invoices for one charge is a customer with two documents for one debt.
     */
    public function issueFor(SubscriptionPayment $payment, array $options = []): SubscriptionInvoice
    {
        $existing = SubscriptionInvoice::query()
            ->where('subscription_payment_id', $payment->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        $tenant = $payment->tenant_id !== null
            ? Tenant::query()->withoutGlobalScopes()->find($payment->tenant_id)
            : null;

        $request = $payment->registrationRequest;

        // Captured at issue. A customer who later renames their company is still owed the document
        // that says what it said when they were charged.
        $billToName = (string) ($options['bill_to_name']
            ?? $tenant?->name
            ?? $request?->tenant_name
            ?? 'Customer');
        $billToEmail = (string) ($options['bill_to_email']
            ?? $request?->email
            ?? $tenant?->billing_email
            ?? '');

        $treatment = (string) ($options['tax_treatment'] ?? TaxTreatment::DEFAULT);
        $rate = TaxTreatment::rate($treatment);

        $unit = (float) $payment->amount;
        $discount = (float) ($options['discount'] ?? 0);
        $subtotal = max(0, $unit - $discount);
        $tax = round($subtotal * $rate, 2);

        return DB::transaction(function () use (
            $payment, $tenant, $request, $billToName, $billToEmail,
            $treatment, $rate, $unit, $discount, $subtotal, $tax, $options
        ): SubscriptionInvoice {
            $invoice = new SubscriptionInvoice;
            $invoice->forceFill([
                'number' => $this->nextNumber(),
                'tenant_id' => $tenant?->getKey(),
                'registration_request_id' => $request?->getKey(),
                'subscription_id' => $payment->subscription_id,
                'subscription_payment_id' => $payment->getKey(),
                'bill_to_name' => $billToName,
                'bill_to_email' => $billToEmail,
                'bill_to_tax_number' => $options['bill_to_tax_number'] ?? null,
                'currency' => $payment->currency,
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'discount_total' => number_format($discount, 2, '.', ''),
                'tax_total' => number_format($tax, 2, '.', ''),
                'total' => number_format($subtotal + $tax, 2, '.', ''),
                'amount_paid' => '0.00',
                'tax_treatment' => $treatment,
                'tax_rate' => $rate,
                'status' => 'issued',
                'issued_at' => Carbon::now(),
                'due_at' => Carbon::now()->addDays((int) ($options['due_days'] ?? 7)),
            ])->save();

            SubscriptionInvoiceLine::create([
                'subscription_invoice_id' => $invoice->getKey(),
                'description' => $this->describe($payment),
                'plan_code' => $payment->plan_code,
                'period_label' => $payment->billing_interval,
                'quantity' => 1,
                'unit_price' => number_format($unit, 2, '.', ''),
                'discount' => number_format($discount, 2, '.', ''),
                'line_total' => number_format($subtotal, 2, '.', ''),
                'sort_order' => 0,
            ]);

            $this->audit->log(
                action: 'subscription.invoice.issued',
                entityType: SubscriptionInvoice::class,
                entityId: (string) $invoice->getKey(),
                after: ['number' => $invoice->number, 'total' => (string) $invoice->total],
                tenantId: $tenant?->getKey() !== null ? (string) $tenant->getKey() : null,
            );

            return $invoice->refresh();
        });
    }

    /**
     * Mark the document settled.
     *
     * Called from the webhook path only, alongside the payment it belongs to — this method does not
     * decide that money arrived, it records that something else established it.
     */
    public function settle(SubscriptionPayment $payment): ?SubscriptionInvoice
    {
        $invoice = SubscriptionInvoice::query()
            ->where('subscription_payment_id', $payment->getKey())->first();

        if ($invoice === null || $invoice->status === 'paid') {
            return $invoice;
        }

        /*
         * Attach the document to the workspace the moment one exists.
         *
         * The very first invoice a customer receives is issued BEFORE their tenant does — it is the
         * trial fee, billed to an applicant. Leaving `tenant_id` null afterwards meant that invoice
         * never appeared in the customer's own list: they had been charged for something they could
         * not see a document for.
         */
        if ($invoice->tenant_id === null && $payment->registrationRequest?->tenant_id !== null) {
            $invoice->forceFill(['tenant_id' => $payment->registrationRequest->tenant_id])->save();
        }

        $invoice->forceFill([
            'status' => 'paid',
            // What was actually collected, which is the charge — the tax is inside the total the
            // customer paid, not an extra we take separately.
            'amount_paid' => $invoice->total,
            'paid_at' => Carbon::now(),
        ])->save();

        $this->audit->log(
            action: 'subscription.invoice.paid',
            entityType: SubscriptionInvoice::class,
            entityId: (string) $invoice->getKey(),
            after: ['number' => $invoice->number, 'amount' => (string) $invoice->total],
            tenantId: $invoice->tenant_id,
        );

        return $invoice->refresh();
    }

    /** A reversal is recorded on the document, never by deleting it. */
    public function refund(SubscriptionPayment $payment, ?string $reason = null): ?SubscriptionInvoice
    {
        $invoice = SubscriptionInvoice::query()
            ->where('subscription_payment_id', $payment->getKey())->first();

        if ($invoice === null) {
            return null;
        }

        $invoice->forceFill([
            'status' => 'refunded',
            'refunded_at' => Carbon::now(),
        ])->save();

        $this->audit->log(
            action: 'subscription.invoice.refunded',
            entityType: SubscriptionInvoice::class,
            entityId: (string) $invoice->getKey(),
            reason: $reason,
            tenantId: $invoice->tenant_id,
        );

        return $invoice->refresh();
    }

    /**
     * Void an invoice that should never have been issued.
     *
     * Voiding rather than deleting, and a reason is required. A tax document that disappears leaves a
     * hole in a sequence somebody will eventually have to explain.
     */
    public function void(SubscriptionInvoice $invoice, string $reason): SubscriptionInvoice
    {
        abort_if($invoice->status === 'paid', 422, 'A settled invoice cannot be voided — refund it instead.');

        $invoice->forceFill([
            'status' => 'void',
            'voided_at' => Carbon::now(),
            'void_reason' => $reason,
        ])->save();

        $this->audit->log(
            action: 'subscription.invoice.voided',
            entityType: SubscriptionInvoice::class,
            entityId: (string) $invoice->getKey(),
            reason: $reason,
            tenantId: $invoice->tenant_id,
        );

        return $invoice->refresh();
    }

    /** Make the document reachable by a link — for an accountant who has no account here. */
    public function share(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if ($invoice->share_token === null) {
            $invoice->forceFill([
                'share_token' => Str::random(48),
                'shared_at' => Carbon::now(),
            ])->save();

            $this->audit->log(
                action: 'subscription.invoice.shared',
                entityType: SubscriptionInvoice::class,
                entityId: (string) $invoice->getKey(),
                tenantId: $invoice->tenant_id,
            );
        }

        return $invoice->refresh();
    }

    /** Revoking is removing the token: any link already sent stops working immediately. */
    public function revokeShare(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        $invoice->forceFill(['share_token' => null, 'shared_at' => null])->save();

        $this->audit->log(
            action: 'subscription.invoice.share_revoked',
            entityType: SubscriptionInvoice::class,
            entityId: (string) $invoice->getKey(),
            tenantId: $invoice->tenant_id,
        );

        return $invoice->refresh();
    }

    /**
     * The next number in this year's sequence.
     *
     * Sequential per year because that is what an accountant reconciles against, and taken inside the
     * issuing transaction with a lock so two concurrent charges cannot mint the same number.
     */
    private function nextNumber(): string
    {
        $year = Carbon::now()->year;
        $prefix = "CH-{$year}-";

        $last = SubscriptionInvoice::query()
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $next = $last === null ? 1 : ((int) Str::afterLast((string) $last, '-')) + 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function describe(SubscriptionPayment $payment): string
    {
        return match ($payment->purpose) {
            'trial' => "CampaignsHub trial — {$payment->plan_code}",
            'reactivation' => "CampaignsHub reactivation — {$payment->plan_code}",
            default => "CampaignsHub subscription — {$payment->plan_code} ({$payment->billing_interval})",
        };
    }
}
