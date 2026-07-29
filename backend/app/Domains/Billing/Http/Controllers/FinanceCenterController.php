<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Quote;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * FINANCE-001 — the unified finance center. Quotes, invoices and payments used to be three separate
 * lists with no consolidated picture; this is the single read model behind /app/finance.
 *
 * Two rules shape every figure here:
 *  - Money owed is computed as `total - amount_paid` per invoice, never as a stored "balance" field
 *    that could drift from the payments actually recorded.
 *  - Nothing is presented as collected unless a payment row says so. A payment that is pending or
 *    failed is reported in its own bucket, never folded into revenue.
 */
final class FinanceCenterController extends Controller
{
    /** Invoice states that still owe money (a draft has not been issued, a cancelled one is void). */
    private const OWING_STATES = ['issued', 'sent', 'partially_paid', 'overdue'];

    /** GET billing/overview — the consolidated KPI picture across quotes, invoices and payments. */
    public function overview(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('billing.view'), 403);

        $now = Carbon::now();

        $quotes = Quote::query()
            ->select('status')
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(total), 0) AS total')
            ->groupBy('status')->get();

        $invoices = Invoice::query()
            ->select('status')
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(amount_paid), 0) AS paid')
            ->groupBy('status')->get();

        $payments = Payment::query()
            ->select('status')
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('status')->get();

        // Outstanding = what issued invoices still owe. Computed from the invoices themselves.
        $open = Invoice::query()->whereIn('status', self::OWING_STATES)->get(['id', 'number', 'total', 'amount_paid', 'due_date', 'status', 'currency', 'client_workspace_id']);

        $outstanding = 0.0;
        $aging = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0];
        $overdueCount = 0;

        foreach ($open as $inv) {
            $due = max(0.0, (float) $inv->total - (float) $inv->amount_paid);
            if ($due <= 0) {
                continue;
            }
            $outstanding += $due;

            $dueDate = $inv->due_date ? Carbon::parse($inv->due_date) : null;
            $daysLate = $dueDate && $dueDate->isPast() ? $dueDate->diffInDays($now) : 0;
            if ($daysLate > 0) {
                $overdueCount++;
            }

            $bucket = match (true) {
                $daysLate <= 0 => 'current',
                $daysLate <= 30 => 'd1_30',
                $daysLate <= 60 => 'd31_60',
                $daysLate <= 90 => 'd61_90',
                default => 'd90_plus',
            };
            $aging[$bucket] += $due;
        }

        $invoicedTotal = (float) $invoices->sum('total');
        $collected = (float) $invoices->sum('paid');

        return ApiResponse::success([
            'quotes' => [
                'by_status' => $this->bucket($quotes),
                'count' => (int) $quotes->sum('count'),
                'total' => round((float) $quotes->sum('total'), 2),
                // Approved quotes are the pipeline that should become invoices.
                'approved_total' => round((float) $quotes->firstWhere('status', 'approved')?->total ?? 0, 2),
            ],
            'invoices' => [
                'by_status' => $this->bucket($invoices),
                'count' => (int) $invoices->sum('count'),
                'total' => round($invoicedTotal, 2),
                'collected' => round($collected, 2),
                'outstanding' => round($outstanding, 2),
                'overdue_count' => $overdueCount,
                // Share of invoiced money actually collected — null (not 0) when nothing was invoiced.
                'collection_rate' => $invoicedTotal > 0 ? round($collected / $invoicedTotal, 4) : null,
            ],
            'payments' => [
                'by_status' => $this->bucket($payments),
                'count' => (int) $payments->sum('count'),
                // Only succeeded payments count as money in. Pending/failed stay in their own buckets.
                'succeeded_total' => round((float) ($payments->firstWhere('status', 'succeeded')?->total ?? 0), 2),
            ],
            'aging' => array_map(fn (float $v) => round($v, 2), $aging),
            'currency' => $open->first()->currency ?? 'SAR',
        ], 'Finance overview.');
    }

    /**
     * GET billing/payments — the payments ledger, which had no HTTP surface at all.
     * Statuses are passed through exactly as recorded; a pending payment is never shown as collected.
     */
    public function payments(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('billing.view'), 403);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'provider' => ['nullable', 'string', 'max:32'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $rows = Payment::query()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['provider'] ?? null, fn ($q, $v) => $q->where('provider', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('provider_payment_id', 'ilike', "%{$v}%")
                ->orWhere('provider_session_id', 'ilike', "%{$v}%")))
            ->with('invoice:id,number,client_workspace_id,total,currency')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return ApiResponse::success($rows->map(fn (Payment $p) => [
            'id' => $p->id,
            'provider' => $p->provider,
            'provider_payment_id' => $p->provider_payment_id,
            'amount' => (float) $p->amount,
            'currency' => $p->currency,
            'status' => $p->status,
            'error' => $p->error,
            'paid_at' => optional($p->paid_at)->toIso8601String(),
            'created_at' => optional($p->created_at)->toIso8601String(),
            'invoice' => $p->invoice ? [
                'id' => $p->invoice->id,
                'number' => $p->invoice->number,
                'total' => (float) $p->invoice->total,
            ] : null,
        ])->all(), 'Payments.');
    }

    /**
     * GET billing/receivables — open invoices ordered by how late they are, so collections work has a
     * real worklist instead of an unsorted invoice table.
     */
    public function receivables(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('billing.view'), 403);

        $now = Carbon::now();

        $rows = Invoice::query()
            ->whereIn('status', self::OWING_STATES)
            ->with('clientWorkspace:id,name')
            ->orderByRaw('due_date NULLS LAST')
            ->get()
            ->map(function (Invoice $i) use ($now): ?array {
                $due = round(max(0.0, (float) $i->total - (float) $i->amount_paid), 2);
                if ($due <= 0) {
                    return null;
                }
                $dueDate = $i->due_date ? Carbon::parse($i->due_date) : null;

                return [
                    'id' => $i->id,
                    'number' => $i->number,
                    'client' => $i->clientWorkspace?->name,
                    'status' => $i->status,
                    'total' => (float) $i->total,
                    'amount_paid' => (float) $i->amount_paid,
                    'due' => $due,
                    'currency' => $i->currency,
                    'due_date' => $dueDate?->toDateString(),
                    'days_late' => $dueDate && $dueDate->isPast() ? (int) $dueDate->diffInDays($now) : 0,
                ];
            })
            ->filter()
            ->values();

        return ApiResponse::success($rows->all(), 'Open receivables.');
    }

    /**
     * @param  Collection<int,object>  $rows
     * @return array<string,array{count:int,total:float}>
     */
    private function bucket($rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->status] = ['count' => (int) $r->count, 'total' => round((float) $r->total, 2)];
        }

        return $out;
    }
}
