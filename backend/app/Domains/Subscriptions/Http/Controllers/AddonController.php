<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Subscriptions\Addons\Addon;
use App\Domains\Subscriptions\Addons\TenantAddon;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PRICING-ADDONS-001 — the add-ons shop inside a workspace.
 *
 * Lists the catalogue with each add-on's real readiness and what this workspace holds. A request for a sold add-on is recorded
 * and grants nothing until the platform activates it: no charge and no capacity is taken from a click. An add-on that is not
 * ready (no price yet) cannot be requested at all.
 */
final class AddonController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /** GET /api/v1/subscriptions/addons */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('subscriptions.view'), 403);
        $held = TenantAddon::query()->where('tenant_id', $this->context->tenantId())->whereIn('status', ['requested', 'active'])->get()->groupBy('addon_id');

        return ApiResponse::success([
            'addons' => Addon::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn (Addon $a) => [
                'code' => $a->code, 'kind' => $a->kind, 'name' => $a->name, 'name_ar' => $a->name_ar,
                'summary_en' => $a->summary_en, 'summary_ar' => $a->summary_ar,
                'price_monthly' => $a->price_monthly === null ? null : (string) $a->price_monthly, 'currency' => $a->currency,
                'readiness' => $a->readiness, 'sold' => $a->isSold(), 'grants' => $a->grants,
                'active_quantity' => (int) ($held->get($a->getKey())?->where('status', 'active')->sum('quantity') ?? 0),
                'requested' => (bool) $held->get($a->getKey())?->contains('status', 'requested'),
            ])->values()->all(),
            // Prices are VAT-exclusive; the tax is added at invoicing where it applies.
            'prices_exclude_vat' => true,
        ], 'Add-ons.');
    }

    /** POST /api/v1/subscriptions/addons/{code}/request */
    public function request(Request $request, string $code): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('subscriptions.manage'), 403);
        $data = $request->validate(['quantity' => ['sometimes', 'integer', 'min:1', 'max:20']]);
        $addon = Addon::query()->where('code', $code)->firstOrFail();
        abort_unless($addon->isSold(), 422, __('billing.addon_not_available'));

        $tenantId = (string) $this->context->tenantId();
        abort_if(TenantAddon::query()->where('tenant_id', $tenantId)->where('addon_id', $addon->getKey())->where('status', 'requested')->exists(),
            409, __('billing.addon_already_requested'));

        $row = TenantAddon::query()->create([
            'tenant_id' => $tenantId, 'addon_id' => $addon->getKey(), 'quantity' => (int) ($data['quantity'] ?? 1),
            'status' => 'requested', 'source' => 'request', 'requested_by' => $request->user()->getKey(),
        ]);
        $this->audit->log(action: 'subscription.addon.requested', entityType: 'addon', entityId: $addon->code,
            after: ['quantity' => $row->quantity, 'price_monthly' => (string) $addon->price_monthly, 'currency' => $addon->currency]);

        return ApiResponse::success(['request_id' => $row->id, 'status' => 'requested'], __('billing.addon_requested'), status: 201);
    }
}
