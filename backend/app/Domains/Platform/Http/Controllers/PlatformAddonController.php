<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Subscriptions\Addons\Addon;
use App\Domains\Subscriptions\Addons\TenantAddon;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * PRICING-ADDONS-001 — the add-ons catalogue and every workspace's add-ons, run from the console.
 *
 * Price, readiness and visibility are edited here (with a reason, audited). A workspace's request is approved or declined here;
 * approving captures the price at that moment, so a later price change never re-prices it. The console may also grant an add-on
 * outright (an offline-paid invoice, a goodwill extension). No charge is made from here: collection stays with the gateway.
 */
final class PlatformAddonController extends Controller
{
    public function __construct(private readonly TenantContext $tenants, private readonly AuditLogger $audit) {}

    /** GET /api/v1/admin/addons — the catalogue, with how many workspaces hold each and how many are waiting. */
    public function index(): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        return ApiResponse::success(['addons' => Addon::query()->orderBy('sort_order')->get()->map(fn (Addon $a) => $this->shape($a) + [
            'active_workspaces' => TenantAddon::query()->where('addon_id', $a->getKey())->where('status', 'active')->distinct()->count('tenant_id'),
            'requests_waiting' => TenantAddon::query()->where('addon_id', $a->getKey())->where('status', 'requested')->count(),
        ])->values()->all()], 'Add-ons.');
    }

    /** PATCH /api/v1/admin/addons/{addon} */
    public function update(Request $request, string $addon): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'], 'name_ar' => ['sometimes', 'string', 'max:120'],
            'price_monthly' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'readiness' => ['sometimes', 'in:available,coming_soon'],
            'is_active' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $model = Addon::query()->findOrFail($addon);
        $readiness = $data['readiness'] ?? $model->readiness;
        $price = array_key_exists('price_monthly', $data) ? $data['price_monthly'] : $model->price_monthly;
        // Nothing is sold without a price — the rule that keeps an unready service from being charged for.
        abort_if($readiness === 'available' && $price === null, 422, 'An add-on that is sold needs a price.');
        $before = $this->shape($model);
        $model->fill(collect($data)->except('reason')->map(fn ($v, $k) => $k === 'currency' ? strtoupper((string) $v) : $v)->all())->save();
        $this->audit->log(action: 'platform.addon.updated', entityType: 'addon', entityId: $model->code, before: $before,
            after: $this->shape($model), reason: $data['reason'], userId: $request->user()?->id);

        return ApiResponse::success(['addon' => $this->shape($model)], 'Saved.');
    }

    /** GET /api/v1/admin/tenants/{tenant}/addons */
    public function tenant(string $tenant): JsonResponse
    {
        $this->tenants->enterPlatformScope();
        $model = Tenant::query()->findOrFail($tenant);

        return ApiResponse::success(['addons' => TenantAddon::query()->with('addon')->where('tenant_id', $model->getKey())
            ->orderByDesc('created_at')->get()->map(fn (TenantAddon $t) => [
                'id' => $t->id, 'code' => $t->addon?->code, 'name' => $t->addon?->name, 'name_ar' => $t->addon?->name_ar,
                'quantity' => $t->quantity, 'status' => $t->status, 'source' => $t->source,
                'unit_price' => $t->unit_price, 'currency' => $t->currency, 'reason' => $t->reason,
                'activated_at' => $t->activated_at?->toIso8601String(), 'created_at' => $t->created_at?->toIso8601String(),
            ])->values()->all()], 'Workspace add-ons.');
    }

    /** POST /api/v1/admin/tenants/{tenant}/addons — grant an add-on outright. */
    public function grant(Request $request, string $tenant): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string'], 'quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->tenants->enterPlatformScope();
        $model = Tenant::query()->findOrFail($tenant);
        $addon = Addon::query()->where('code', $data['code'])->firstOrFail();
        abort_unless($addon->readiness === 'available', 422, 'An add-on that is not ready cannot be activated.');

        $row = TenantAddon::query()->create([
            'tenant_id' => $model->getKey(), 'addon_id' => $addon->getKey(), 'quantity' => (int) ($data['quantity'] ?? 1),
            'status' => 'active', 'source' => 'admin', 'reason' => $data['reason'], 'decided_by' => $request->user()?->getKey(),
            'unit_price' => $addon->price_monthly, 'currency' => $addon->currency, 'activated_at' => Carbon::now(),
        ]);
        $this->audit->log(action: 'platform.tenant.addon_granted', entityType: 'addon', entityId: $addon->code,
            after: ['quantity' => $row->quantity, 'unit_price' => (string) $row->unit_price, 'currency' => $row->currency],
            reason: $data['reason'], userId: $request->user()?->id, tenantId: (string) $model->getKey());

        return $this->tenant((string) $model->getKey());
    }

    /** PATCH /api/v1/admin/tenant-addons/{id} — approve or decline a request, or cancel an active add-on. */
    public function decide(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,decline,cancel'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->tenants->enterPlatformScope();
        $row = TenantAddon::query()->with('addon')->findOrFail($id);
        $from = $row->status;

        $decision = (string) $data['decision'];
        $allowed = match ($decision) {
            'approve' => $from === 'requested' && (bool) $row->addon?->isSold(),
            'decline' => $from === 'requested',
            default => $from === 'active',
        };
        // Approve or decline a waiting request (approve only while the add-on is sold); cancel only what is active.
        abort_unless($allowed, 422, "This add-on cannot be {$decision}d from «{$from}».");
        $row->forceFill(match ($decision) {
            // The price at the moment of approval is the price this workspace pays for it.
            'approve' => ['status' => 'active', 'unit_price' => $row->addon?->price_monthly, 'currency' => $row->addon?->currency, 'activated_at' => Carbon::now()],
            'decline' => ['status' => 'declined', 'ended_at' => Carbon::now()],
            default => ['status' => 'cancelled', 'ended_at' => Carbon::now()],
        } + ['decided_by' => $request->user()?->getKey(), 'reason' => $data['reason']])->save();

        $this->audit->log(action: 'platform.tenant.addon_'.$decision.'d', entityType: 'addon', entityId: (string) $row->addon?->code,
            before: ['status' => $from], after: ['status' => $row->status, 'unit_price' => $row->unit_price === null ? null : (string) $row->unit_price],
            reason: $data['reason'], userId: $request->user()?->id, tenantId: (string) $row->tenant_id);

        return $this->tenant((string) $row->tenant_id);
    }

    /** @return array<string, mixed> */
    private function shape(Addon $a): array
    {
        return ['id' => $a->id, 'code' => $a->code, 'kind' => $a->kind, 'name' => $a->name, 'name_ar' => $a->name_ar,
            'price_monthly' => $a->price_monthly === null ? null : (string) $a->price_monthly, 'currency' => $a->currency,
            'readiness' => $a->readiness, 'is_active' => $a->is_active, 'grants' => $a->grants, 'sold' => $a->isSold()];
    }
}
