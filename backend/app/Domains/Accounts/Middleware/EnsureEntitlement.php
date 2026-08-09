<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Middleware;

use App\Domains\Accounts\Services\AccountEntitlements;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces entitlements at the API, so a section the rail does not show is also a section the
 * endpoint refuses — hiding a button has never been a boundary.
 *
 * Asks the question against the ACTIVE PORTAL (REG-001), which changes what it enforces. It used to
 * ask only "is this workspace a company?", so a `personal` workspace — the fallback for any account
 * type that was never set — was allowed the clients and requests endpoints from anywhere, including
 * from inside the advertiser portal. Now `app` does not offer `clients` at all, and the refusal
 * holds however the workspace is classified.
 *
 * Runs after `tenant` and after `ResolveMembership`, so both the tenant and the portal are settled.
 * Platform scope (no tenant) is unaffected — `/admin` is gated by `is_platform_admin` instead.
 */
final class EnsureEntitlement
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MembershipContext $memberships,
        private readonly AccountEntitlements $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $navKey): Response
    {
        $tenantId = $this->context->tenantId();
        if ($tenantId !== null) {
            $tenant = Tenant::find((string) $tenantId);
            $portal = $this->memberships->membership()?->portal;

            if ($tenant !== null && ! $this->entitlements->allows($tenant, $navKey, $portal)) {
                /*
                 * The refusal names what was refused and where to go — PAY-AUDIT-004.
                 *
                 * This was `abort(403, …)`, a sentence and nothing else, while the middleware
                 * standing next to it (`EnsureWithinPlanLimit`) already answered with the metric,
                 * the usage, the cap and an upgrade path. Two adjacent gates refusing the same
                 * customer to two different standards, and the leaner one is the one that refuses a
                 * whole SECTION.
                 *
                 * RETURNED rather than thrown, for the reason recorded on the other one: `abort()`
                 * with a Response raises HttpResponseException, and this application's handler
                 * renders that as a 500 — the refusal never reaches the customer and looks like a
                 * crash.
                 */
                return response()->json([
                    'success' => false,
                    'message' => __('accounts.portal_capability_unavailable'),
                    'data' => null,
                    'errors' => null,
                    'meta' => [
                        'entitlement' => true,
                        'capability' => $navKey,
                        // The plan in force, so the interface can say what it is upgrading FROM.
                        'plan' => $tenant->subscription_plan,
                        'upgrade_path' => '/app/subscriptions',
                    ],
                ], 403);
            }
        }

        return $next($request);
    }
}
