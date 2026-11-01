<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Subscriptions\Usage\CreditTransaction;
use App\Domains\Subscriptions\Usage\UsageLedger;
use App\Domains\Subscriptions\Usage\UsageMeter;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * PRICING-USAGE-CREDITS-001 — the console side: meters (actual unit cost, whether credits are charged, monthly allowances per
 * plan) and each workspace's credits (balance, ledger, hand adjustments).
 *
 * - A meter funded by the customer's own key can never be set to charge credits.
 * - Every change carries a reason and is audited; a credit adjustment is idempotent on the key the console sends, and can never
 *   take a balance below zero. Nothing here collects money: credits are granted by hand.
 */
final class PlatformUsageController extends Controller
{
    public function __construct(
        private readonly UsageLedger $ledger,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/admin/usage/meters */
    public function meters(): JsonResponse
    {
        return ApiResponse::success(['meters' => UsageMeter::query()->orderBy('sort_order')->get()->map(fn (UsageMeter $m) => $this->shape($m))->values()->all()], 'Meters.');
    }

    /** PATCH /api/v1/admin/usage/meters/{code} */
    public function update(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'charges_credits' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'included_per_plan' => ['sometimes', 'nullable', 'array'],
            'included_per_plan.*' => ['nullable', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $meter = UsageMeter::query()->where('code', $code)->firstOrFail();
        $charges = (bool) ($data['charges_credits'] ?? $meter->charges_credits);
        $cost = array_key_exists('unit_cost', $data) ? $data['unit_cost'] : $meter->unit_cost;
        // The customer's own key is paid to their provider; we never take credits for it.
        abort_if($charges && $meter->funded_by !== UsageMeter::PLATFORM, 422, __('billing.usage_own_key_never_charged'));
        // Charging needs the actual cost first.
        abort_if($charges && ($cost === null || (float) $cost <= 0), 422, __('billing.usage_cost_required'));

        $before = $this->shape($meter);
        $fill = collect($data)->except('reason')->all();
        if (array_key_exists('included_per_plan', $fill) && is_array($fill['included_per_plan'])) {
            $fill['included_per_plan'] = array_filter($fill['included_per_plan'], fn ($v) => $v !== null);
        }
        $meter->fill($fill)->save();
        $this->audit->log(action: 'platform.usage_meter.updated', entityType: 'usage_meter', entityId: $meter->code, before: $before,
            after: $this->shape($meter), reason: $data['reason'], userId: $request->user()?->id);

        return ApiResponse::success(['meter' => $this->shape($meter)], 'Saved.');
    }

    /** GET /api/v1/admin/tenants/{tenant}/credits */
    public function tenant(string $tenant): JsonResponse
    {
        $this->tenants->enterPlatformScope();
        $model = Tenant::query()->findOrFail($tenant);
        $id = (string) $model->getKey();

        return ApiResponse::success([
            'balance' => $this->ledger->balance($id),
            'currency' => 'SAR',
            'meters' => $this->ledger->summary($model),
            'transactions' => CreditTransaction::query()->where('tenant_id', $id)->orderByDesc('id')->limit(50)->get()
                ->map(fn (CreditTransaction $t) => [
                    'id' => $t->id, 'kind' => $t->kind, 'amount' => bcadd((string) $t->amount, '0', 2),
                    'balance_after' => bcadd((string) $t->balance_after, '0', 2), 'reason' => $t->reason,
                    'actor_id' => $t->actor_id, 'at' => $t->created_at?->toIso8601String(),
                ])->values()->all(),
        ], 'Workspace credits.');
    }

    /** POST /api/v1/admin/tenants/{tenant}/credits — add or take back credits by hand. */
    public function adjust(Request $request, string $tenant): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'between:-100000,100000', 'not_in:0'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);
        $this->tenants->enterPlatformScope();
        $model = Tenant::query()->findOrFail($tenant);
        $id = (string) $model->getKey();
        $before = $this->ledger->balance($id);

        try {
            $tx = $this->ledger->adjust($id, (string) $data['amount'], $data['reason'], $request->user()?->id, 'console:'.$data['idempotency_key']);
        } catch (InvalidArgumentException $e) {
            abort(422, __('billing.'.$e->getMessage()));
        }
        if ($tx->wasRecentlyCreated) {
            $this->audit->log(action: 'platform.credits.adjusted', entityType: 'credit_account', entityId: $id,
                before: ['balance' => $before], after: ['balance' => $this->ledger->balance($id), 'amount' => (string) $tx->amount],
                reason: $data['reason'], userId: $request->user()?->id, tenantId: $id);
        }

        return ApiResponse::success(['balance' => $this->ledger->balance($id), 'transaction_id' => $tx->id], 'Credits adjusted.', status: $tx->wasRecentlyCreated ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function shape(UsageMeter $m): array
    {
        return [
            'code' => $m->code, 'name' => $m->name, 'name_ar' => $m->name_ar, 'unit' => $m->unit, 'funded_by' => $m->funded_by,
            'unit_cost' => $m->unit_cost === null ? null : (string) $m->unit_cost, 'currency' => $m->currency,
            'charges_credits' => $m->charges_credits, 'charges' => $m->charges(), 'included_per_plan' => $m->included_per_plan ?? (object) [],
            'is_active' => $m->is_active,
        ];
    }
}
