<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Models\ClientBillingProfile;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Support\IncompleteFeeBasis;
use App\Domains\Billing\Support\TaxTreatment;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Rates\OfficialPegs;
use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * INV-LIFECYCLE-001, INV-IMMUTABLE-001, INV-PROFILE-001 — the agency-to-client fee invoice.
 *
 * Draft → Reviewed → Issued → Sent → Paid, with Due and Overdue read from the calendar. The Owner reviews
 * before anything is issued; once issued the figures never change, a mistake is corrected by a NEW version
 * that supersedes the old one (the old one is voided with its reason, never edited).
 *
 * ## The fee is computed from the canonical money
 *
 * A percentage fee is a percentage of the period's spend as `MetricsAggregator` reports it for the
 * client's projects — the same figure the dashboard, reports and portal show. If any of that spend is
 * withheld, no draft is produced ({@see IncompleteFeeBasis}): a fee computed on part of the money is not
 * a smaller fee, it is a wrong one. The result is converted into the invoice currency at the period's end
 * date with the stored rate (a legal peg is recorded on first use, exactly as `fx:rates` would).
 *
 * This reports nothing about delivery: a report being sent or viewed never moves an invoice, and an
 * invoice being paid never moves a report (RB-INDEPENDENT-001).
 */
final class ClientInvoiceService
{
    public function __construct(
        private readonly MetricsAggregator $metrics,
        private readonly CurrencyConverter $rates,
        private readonly BillingService $billing,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Draft the invoice for one period under the client's billing profile.
     *
     * @param  float|null  $manualAmount  required for a `manual` fee model; ignored otherwise
     */
    public function draftFor(ClientBillingProfile $profile, Carbon $from, Carbon $to, ?float $manualAmount = null): Invoice
    {
        $existing = Invoice::query()
            ->where('client_workspace_id', $profile->client_workspace_id)
            ->where('source', 'billing_profile')
            ->whereDate('period_start', $from)->whereDate('period_end', $to)
            ->whereNotIn('status', ['void'])
            ->first();

        // One live invoice per client per period: asking again returns it instead of billing twice.
        if ($existing !== null) {
            return $existing;
        }

        [$fee, $basis] = $this->fee($profile, $from, $to, $manualAmount);

        $subtotal = round($fee, 2);
        $tax = TaxTreatment::taxFor($profile->tax_treatment, $subtotal);

        $invoice = Invoice::create([
            'tenant_id' => $profile->tenant_id,
            'client_workspace_id' => $profile->client_workspace_id,
            'number' => $this->billing->generateNumber('INV'),
            'currency' => $profile->currency,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'tax_treatment' => $profile->tax_treatment,
            'discount' => 0,
            'total' => round($subtotal + $tax, 2),
            'line_items' => [[
                'description' => 'Advertising management fee',
                'description_ar' => 'رسوم إدارة الحملات الإعلانية',
                'period' => $from->toDateString().' → '.$to->toDateString(),
                'quantity' => 1,
                'unit_price' => $subtotal,
                'total' => $subtotal,
            ]],
            'amount_paid' => 0,
            'status' => 'draft',
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'source' => 'billing_profile',
            'profile_snapshot' => ['profile' => $profile->only(['fee_model', 'fixed_amount', 'percentage', 'minimum_fee', 'cadence', 'currency', 'tax_treatment', 'due_days', 'issuer', 'bill_to', 'payment_details', 'notes']), 'basis' => $basis],
        ]);

        $this->audit->log('invoice.drafted', 'invoice', (string) $invoice->getKey(), null, ['number' => $invoice->number, 'total' => $invoice->total, 'basis' => $basis]);

        return $invoice;
    }

    /**
     * @return array{0: float, 1: array<string, mixed>} the fee in the invoice currency, and how it was reached
     */
    private function fee(ClientBillingProfile $profile, Carbon $from, Carbon $to, ?float $manualAmount): array
    {
        $basis = ['model' => $profile->fee_model];

        if ($profile->fee_model === 'manual') {
            if ($manualAmount === null || $manualAmount < 0) {
                throw new InvalidArgumentException('A manual fee needs an amount.');
            }

            return [$manualAmount, $basis + ['amount' => $manualAmount]];
        }

        if ($profile->fee_model === 'fixed') {
            $fixed = (float) $profile->fixed_amount;

            return [$this->atLeastMinimum($profile, $fixed), $basis + ['fixed' => $fixed]];
        }

        // Percentage of the period's managed spend, on the canonical figure.
        $projectIds = Project::query()->where('client_workspace_id', $profile->client_workspace_id)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $totals = $projectIds === [] ? [] : $this->metrics->forProjects($projectIds)->totals($from->copy()->startOfDay(), $to->copy()->startOfDay());

        if ($projectIds === [] || ((int) ($totals['spend_withheld_rows'] ?? 0)) > 0) {
            throw new IncompleteFeeBasis('The period\'s spend is incomplete (withheld money or no projects), so a percentage fee cannot be drafted.');
        }

        $spend = (float) ($totals['spend'] ?? 0.0);
        $inInvoiceCurrency = $this->convert($spend, ReportingCurrency::DEFAULT, $profile->currency, $to);
        $percent = (float) $profile->percentage;

        return [
            $this->atLeastMinimum($profile, round($inInvoiceCurrency * $percent / 100, 2)),
            $basis + ['spend' => $spend, 'spend_currency' => ReportingCurrency::DEFAULT, 'spend_in_invoice_currency' => round($inInvoiceCurrency, 2), 'percentage' => $percent],
        ];
    }

    private function atLeastMinimum(ClientBillingProfile $profile, float $fee): float
    {
        return $profile->minimum_fee !== null ? max($fee, (float) $profile->minimum_fee) : $fee;
    }

    private function convert(float $amount, string $from, string $to, Carbon $date): float
    {
        if (strtoupper($from) === strtoupper($to)) {
            return $amount;
        }

        $resolved = $this->rates->resolve($from, $to, $date);

        if ($resolved === null && ($peg = OfficialPegs::rate($from, $to)) !== null) {
            // A legal peg needs no feed; it is recorded once, attributed, exactly as `fx:rates` records it.
            CurrencyRate::updateOrCreate(
                ['base_currency' => strtoupper($from), 'quote_currency' => strtoupper($to), 'rate_date' => '2001-01-01'],
                ['rate' => $peg, 'source' => OfficialPegs::source($from, $to)],
            );
            $resolved = ['rate' => $peg];
        }

        if ($resolved === null) {
            throw new IncompleteFeeBasis("No exchange rate from {$from} to {$to} on or before {$date->toDateString()}, so the fee cannot be put in the invoice currency.");
        }

        return $amount * $resolved['rate'];
    }

    public function review(Invoice $invoice, User $by): Invoice
    {
        $this->require($invoice, ['draft'], 'review');
        $invoice->update(['status' => 'reviewed', 'reviewed_at' => Carbon::now(), 'reviewed_by' => $by->id]);
        $this->audit->log('invoice.reviewed', 'invoice', (string) $invoice->getKey());

        return $invoice;
    }

    /** Issue a REVIEWED invoice: the figures freeze, the number is final and the due date is set from the terms. */
    public function issue(Invoice $invoice): Invoice
    {
        $this->require($invoice, ['reviewed'], 'issue');
        $days = (int) data_get($invoice->profile_snapshot, 'profile.due_days', 7);

        $invoice->update([
            'status' => 'issued',
            'issued_at' => Carbon::now(),
            'due_date' => $invoice->due_date ?? Carbon::today()->addDays($days),
        ]);
        $this->audit->log('invoice.issued', 'invoice', (string) $invoice->getKey(), null, ['total' => $invoice->total, 'due_date' => $invoice->due_date?->toDateString()]);

        return $invoice;
    }

    public function markSent(Invoice $invoice): Invoice
    {
        $this->require($invoice, ['issued'], 'send');
        $invoice->update(['status' => 'sent', 'sent_at' => Carbon::now()]);
        $this->audit->log('invoice.sent', 'invoice', (string) $invoice->getKey());

        return $invoice;
    }

    /** Record a payment received outside the gateway (a bank transfer confirmed over WhatsApp). */
    public function recordPayment(Invoice $invoice, float $amount, User $by): Invoice
    {
        $this->require($invoice, ['issued', 'sent', 'partially_paid'], 'record a payment on');
        if ($amount <= 0) {
            throw new InvalidArgumentException('A payment must be a positive amount.');
        }

        $paid = round((float) $invoice->amount_paid + $amount, 2);
        $whole = $paid >= (float) $invoice->total;
        $invoice->update(['amount_paid' => min($paid, (float) $invoice->total), 'status' => $whole ? 'paid' : 'partially_paid', 'paid_at' => $whole ? Carbon::now() : null]);
        $this->audit->log('invoice.payment_recorded', 'invoice', (string) $invoice->getKey(), null, ['amount' => $amount, 'by' => $by->id]);

        return $invoice;
    }

    /**
     * Correct an issued invoice without rewriting it: the original is voided with the reason, and a new
     * draft carrying the same period supersedes it. Returns the new draft.
     */
    public function correct(Invoice $invoice, string $reason, User $by): Invoice
    {
        $this->require($invoice, ['issued', 'sent', 'partially_paid'], 'correct');
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A correction needs a reason.');
        }

        $invoice->update(['status' => 'void', 'correction_reason' => $reason]);

        $copy = Invoice::create([
            'tenant_id' => $invoice->tenant_id,
            'client_workspace_id' => $invoice->client_workspace_id,
            'number' => $this->billing->generateNumber('INV'),
            'currency' => $invoice->currency,
            'subtotal' => $invoice->subtotal,
            'tax' => $invoice->tax,
            'tax_treatment' => $invoice->tax_treatment,
            'discount' => $invoice->discount,
            'total' => $invoice->total,
            'line_items' => $invoice->line_items,
            'amount_paid' => 0,
            'status' => 'draft',
            'period_start' => $invoice->period_start,
            'period_end' => $invoice->period_end,
            'source' => $invoice->source,
            'supersedes_id' => $invoice->getKey(),
            'profile_snapshot' => $invoice->profile_snapshot,
        ]);
        $this->audit->log('invoice.corrected', 'invoice', (string) $invoice->getKey(), null, ['superseded_by' => $copy->number, 'by' => $by->id], $reason);

        return $copy;
    }

    /** @param list<string> $allowed */
    private function require(Invoice $invoice, array $allowed, string $verb): void
    {
        if (! in_array($invoice->status, $allowed, true)) {
            throw new InvalidArgumentException("Invoice [{$invoice->number}] cannot {$verb} from status [{$invoice->status}].");
        }
    }
}
