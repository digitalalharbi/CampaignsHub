<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Http\Middleware;

use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
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
                abort(403, "You have reached your plan limit for \"{$metric}\". Upgrade your plan to add more.");
            }
        }

        return $next($request);
    }
}
