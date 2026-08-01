<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Http\Controllers;

use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant subscriptions: read the plan catalogue, read the current subscription + honest usage/remaining, and
 * (ops-gated) change plan. Tenant isolation comes from the service scoping every query to the resolved tenant;
 * plan changes require the internal `subscriptions.manage` permission.
 */
final class SubscriptionController extends Controller
{
    /** The metrics surfaced in the usage summary — mirror the plan `limits` keys. */
    private const METERED = ['projects', 'team_members', 'connections', 'reports_per_month'];

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly TenantContext $context,
    ) {}

    /** GET /subscriptions/plans — the active plan catalogue (any authenticated tenant user). */
    public function plans(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('subscriptions.view'), 403);

        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('price_monthly')
            ->get()
            ->map(fn (SubscriptionPlan $p) => $this->planShape($p));

        return ApiResponse::success($plans->all(), 'Plans.');
    }

    /** GET /subscriptions/current — the tenant's current subscription + per-metric usage/remaining. */
    public function current(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('subscriptions.view'), 403);

        $tenant = $this->tenant();
        $subscription = $this->subscriptions->subscriptionFor($tenant);
        $plan = $this->subscriptions->currentPlan($tenant);

        return ApiResponse::success([
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->status,
                'seats' => $subscription->seats,
                'billing_interval' => $subscription->billing_interval,
                'unit_amount' => $subscription->unit_amount === null ? null : (string) $subscription->unit_amount,
                'currency' => $subscription->currency,
                // The period's START, so the interface can show how much of it is left rather than
                // only when it ends (PAY-002).
                'current_period_start' => $subscription->current_period_start?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                /*
                 * A change that is agreed but not in force.
                 *
                 * Reported separately from `plan` and never merged into it: the customer is
                 * entitled to what `plan` says until this one takes effect, and an interface that
                 * showed the coming plan as the current one would be describing a downgrade as
                 * already having happened.
                 */
                'scheduled_change' => $subscription->hasScheduledChange() ? [
                    'plan' => $subscription->scheduledPlan?->code,
                    'plan_name' => $subscription->scheduledPlan?->name,
                    'billing_interval' => $subscription->scheduled_billing_interval,
                    'unit_amount' => $subscription->scheduled_unit_amount === null ? null : (string) $subscription->scheduled_unit_amount,
                    'effective_at' => $subscription->scheduled_change_at?->toIso8601String(),
                    // No date means it is waiting on a payment rather than on the calendar.
                    'awaiting_payment' => $subscription->scheduled_change_at === null,
                ] : null,
            ],
            'plan' => $plan === null ? null : $this->planShape($plan),
            'is_default_plan' => $subscription === null, // no subscription → defaulted to the most permissive plan
            'usage' => $this->subscriptions->usageSummary($tenant, self::METERED),
        ], 'Current subscription.');
    }

    /**
     * POST /subscriptions/change — the PLATFORM OWNER puts a tenant on a plan. No money changes hands.
     *
     * Restricted to `is_platform_admin`, and that restriction is the point. It used to be gated on
     * `subscriptions.manage`, which every workspace owner holds — so a customer could call it and
     * assign themselves the largest plan for nothing, straight past the checkout, the webhook and
     * the whole activation contract. Everything PAY-002/003 enforce could be skipped with one POST.
     *
     * A customer changing their own plan goes through {@see SubscriptionPlanChangeController}, where
     * the difference is priced and an upgrade waits for a verified payment. This one stays for what
     * it was always for: an operator granting a plan as an exception, with an audit trail.
     */
    public function change(Request $request): JsonResponse
    {
        abort_unless($request->user()?->is_platform_admin, 403, __('api.unauthorized'));

        $data = $request->validate([
            'plan_code' => ['required', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'in:trialing,active,past_due,canceled'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $plan = SubscriptionPlan::query()->where('code', $data['plan_code'])->where('is_active', true)->first();
        abort_if($plan === null, 404, 'Plan not found.');

        $subscription = $this->subscriptions->assignPlan(
            $this->tenant(),
            $plan,
            $data['status'] ?? 'active',
            null,
            $data['seats'] ?? null,
        );

        return ApiResponse::success([
            'subscription' => [
                'status' => $subscription->status,
                'seats' => $subscription->seats,
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            ],
            'plan' => $this->planShape($plan),
        ], 'Subscription updated.', status: 201);
    }

    private function tenant(): Tenant
    {
        $tenantId = $this->context->tenantId();
        abort_if($tenantId === null, 400, 'No tenant in context.');
        $tenant = Tenant::find((string) $tenantId);
        abort_if($tenant === null, 404, 'Tenant not found.');

        return $tenant;
    }

    /** @return array<string,mixed> */
    private function planShape(SubscriptionPlan $plan): array
    {
        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'price_monthly' => (string) $plan->price_monthly,
            'currency' => $plan->currency,
            'features' => $plan->features ?? [],
            'limits' => $plan->limits ?? [],
        ];
    }
}
