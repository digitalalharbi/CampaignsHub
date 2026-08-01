<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Middleware;

use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Services\MembershipSelector;
use App\Domains\Tenancy\Services\PortalResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route group to one portal (ADR 0002): `portal:agency`, `portal:app`, and so on.
 *
 * Each portal is a separate authorisation surface, not a menu — so reaching an agency endpoint
 * requires an *agency membership*, and holding an advertiser membership in the same tenant is not
 * enough. This is enforced here in the backend, so calling the endpoint directly or tampering with
 * identifiers gains nothing that the interface would have hidden.
 *
 * If the active membership is for a different portal but the user genuinely holds one for the portal
 * being requested, we switch to it rather than refusing: following a link into a portal you belong to
 * should just work. Everything else is a 403.
 */
final class EnsurePortal
{
    public function __construct(
        private readonly MembershipContext $memberships,
        private readonly PortalResolver $resolver,
        private readonly MembershipSelector $selector,
        private readonly TenantContext $tenants,
    ) {}

    public function handle(Request $request, Closure $next, string ...$portals): Response
    {
        $user = $request->user();
        abort_if($user === null, 401, 'Authentication required.');

        $allowed = array_values(array_filter(array_map(
            static fn (string $p) => Portal::tryFrom($p),
            $portals,
        )));

        // A route asking for a portal that does not exist is a programming error, never an open door.
        abort_if($allowed === [], 500, 'Unknown portal for this route.');

        /*
         * A portal that is switched off is closed to everybody, membership or not (INFL-OFF-001).
         *
         * Enforced HERE rather than by deleting the route file, because the routes must come back
         * intact when the sub-system returns — and because a route that quietly disappears answers
         * 404, which reads as "you typed it wrong" instead of "this service is not available yet".
         *
         * `403` with a named message is what lets the interface redirect and SAY so. It is checked
         * before the membership lookup so that holding one grants nothing: the influencer operator's
         * membership is still a valid row, and it still opens nothing while the flag is false.
         */
        $offered = array_values(array_filter($allowed, fn (Portal $p) => $p->isEnabled()));

        abort_if($offered === [], 403, __('api.portal_unavailable'));

        $allowed = $offered;

        $active = $this->memberships->membership();

        if ($active !== null && in_array($active->portal, $allowed, true)) {
            return $next($request);
        }

        // Not the active portal — but the user may still hold it. Honour a membership they own.
        foreach ($allowed as $portal) {
            $held = $this->resolver->resolve($user, $portal);
            if ($held !== null && $held->portal === $portal) {
                $this->selector->select($request, $user, (string) $held->getKey());
                $this->memberships->set($held);
                // The tenant scope must move with the membership. Leaving it on the previous tenant
                // would run this request's queries against data the new membership cannot see.
                $this->tenants->setTenantId($held->tenant_id);

                return $next($request);
            }
        }

        abort(403, 'You do not have access to this portal.');
    }
}
