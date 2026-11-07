<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use Illuminate\Support\Facades\DB;

/**
 * PRICING-VAT-002 — which subscription invoices need an accountant's eye. Read-only: nothing here changes a document.
 *
 * Each invoice is held against the charge it was issued for:
 * - `total_differs_from_charge` — the document asks for a different amount than the gateway was asked for (the pre-VAT-001
 *   documents that added 15% on top of an all-in charge).
 * - `paid_amount_differs_from_collected` — marked paid for a different amount than the gateway confirmed.
 * - `paid_without_collection` — marked paid while its charge is not paid.
 * - `no_tax_treatment` — issued without a treatment, so its VAT cannot be stated.
 * Historical documents are never rewritten: a flagged one is corrected, if at all, by a credit note an accountant decides on.
 */
final class VatInvoiceReview
{
    public const ISSUES = ['total_differs_from_charge', 'paid_amount_differs_from_collected', 'paid_without_collection', 'no_tax_treatment'];

    /** @return array{checked: int, flagged: int, by_issue: array<string, int>, rows: list<array<string, mixed>>} */
    public function run(int $limit = 200): array
    {
        $rows = DB::table('subscription_invoices as i')
            ->leftJoin('subscription_payments as p', 'p.id', '=', 'i.subscription_payment_id')
            ->leftJoin('tenants as t', 't.id', '=', 'i.tenant_id')
            ->whereNotIn('i.status', ['void'])
            ->orderBy('i.issued_at')
            ->get(['i.id', 'i.number', 'i.status', 'i.currency', 'i.subtotal', 'i.tax_total', 'i.total', 'i.amount_paid', 'i.tax_treatment',
                'i.issued_at', 't.name as tenant_name', 'p.amount as charged', 'p.status as charge_status']);

        $byIssue = array_fill_keys(self::ISSUES, 0);
        $flagged = [];
        $count = 0;
        foreach ($rows as $r) {
            $issues = [];
            if ($r->charged !== null && bccomp((string) $r->total, (string) $r->charged, 2) !== 0) {
                $issues[] = 'total_differs_from_charge';
            }
            if ($r->status === 'paid' && $r->charge_status === 'paid' && bccomp((string) $r->amount_paid, (string) $r->charged, 2) !== 0) {
                $issues[] = 'paid_amount_differs_from_collected';
            }
            if ($r->status === 'paid' && $r->charge_status !== 'paid') {
                $issues[] = 'paid_without_collection';
            }
            if ($r->tax_treatment === null) {
                $issues[] = 'no_tax_treatment';
            }
            foreach ($issues as $i) {
                $byIssue[$i]++;
            }
            $count += $issues === [] ? 0 : 1;
            if ($issues !== [] && count($flagged) < $limit) {
                $flagged[] = [
                    'invoice_id' => (string) $r->id, 'number' => $r->number, 'tenant' => $r->tenant_name, 'status' => $r->status,
                    'currency' => $r->currency, 'subtotal' => (string) $r->subtotal, 'tax_total' => (string) $r->tax_total,
                    'total' => (string) $r->total, 'amount_paid' => (string) $r->amount_paid,
                    'charged' => $r->charged === null ? null : (string) $r->charged, 'charge_status' => $r->charge_status,
                    'tax_treatment' => $r->tax_treatment, 'issued_at' => $r->issued_at, 'issues' => $issues,
                ];
            }
        }

        return ['checked' => $rows->count(), 'flagged' => $count, 'by_issue' => $byIssue, 'rows' => $flagged];
    }
}
