<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Quote;
use App\Domains\Billing\Services\BillingService;
use App\Domains\Billing\Support\TaxTreatment;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tenant billing: quotes → invoices → payments. Tenant isolation comes from the models' global scope
 * (route-model binding 404s cross-tenant). Reads need billing.view; mutations billing.manage. The webhook
 * endpoint is intentionally public and unauthenticated — trust is established by verifying the signature
 * inside the provider adapter, never by the caller.
 */
final class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    public function quotes(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('billing.view'), 403);

        return ApiResponse::success(
            Quote::query()->latest('created_at')->limit(200)->get()->all(),
            'Quotes.',
        );
    }

    public function storeQuote(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('billing.manage'), 403);

        $data = $request->validate([
            'client_workspace_id' => ['nullable', 'uuid'],
            'request_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'number' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'size:3'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'tax_treatment' => ['nullable', 'string', Rule::in(TaxTreatment::keys())],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'total' => ['nullable', 'numeric', 'min:0'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $quote = $this->billing->createQuote(array_merge($data, [
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'created_by' => $request->user()->id, // guaranteed by the billing.manage guard above
        ]));

        return ApiResponse::success($quote, 'Quote created.', status: 201);
    }

    public function approveQuote(Request $request, Quote $quote): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('billing.manage'), 403);

        return ApiResponse::success(
            $this->billing->approveQuote($quote),
            'Quote approved and invoice issued.',
            status: 201,
        );
    }

    public function invoices(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('billing.view'), 403);

        $query = Invoice::query()->latest('created_at');
        if ($status = $request->string('status')->toString()) {
            if (in_array($status, ['draft', 'issued', 'paid', 'partially_paid', 'void', 'refunded'], true)) {
                $query->where('status', $status);
            }
        }

        return ApiResponse::success($query->limit(200)->get()->all(), 'Invoices.');
    }

    public function startPayment(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('billing.manage'), 403);

        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $payment = $this->billing->startPayment($invoice, [
            'provider' => $data['provider'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ]);

        return ApiResponse::success($payment, 'Payment session opened.', status: 201);
    }

    /**
     * PUBLIC webhook sink. No auth, no tenant middleware — the adapter verifies the signature. An unverified or
     * duplicate event is acknowledged (200) but moves nothing.
     */
    public function webhook(Request $request, string $provider): JsonResponse
    {
        /** @var array<string,string> $headers */
        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            $headers[$key] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        $event = $this->billing->handleWebhook($provider, $request->getContent(), $headers);

        return ApiResponse::success(
            ['event_id' => $event->event_id, 'verified' => $event->verified],
            'Webhook received.',
        );
    }
}
