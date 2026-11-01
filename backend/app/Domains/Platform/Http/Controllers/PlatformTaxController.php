<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Support\TaxTreatment;
use App\Domains\Subscriptions\Models\PlanCatalogueVersion;
use App\Domains\Subscriptions\Services\SubscriptionTax;
use App\Domains\Subscriptions\Services\VatInvoiceReview;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * PRICING-VAT-002 — VAT from the console: the treatment and the price basis of each catalogue edition, for NEW sales.
 *
 * - Only legal treatments are offered (standard 15%, zero-rated, exempt, out of scope); the legacy 5% never is, and no rate is
 *   free-typed — the rate is the treatment's.
 * - A change applies to sales made after it. Every subscription keeps the terms it was sold under, so nobody already subscribed
 *   is re-taxed or re-priced; the response says how many subscriptions keep their own terms.
 * - The invoice review is read-only: it flags documents for an accountant and changes none.
 */
final class PlatformTaxController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /api/v1/admin/tax */
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'treatments' => array_map(fn (string $t) => ['key' => $t, 'rate' => TaxTreatment::rate($t)], SubscriptionTax::OFFERED),
            'editions' => PlanCatalogueVersion::query()->orderBy('number')->get()->map(fn (PlanCatalogueVersion $v) => $this->shape($v))->values()->all(),
        ], 'Tax.');
    }

    /** PATCH /api/v1/admin/tax/editions/{edition} */
    public function update(Request $request, string $edition): JsonResponse
    {
        $data = $request->validate([
            'tax_treatment' => ['required', Rule::in(SubscriptionTax::OFFERED)],
            'prices_include_vat' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $model = PlanCatalogueVersion::query()->findOrFail($edition);
        $before = $this->shape($model);
        $model->forceFill(['tax_treatment' => $data['tax_treatment'], 'prices_include_vat' => (bool) $data['prices_include_vat']])->save();
        $this->audit->log(action: 'platform.tax.edition_updated', entityType: 'plan_catalogue_version', entityId: (string) $model->getKey(),
            before: $before, after: $this->shape($model), reason: $data['reason'], userId: $request->user()?->id);

        return ApiResponse::success(['edition' => $this->shape($model)], 'Saved. Applies to new sales; existing subscriptions keep their terms.');
    }

    /** GET /api/v1/admin/tax/invoice-review */
    public function review(VatInvoiceReview $review): JsonResponse
    {
        return ApiResponse::success($review->run(), 'Invoice review.');
    }

    /** @return array<string, mixed> */
    private function shape(PlanCatalogueVersion $v): array
    {
        $plans = DB::table('subscription_plans')->where('catalogue_version_id', $v->getKey())->pluck('id');

        return [
            'id' => (string) $v->getKey(), 'number' => $v->number, 'label' => $v->label, 'currency' => $v->currency, 'status' => $v->status,
            'tax_treatment' => $v->tax_treatment, 'rate' => TaxTreatment::rate($v->tax_treatment), 'prices_include_vat' => (bool) $v->prices_include_vat,
            // Subscriptions sold under this edition keep the terms captured on them; listed so the operator sees who is unaffected.
            'subscriptions_keeping_their_terms' => DB::table('subscriptions')->whereIn('plan_id', $plans)->where('status', '!=', 'cancelled')->count(),
        ];
    }
}
