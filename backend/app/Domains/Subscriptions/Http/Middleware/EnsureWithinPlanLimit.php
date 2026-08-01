<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Http\Middleware;

use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-CLOSED plan-limit gate for create actions. Applied as `EnsureWithinPlanLimit::class.':<metric>'` on a
 * create route (e.g. projects.store, campaigns.store). If the tenant would exceed its plan cap for the metric
 * the request is denied with an honest 403 — no silent truncation.
 *
 * Fail-OPEN by design where it must be: platform scope (no tenant) passes through, and a tenant with NO
 * subscription defaults to the most permissive plan (see {@see SubscriptionService::currentPlan()}), so this
 * never regresses an existing creation flow that predates subscriptions.
 *
 * Runs after `tenant`, so the current tenant is already resolved.
 */
final class EnsureWithinPlanLimit
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function handle(Request $request, Closure $next, string $metric): Response
    {
        $tenantId = $this->context->tenantId();
        if ($tenantId !== null) {
            $tenant = Tenant::find((string) $tenantId);
            if ($tenant !== null && ! $this->subscriptions->withinLimit($tenant, $metric)) {
                /*
                 * The refusal carries the NUMBERS (PLAN-003).
                 *
                 * "You have reached your plan limit" leaves somebody to guess what the limit is, how
                 * close they were, and whether upgrading would even help. Saying 25 of 25 answers all
                 * three in one line — and the contract asks for the usage shown against the limit,
                 * not merely for the block.
                 *
                 * Nothing is deleted and nothing is hidden: the create is refused, and everything the
                 * customer already has stays exactly where it is.
                 */
                $limit = $this->subscriptions->effectiveLimit($tenant, $metric);
                $used = $this->subscriptions->usage($tenant, $metric);

                /*
                 * RETURNED, not thrown.
                 *
                 * `abort()` with a Response raises HttpResponseException, and this application's
                 * exception handler renders that as a 500 — the refusal never reached the customer
                 * and looked like a crash instead. Returning from middleware short-circuits the
                 * pipeline with exactly this response.
                 */
                return response()->json([
                    'success' => false,
                    'message' => __('billing.plan_limit_reached', [
                        // A metric added later has no label yet, and its own key reads better in the
                        // sentence than the missing-key string `billing.metrics.whatever` would.
                        'metric' => Lang::has('billing.metrics.'.$metric) ? __('billing.metrics.'.$metric) : $metric,
                        'used' => $used,
                        'limit' => $limit,
                    ]),
                    'data' => null,
                    'errors' => null,
                    'meta' => [
                        'plan_limit' => true,
                        'metric' => $metric,
                        'used' => $used,
                        'limit' => $limit,
                        // Named so the interface can offer the upgrade rather than inventing a route.
                        'upgrade_path' => '/app/subscriptions',
                    ],
                ], 403);
            }
        }

        return $next($request);
    }
}
