<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plans, subscriptions and payments from the owner's side (ADMIN-002).
 *
 * Built ON the existing engines — `SubscriptionPlan`, `Subscription`, `Invoice`, `Payment` — never a
 * second one. What the owner needs that a tenant's own billing page cannot give is the CROSS-tenant
 * view: which plans exist, who is on them, and what has actually been collected.
 *
 * Money is reported per currency, never summed across them. A single total over mixed currencies is
 * a number that looks authoritative and means nothing, and the product already refuses to do it
 * elsewhere (`spend_currency_mode: mixed` on the client portfolio).
 *
 * And it reports COMMITTED subscription value, not cash: the invoices/payments ledger belongs to
 * agencies invoicing their clients, not to CampaignsHub invoicing tenants. See `revenue()`.
 */
final class PlatformBillingController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** GET /api/v1/admin/plans — the catalogue, with how many tenants are actually on each. */
    public function plans(): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $subscribers = Subscription::query()
            ->selectRaw('plan_id, status, count(*) as c')->groupBy('plan_id', 'status')->get();

        $plans = SubscriptionPlan::query()->orderBy('price_monthly')->get();

        return ApiResponse::success([
            'plans' => $plans->map(function (SubscriptionPlan $p) use ($subscribers) {
                $rows = $subscribers->where('plan_id', $p->getKey());

                return ['id' => (string) $p->getKey()] + $this->planRow($p) + [
                    // Split by status: a plan with 40 cancelled subscribers is not a plan with 40
                    // customers, and one number would say it was.
                    'subscribers' => [
                        'active' => (int) $rows->where('status', 'active')->sum('c'),
                        'total' => (int) $rows->sum('c'),
                    ],
                ];
            })->all(),
        ], 'Plans.');
    }

    /**
     * PATCH /api/v1/admin/plans/{plan} — availability and presentation only.
     *
     * Deliberately NOT the price. Changing the price of a plan people are already on is a billing
     * decision with contractual consequences, and doing it from a console with one field would apply
     * it silently to every existing subscriber. Deactivating stops new sign-ups and leaves existing
     * subscriptions alone, which is the safe half of the operation.
     */
    /**
     * A plan as the console reads and writes it — one shape, so the list and the save cannot drift.
     *
     * @return array<string, mixed>
     */
    private function planRow(SubscriptionPlan $p): array
    {
        return [
            'code' => $p->code,
            'name' => $p->name,
            'name_ar' => $p->name_ar,
            'summary_ar' => $p->summary_ar,
            'summary_en' => $p->summary_en,
            'currency' => $p->currency,
            'price_monthly' => (string) $p->price_monthly,
            // Null is a statement: this plan is not sold on an annual term.
            'price_annual' => $p->price_annual === null ? null : (string) $p->price_annual,
            'trial_fee' => (string) $p->trial_fee,
            'trial_days' => $p->trial_days,
            'trial_limits' => $p->trial_limits,
            'features' => $p->features ?? [],
            'limits' => $p->limits ?? [],
            'is_active' => (bool) $p->is_active,
            'is_public' => (bool) $p->is_public,
            'sort_order' => $p->sort_order,
        ];
    }

    public function updatePlan(Request $request, string $plan): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        /*
         * The commercial terms of a plan, editable from the console (PLAN-001).
         *
         * These were previously literals in a seeder, which meant changing a price or a trial length
         * was a deploy. The contract requires the trial's fee, duration and limits to be manageable
         * from /admin, and the same argument applies to the prices they convert into.
         *
         * `price_annual` accepts an explicit null — that is how a plan is withdrawn from the annual
         * term, and it has to be distinguishable from "not mentioned in this request".
         */
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'summary_ar' => ['sometimes', 'nullable', 'string', 'max:500'],
            'summary_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            'price_monthly' => ['sometimes', 'numeric', 'min:0'],
            'price_annual' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'trial_fee' => ['sometimes', 'numeric', 'min:0'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'trial_limits' => ['sometimes', 'nullable', 'array'],
            'limits' => ['sometimes', 'nullable', 'array'],
            'features' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            // Withdrawing a plan from SALE is not the same as switching it off: everyone already on
            // it must keep working, which is why these are two fields and not one.
            'is_public' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);

        /** @var SubscriptionPlan|null $model */
        $model = SubscriptionPlan::query()->whereKey($plan)->first();
        abort_if($model === null, 404);

        $tracked = [
            'name', 'name_ar', 'price_monthly', 'price_annual', 'trial_fee', 'trial_days',
            'trial_limits', 'limits', 'features', 'is_active', 'is_public', 'sort_order',
        ];
        $before = $model->only($tracked);
        $model->fill($data)->save();

        AuditLog::create([
            'user_id' => $request->user()?->getKey(),
            'action' => 'platform.plan.updated',
            'entity_type' => SubscriptionPlan::class,
            'entity_id' => (string) $model->getKey(),
            'before' => $before,
            'after' => $model->only($tracked),
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success(
            ['plan' => ['id' => (string) $model->getKey()] + $this->planRow($model->refresh())],
            'Plan updated.',
        );
    }

    /** GET /api/v1/admin/subscriptions — who is on what, across tenants. */
    public function subscriptions(Request $request): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $query = Subscription::query()->with(['plan'])->orderByDesc('created_at');

        if (($status = $request->query('status')) !== null && $status !== '') {
            $query->where('status', $status);
        }

        $page = $query->paginate(50);

        // One query for the names rather than a relation per row.
        $names = Tenant::query()
            ->whereIn('id', collect($page->items())->pluck('tenant_id')->all())
            ->pluck('name', 'id');

        return ApiResponse::success([
            'subscriptions' => collect($page->items())->map(fn (Subscription $s) => [
                'id' => (string) $s->getKey(),
                'tenant_id' => (string) $s->tenant_id,
                'tenant_name' => $names[$s->tenant_id] ?? null,
                'plan' => $s->plan?->name,
                'plan_code' => $s->plan?->code,
                'status' => $s->status,
                'seats' => $s->seats,
                'current_period_end' => $s->current_period_end?->toDateString(),
            ])->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
        ], 'Subscriptions.');
    }

    /**
     * GET /api/v1/admin/revenue — what the PLATFORM is owed by its tenants.
     *
     * This is the honest version, and it is smaller than it first appears. `invoices` and `payments`
     * carry a NOT NULL `client_workspace_id`: that ledger is an AGENCY invoicing ITS client, not
     * CampaignsHub invoicing a tenant. Summing it here would report customers' money as the
     * platform's own revenue — a figure that looks like a business result and is somebody else's.
     *
     * So what can be reported truthfully today is COMMITTED subscription value: active subscriptions
     * times their plan price, per currency. That is a forward commitment, not cash received, and it
     * says so — no payment has been taken, because the platform has no charging path yet.
     */
    public function revenue(): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $active = Subscription::query()->where('status', 'active')->with('plan')->get();

        $committed = $active
            ->filter(fn (Subscription $s) => $s->plan !== null)
            ->groupBy(fn (Subscription $s) => (string) $s->plan->currency)
            ->map(fn ($rows, $currency) => [
                'currency' => $currency,
                // Per currency, never one blended figure — a total across currencies looks
                // authoritative and means nothing.
                'monthly' => number_format(
                    $rows->sum(fn (Subscription $s) => (float) $s->plan->price_monthly),
                    2, '.', ''
                ),
                'subscriptions' => $rows->count(),
            ])->values()->all();

        return ApiResponse::success([
            'committed_monthly' => $committed,
            // Stated rather than implied. A console that showed a revenue figure with no charging
            // behind it would read as money received.
            'collection_status' => 'not_implemented',
            'note' => 'Committed subscription value only. CampaignsHub does not yet charge tenants: '
                .'the invoices/payments ledger is agency-to-client billing (client_workspace_id is '
                .'NOT NULL) and is deliberately NOT counted here.',
        ], 'Platform revenue.');
    }
}
