<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Funding\Models\FundingPayment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * FIN-WALLET-001 — finance and the budgets are one loop: a CONFIRMED client payment on an invoice of type «شحن رصيد
 * إعلاني» (`invoices.purpose = wallet_top_up`, naming its project) is appended to that project's budget ledger as a
 * deposit, so the budget's remaining rises by it. No paid integration: it runs on the two confirmations the product
 * already has — a payment recorded by finance, and a gateway payment a verified webhook settled.
 *
 * The deposit is the paid amount net of the invoice's tax share (`paid × subtotal ÷ total`): VAT collected is not ad
 * money. It goes through `AddFundingPayment` (one writer of the ledger: locking, FX to SAR with a dated rate or a
 * refusal, remaining before/after, audit, notices) with an idempotency key per confirmation, so the same confirmation
 * twice adds once. The entry carries its source (`meta.source`: the invoice and, for the gateway, the payment) and the
 * invoice number as its reference — a deposit always says where it came from.
 */
final class WalletTopUp
{
    public const PURPOSE = 'wallet_top_up';

    public function __construct(private readonly AddFundingPayment $add) {}

    /**
     * @param  string  $confirmation  unique per confirmation (`gateway:<payment id>` or `manual:<uuid>`)
     */
    public function fromInvoicePayment(Invoice $invoice, float $paid, string $confirmation, ?User $by = null, ?string $paymentId = null): ?FundingPayment
    {
        if ($invoice->purpose !== self::PURPOSE || $invoice->project_id === null || $paid <= 0) {
            return null;
        }
        $total = (float) $invoice->total;
        $net = $total > 0 ? round($paid * ((float) $invoice->subtotal / $total), 2) : round($paid, 2);
        if ($net <= 0) {
            return null;
        }

        return ($this->add)($by, [
            'client_workspace_id' => (string) $invoice->client_workspace_id,
            'project_id' => (string) $invoice->project_id,
            'amount' => $net,
            'currency' => (string) $invoice->currency,
            'paid_on' => Carbon::today('Asia/Riyadh')->toDateString(),
            'reference' => (string) $invoice->number,
            'note' => 'wallet top-up payment',
            'idempotency_key' => 'invoice:'.$invoice->getKey().':'.$confirmation,
            'source' => array_filter([
                'type' => 'invoice_payment',
                'invoice_id' => (string) $invoice->getKey(),
                'invoice_number' => (string) $invoice->number,
                'payment_id' => $paymentId,
                'via' => str_starts_with($confirmation, 'gateway:') ? 'gateway' : 'manual',
            ], static fn ($v): bool => $v !== null),
        ])['payment'];
    }
}
