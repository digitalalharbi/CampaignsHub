<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Http\Controllers;

use App\Domains\Subscriptions\Models\SubscriptionInvoice;
use App\Domains\Subscriptions\Services\SubscriptionInvoicing;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Frontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A customer's own CampaignsHub invoices (SUBINV-001).
 *
 * Scoped by hand rather than by the tenant global scope, because these documents are deliberately not
 * tenant-scoped in the model: the first one a customer ever receives is issued before their workspace
 * exists. Every read here therefore names the tenant explicitly, and a document belonging to another
 * one is a 404 rather than an empty list — an empty list would be indistinguishable from having none.
 *
 * These are OURS to the customer. An agency's invoices to its own clients are a different surface
 * entirely (`/api/v1/billing`), and mixing them would put a customer's own bills behind the
 * permission that governs their clients' bills.
 */
final class SubscriptionInvoiceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SubscriptionInvoicing $invoicing,
    ) {}

    /** GET /api/v1/subscriptions/invoices */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenants->tenantId();

        $invoices = SubscriptionInvoice::query()
            ->where('tenant_id', $tenantId)
            ->with('lines')
            ->orderByDesc('issued_at')
            ->limit(100)
            ->get();

        return ApiResponse::success([
            'invoices' => $invoices->map(fn (SubscriptionInvoice $i) => $this->present($i))->all(),
        ], 'Subscription invoices.');
    }

    /** GET /api/v1/subscriptions/invoices/{invoice} */
    public function show(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        $this->assertBelongsToCaller($invoice);

        return ApiResponse::success(['invoice' => $this->present($invoice->load('lines'))], 'Subscription invoice.');
    }

    /**
     * POST /api/v1/subscriptions/invoices/{invoice}/share
     *
     * Mints a link so the document can go to somebody's accountant, who has no account here.
     */
    public function share(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        $this->assertBelongsToCaller($invoice);
        abort_unless($request->user()?->hasPermission('subscriptions.manage'), 403);

        $invoice = $this->invoicing->share($invoice);

        return ApiResponse::success([
            'share_url' => $this->shareUrl($invoice),
            'invoice' => $this->present($invoice->load('lines')),
        ], 'A share link was created.');
    }

    /** DELETE /api/v1/subscriptions/invoices/{invoice}/share — any link already sent stops working. */
    public function revokeShare(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        $this->assertBelongsToCaller($invoice);
        abort_unless($request->user()?->hasPermission('subscriptions.manage'), 403);

        return ApiResponse::success(
            ['invoice' => $this->present($this->invoicing->revokeShare($invoice)->load('lines'))],
            'The share link was revoked.',
        );
    }

    /**
     * GET /api/v1/subscriptions/invoices/shared/{token} — PUBLIC.
     *
     * The token IS the authorisation, which is why it is 48 random characters and why revoking is
     * removing it. Nothing about the workspace is exposed beyond the document itself.
     */
    public function shared(string $token): JsonResponse
    {
        $invoice = SubscriptionInvoice::query()->where('share_token', $token)->with('lines')->first();

        abort_if($invoice === null, 404);

        return ApiResponse::success(['invoice' => $this->present($invoice)], 'Subscription invoice.');
    }

    /**
     * GET /api/v1/subscriptions/invoices/{invoice}/download
     *
     * Plain text, not a PDF. A PDF generator that has never rendered Arabic correctly is worse than
     * no download at all — this repository has already fixed one Arabic text-layer defect — and the
     * document a customer needs is the one they can hand to an accountant, which this is. When a
     * renderer is added the endpoint changes shape, not meaning.
     */
    public function download(Request $request, SubscriptionInvoice $invoice): Response
    {
        $this->assertBelongsToCaller($invoice);

        return response($this->asText($invoice->load('lines')), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.txt"',
        ]);
    }

    /**
     * A document that is not this caller's is a 404.
     *
     * Not a 403: telling somebody an invoice exists but is not theirs is telling them an invoice
     * exists.
     */
    private function assertBelongsToCaller(SubscriptionInvoice $invoice): void
    {
        abort_if($invoice->tenant_id !== $this->tenants->tenantId(), 404);
    }

    private function shareUrl(SubscriptionInvoice $invoice): ?string
    {
        return $invoice->share_token === null
            ? null
            : Frontend::origin().'/invoices/'.$invoice->share_token;
    }

    /** @return array<string, mixed> */
    private function present(SubscriptionInvoice $invoice): array
    {
        return [
            'id' => (string) $invoice->getKey(),
            'number' => $invoice->number,
            'status' => $invoice->status,
            'bill_to' => [
                'name' => $invoice->bill_to_name,
                'email' => $invoice->bill_to_email,
                'tax_number' => $invoice->bill_to_tax_number,
            ],
            'currency' => $invoice->currency,
            'subtotal' => (string) $invoice->subtotal,
            'discount_total' => (string) $invoice->discount_total,
            'tax_treatment' => $invoice->tax_treatment,
            'tax_rate' => (string) $invoice->tax_rate,
            'tax_total' => (string) $invoice->tax_total,
            'total' => (string) $invoice->total,
            'amount_paid' => (string) $invoice->amount_paid,
            'outstanding' => $invoice->outstanding(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'refunded_at' => $invoice->refunded_at?->toIso8601String(),
            'void_reason' => $invoice->void_reason,
            'is_shared' => $invoice->isShared(),
            'share_url' => $this->shareUrl($invoice),
            'lines' => $invoice->lines->map(fn ($l) => [
                'description' => $l->description,
                'plan_code' => $l->plan_code,
                'period' => $l->period_label,
                'quantity' => (string) $l->quantity,
                'unit_price' => (string) $l->unit_price,
                'discount' => (string) $l->discount,
                'line_total' => (string) $l->line_total,
            ])->all(),
        ];
    }

    private function asText(SubscriptionInvoice $invoice): string
    {
        $lines = ["CampaignsHub — {$invoice->number}", str_repeat('=', 40), ''];
        $lines[] = "To: {$invoice->bill_to_name} <{$invoice->bill_to_email}>";
        if ($invoice->bill_to_tax_number !== null) {
            $lines[] = "Tax number: {$invoice->bill_to_tax_number}";
        }
        $lines[] = 'Issued: '.($invoice->issued_at?->toDateString() ?? '');
        $lines[] = 'Status: '.$invoice->status;
        $lines[] = '';

        foreach ($invoice->lines as $line) {
            $lines[] = "{$line->description} — {$line->line_total} {$invoice->currency}";
        }

        $lines[] = '';
        $lines[] = "Subtotal: {$invoice->subtotal} {$invoice->currency}";
        if ((float) $invoice->discount_total > 0) {
            $lines[] = "Discount: -{$invoice->discount_total} {$invoice->currency}";
        }
        // The treatment is named, not only the amount: `zero_rated` and `exempt` both compute to zero
        // and are different statements to a tax authority.
        $lines[] = "Tax ({$invoice->tax_treatment}): {$invoice->tax_total} {$invoice->currency}";
        $lines[] = "Total: {$invoice->total} {$invoice->currency}";
        $lines[] = 'Outstanding: '.$invoice->outstanding()." {$invoice->currency}";

        return implode("\n", $lines)."\n";
    }
}
