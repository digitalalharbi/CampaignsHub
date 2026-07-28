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
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            ],
            'plan' => $plan === null ? null : $this->planShape($plan),
            'is_default_plan' => $subscription === null, // no subscription → defaulted to the most permissive plan
            'usage' => $this->subscriptions->usageSummary($tenant, self::METERED),
        ], 'Current subscription.');
    }

    /** POST /subscriptions/change — move the tenant onto a plan (internal/ops only). */
    public function change(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('subscriptions.manage'), 403);

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
