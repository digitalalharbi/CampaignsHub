<?php

declare(strict_types=1);

namespace App\Domains\Access\Middleware;

use App\Domains\Access\Support\AccessSections;
use App\Domains\Tenancy\Context\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ACCESS-SECTIONS-001 — refuses every route of a section switched off for the signed-in member, on the very next
 * request after the switch (the rows are read per request; nothing is cached in the session or the token).
 *
 * Appended to the `api` group and placed in the middleware priority after `ResolveMembership`, so the tenant is
 * bound when it asks. It only ever refuses: a guest, a request with no tenant, a platform operator and a route
 * outside the seven sections pass through to the route's own checks, which stay deny-by-default.
 */
final class EnforceSectionAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $section = AccessSections::forRoute($request->route()?->getName());
        if ($section === null || app(TenantContext::class)->tenantId() === null) {
            return $next($request);
        }

        $user = $request->user();
        if ($user !== null && method_exists($user, 'sectionSwitchedOff') && $user->sectionSwitchedOff($section)) {
            abort(403, 'This section is not available to you.');
        }

        return $next($request);
    }
}
