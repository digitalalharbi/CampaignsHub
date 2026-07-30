<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Middleware;

use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Services\MembershipSelector;
use App\Domains\Tenancy\Services\PortalResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the active membership, and derives the tenant scope FROM IT (ADR 0002).
 *
 * This replaces reading `users.tenant_id`, which could only ever describe one tenant per person and
 * therefore could not express an agency operator who is also a client elsewhere. The tenant a request
 * is confined to is now a property of the membership in play, not of the user record.
 *
 * The membership comes from exactly two places, in order:
 *   1. the one the user switched into, re-verified against the database every request;
 *   2. otherwise their default.
 *
 * Neither can be supplied by the client. A user with no membership gets no tenant scope at all —
 * fail-closed — so every tenant-scoped query returns nothing rather than silently falling back to
 * some other tenant's data.
 */
final class ResolveMembership
{
    public function __construct(
        private readonly MembershipContext $memberships,
        private readonly TenantContext $tenants,
        private readonly MembershipSelector $selector,
        private readonly PortalResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Platform staff legitimately operate across tenants and hold no membership. Identified by
        // the flag alone — pairing it with `tenant_id === null` would make the legacy column part of
        // an authorisation decision again.
        if ($user->is_platform_admin) {
            $this->tenants->enterPlatformScope();

            return $next($request);
        }

        $membership = $this->selector->selected($request, $user) ?? $this->resolver->resolve($user);

        if ($membership !== null) {
            $this->memberships->set($membership);
            $this->tenants->setTenantId($membership->tenant_id);

            return $next($request);
        }

        /*
         * No membership, no scope. FAIL-CLOSED, deliberately.
         *
         * `users.tenant_id` is NOT consulted here. It used to be, as a compatibility bridge, and that
         * bridge is gone: every user-creation path now provisions a membership (guaranteed by the
         * model hook on User), so a user reaching this point genuinely belongs nowhere. Falling back
         * to the column would hand them a tenant the membership layer never granted — the exact
         * coupling ADR 0002 removes.
         *
         * They keep no tenant scope at all, so every scoped query returns nothing rather than
         * quietly returning someone else's rows, and `EnsurePortal` refuses them every portal.
         */
        return $next($request);
    }

    /**
     * Tear the request's scope down once the response has been sent.
     *
     * Both contexts are bound `scoped`, which is enough for Octane (it forgets scoped instances
     * between requests) but says nothing about a long-lived container that handles two requests
     * without one — a queue worker, or a test process issuing several calls. Clearing them here makes
     * the lifetime explicit and identical everywhere: scope belongs to a request and dies with it.
     *
     * Without this, the *previous* request's tenant would still be set when a request arrives that
     * resolves no membership — and its queries would silently run against that tenant's data.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->memberships->forget();
        $this->tenants->forget();
    }
}
