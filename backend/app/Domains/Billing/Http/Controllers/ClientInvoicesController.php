<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Models\ClientBillingProfile;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Services\ClientInvoiceService;
use App\Domains\Billing\Services\InvoicePdf;
use App\Domains\Billing\Support\ImmutableInvoice;
use App\Domains\Billing\Support\IncompleteFeeBasis;
use App\Domains\Billing\Support\TaxTreatment;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * INV-PROFILE-001, INV-LIFECYCLE-001, INV-PDF-001 — the agency's side of the client fee invoice.
 *
 * Every action is `billing.manage` AND scoped to a client the operator can reach (`ClientAccess`), so an
 * operator narrowed to some clients cannot draft, issue or read another client's invoice by guessing an id.
 */
final class ClientInvoicesController extends Controller
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly ClientInvoiceService $invoices,
        private readonly InvoicePdf $pdf,
    ) {}

    /** GET /billing/clients/{client}/profile */
    public function profile(Request $request, string $client): JsonResponse
    {
        $workspace = $this->access->resolve($client);
        $this->access->assert($request->user(), 'billing.manage', $workspace);

        return ApiResponse::success(ClientBillingProfile::query()->where('client_workspace_id', $workspace->getKey())->first(), 'Billing profile.');
    }

    /** PUT /billing/clients/{client}/profile */
    public function saveProfile(Request $request, string $client): JsonResponse
    {
        $workspace = $this->access->resolve($client);
        $this->access->assert($request->user(), 'billing.manage', $workspace);

        $data = $request->validate([
            'fee_model' => ['required', Rule::in(ClientBillingProfile::FEE_MODELS)],
            'fixed_amount' => ['nullable', 'numeric', 'min:0', 'required_if:fee_model,fixed'],
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_if:fee_model,percentage'],
            'minimum_fee' => ['nullable', 'numeric', 'min:0'],
            'cadence' => ['required', Rule::in(ClientBillingProfile::CADENCES)],
            'currency' => ['required', 'string', 'size:3'],
            'tax_treatment' => ['required', Rule::in(TaxTreatment::keys())],
            'due_days' => ['required', 'integer', 'min:0', 'max:180'],
            'issuer' => ['nullable', 'array'],
            'issuer.legal_name' => ['nullable', 'string', 'max:160'],
            'issuer.vat_number' => ['nullable', 'string', 'max:40'],
            'issuer.address' => ['nullable', 'string', 'max:300'],
            'bill_to' => ['nullable', 'array'],
            'bill_to.legal_name' => ['nullable', 'string', 'max:160'],
            'bill_to.vat_number' => ['nullable', 'string', 'max:40'],
            'bill_to.address' => ['nullable', 'string', 'max:300'],
            'payment_details' => ['nullable', 'array'],
            'payment_details.bank' => ['nullable', 'string', 'max:120'],
            'payment_details.beneficiary' => ['nullable', 'string', 'max:160'],
            'payment_details.iban' => ['nullable', 'string', 'max:40'],
            'payment_details.account_number' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        // The VAT treatment this product no longer offers for new documents is not accepted for a new profile.
        abort_if($data['tax_treatment'] === 'historical_5', 422, 'The 5% historical rate is not available for new documents.');
        $data['currency'] = strtoupper($data['currency']);

        $profile = ClientBillingProfile::query()->updateOrCreate(
            ['client_workspace_id' => $workspace->getKey()],
            $data + ['tenant_id' => $workspace->tenant_id],
        );

        return ApiResponse::success($profile, 'Billing profile saved.');
    }

    /** POST /billing/clients/{client}/invoices/draft — draft the period's invoice from the profile. */
    public function draft(Request $request, string $client): JsonResponse
    {
        $workspace = $this->access->resolve($client);
        $this->access->assert($request->user(), 'billing.manage', $workspace);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $profile = ClientBillingProfile::query()->where('client_workspace_id', $workspace->getKey())->first();
        abort_if($profile === null, 422, 'Set the client\'s billing profile first.');

        try {
            $invoice = $this->invoices->draftFor($profile, Carbon::parse($data['from']), Carbon::parse($data['to']), isset($data['amount']) ? (float) $data['amount'] : null);
        } catch (IncompleteFeeBasis|InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        return ApiResponse::success($this->shape($invoice), 'Draft invoice.', status: 201);
    }

    /** POST /billing/invoices/{invoice}/{action} — review, issue, send, payment, correct. */
    public function act(Request $request, Invoice $invoice, string $action): JsonResponse
    {
        $this->authorise($request, $invoice);
        $user = $request->user();

        try {
            $result = match ($action) {
                'review' => $this->invoices->review($invoice, $user),
                'issue' => $this->invoices->issue($invoice),
                'send' => $this->invoices->markSent($invoice),
                'payment' => $this->invoices->recordPayment($invoice, (float) $request->validate(['amount' => ['required', 'numeric', 'gt:0']])['amount'], $user),
                'correct' => $this->invoices->correct($invoice, (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'], $user),
                default => abort(404),
            };
        } catch (InvalidArgumentException|ImmutableInvoice $e) {
            return ApiResponse::error($e->getMessage(), status: 409);
        }

        return ApiResponse::success($this->shape($result->fresh()), 'Invoice updated.');
    }

    /** GET /billing/invoices/{invoice}/pdf?lang=ar|en */
    public function pdf(Request $request, Invoice $invoice): Response
    {
        $this->authorise($request, $invoice, 'billing.view');

        if (! $this->pdf->available()) {
            return ApiResponse::error('PDF rendering is not enabled on this deployment.', status: 503);
        }

        return response($this->pdf->render($invoice, (string) $request->query('lang', 'ar')), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.pdf"',
        ]);
    }

    private function authorise(Request $request, Invoice $invoice, string $permission = 'billing.manage'): void
    {
        $workspace = $this->access->resolve((string) $invoice->client_workspace_id);
        $this->access->assert($request->user(), $permission, $workspace);
    }

    /** @return array<string,mixed> */
    private function shape(Invoice $invoice): array
    {
        return [
            'id' => $invoice->getKey(), 'number' => $invoice->number, 'status' => $invoice->status, 'display_status' => $invoice->displayStatus(),
            'currency' => $invoice->currency, 'subtotal' => $invoice->subtotal, 'tax' => $invoice->tax, 'total' => $invoice->total,
            'amount_paid' => $invoice->amount_paid, 'period_start' => $invoice->period_start?->toDateString(), 'period_end' => $invoice->period_end?->toDateString(),
            'issued_at' => $invoice->issued_at?->toIso8601String(), 'due_date' => $invoice->due_date?->toDateString(), 'sent_at' => $invoice->sent_at?->toIso8601String(),
            'supersedes_id' => $invoice->supersedes_id,
        ];
    }
}
