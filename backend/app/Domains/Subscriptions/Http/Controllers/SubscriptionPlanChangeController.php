<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Http\Controllers;

use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\PlanCatalogue;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use App\Domains\Subscriptions\Services\SubscriptionProration;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Changing plan part-way through a period the customer has already paid for (PAY-002).
 *
 * Deliberately separate from `SubscriptionController::change`, which is the OPS assignment: an
 * operator putting a tenant on a plan, with no money involved. This is the customer's own action,
 * and money is the entire difference between them.
 *
 * The shape is quote-then-commit rather than one call, because the numbers are the decision. A
 * customer asked to confirm «you will be charged 143.55 SAR now for the 21 days left, and 499.00 SAR
 * on the 4th of every month after that» is making a choice; one shown a Change button and then a
 * bank message is not.
 */
final class SubscriptionPlanChangeController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly SubscriptionProration $proration,
        private readonly PlanCatalogue $catalogue,
        private readonly SubscriptionCheckout $checkout,
        private readonly TenantContext $context,
    ) {}

    /**
     * POST /subscriptions/plan-change/quote — what it would cost, changing nothing.
     *
     * Safe to call as often as the interface likes: it opens no charge, writes no row and moves no
     * plan.
     */
    public function quote(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('subscriptions.view'), 403);

        [$subscription, $plan, $interval] = $this->resolve($request);

        return ApiResponse::success([
            'from' => [
                'plan' => $subscription->plan?->code,
                'plan_name' => $subscription->plan?->name,
                'interval' => $subscription->billing_interval,
                'unit_amount' => $subscription->unit_amount === null ? null : (string) $subscription->unit_amount,
            ],
            'to' => ['plan' => $plan->code, 'plan_name' => $plan->name, 'interval' => $interval],
            'quote' => $this->proration->quote($subscription, $plan, $interval),
        ], 'Plan change quote.');
    }

    /**
     * POST /subscriptions/plan-change — commit to it.
     *
     * An upgrade answers with a checkout to pay the difference and does NOT move the plan; a
     * downgrade answers with the date it takes effect and charges nothing. Both are decided by the
     * lifecycle, not here.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('subscriptions.manage'), 403);

        [$subscription, $plan, $interval] = $this->resolve($request);

        try {
            $result = $this->lifecycle->changePlan($subscription, $plan, $interval, $this->checkout);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        $charge = $result['charge'];

        return ApiResponse::success([
            'quote' => $result['quote'],
            'effective' => $result['quote']['effective'],
            'effective_at' => $result['quote']['effective_at'],
            // Only present when money is owed. `checkout_url` is null when no gateway is configured,
            // and the status says `awaiting_credentials` rather than pretending a payment page exists.
            'payment' => $charge === null ? null : [
                'status' => $charge['status'],
                'checkout_url' => $charge['checkout_url'],
                'amount' => (string) $charge['payment']->amount,
                'currency' => $charge['payment']->currency,
            ],
            /*
             * Said plainly, because it is the thing most easily assumed wrong: opening a charge is
             * not being on the new plan. The subscription moves when the gateway confirms the money,
             * and until then `plan` below is still what the customer is entitled to.
             */
            'plan' => $result['subscription']->refresh()->plan?->code,
            'scheduled_plan' => $result['subscription']->scheduledPlan?->code,
        ], 'Plan change recorded.', status: 201);
    }

    /** DELETE /subscriptions/plan-change — withdraw a change that has not taken effect. */
    public function destroy(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('subscriptions.manage'), 403);

        $subscription = $this->subscriptions->subscriptionFor($this->tenant());
        abort_if($subscription === null, 404, 'This workspace has no subscription.');

        $this->lifecycle->cancelScheduledChange($subscription, 'The customer withdrew the change.');

        return ApiResponse::success(['scheduled_plan' => null], 'The pending plan change was withdrawn.');
    }

    /** @return array{0: Subscription, 1: SubscriptionPlan, 2: string} */
    private function resolve(Request $request): array
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string', 'max:64'],
            'billing_interval' => ['required', 'string', 'in:monthly,annual'],
        ]);

        $subscription = $this->subscriptions->subscriptionFor($this->tenant());
        abort_if($subscription === null, 404, 'This workspace has no subscription to change.');

        /*
         * Only a plan the customer could have bought in the first place.
         *
         * `offered()` excludes private and inactive plans, so a code lifted from an old link or a
         * competitor's screenshot cannot be used to buy something that is not for sale.
         */
        $plan = $this->catalogue->byCode($data['plan_code']);
        abort_if($plan === null || ! $this->catalogue->isOffered($data['plan_code']), 404, __('billing.plan_not_available'));

        return [$subscription, $plan, $data['billing_interval']];
    }

    private function tenant(): Tenant
    {
        $tenantId = $this->context->tenantId();
        abort_if($tenantId === null, 400, 'No tenant in context.');
        $tenant = Tenant::find((string) $tenantId);
        abort_if($tenant === null, 404, 'Tenant not found.');

        return $tenant;
    }
}
