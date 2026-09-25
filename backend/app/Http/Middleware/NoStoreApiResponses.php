<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUTH-CACHE-NO-STORE-001 — a per-user answer is not a cacheable document.
 *
 * ## The failure that found this
 *
 * `registration-onboarding.spec.ts` intermittently landed a freshly-registered account on `/switch`
 * instead of the onboarding wizard, on Firefox only, on two unrelated pull requests. Reproduced
 * locally, once in twenty-one runs, and its own diagnostic named the mechanism:
 *
 *     /auth/memberships answered [GET 304, GET 401, GET 401, GET 401]
 *
 * A **304**. The browser held a cached copy of one user's membership list and revalidated against it,
 * and `/switch` then rendered «You belong to more than one space» for an account that belongs to one.
 * `resolvePostAuthOutcome` falls to `/switch` when that call does not answer usefully, so a stale body
 * and a 401 arrive at the same wrong screen.
 *
 * ## Why the responses were cacheable at all
 *
 * Nothing said they were not. Laravel adds no cache directives to a JSON response, and HTTP lets a
 * cache store and heuristically reuse a response that carries no explicit freshness information.
 * Chromium rarely does this for XHR; Firefox does, which is exactly why the failure had a browser.
 *
 * ## Why this is a correctness rule and not a test repair
 *
 * `/auth/me`, `/auth/memberships` and every project-scoped endpoint answer «what may THIS person see».
 * A stored copy of that answer outlives the session it was computed for. The worst case is not a
 * flaky test: it is one person's workspace list surviving in a cache that another response is then
 * served from — on a shared machine, through a corporate proxy, or simply after a sign-out. So the
 * rule is `no-store, private`, applied to the API by default.
 *
 * ## It never overrides a deliberate decision
 *
 * `PublicPaidServiceController` sets an ETag and a `Cache-Control` on purpose: a public catalogue
 * SHOULD be cached, and revalidating it is the point. A response that has already stated its own
 * caching is left exactly as it is — this fills the silence rather than overruling anybody.
 */
final class NoStoreApiResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Global middleware, so it also sees the SPA's own documents and the public pages. Only the
        // API is per-user by default; an HTML page's caching is the frontend's business.
        if (! $request->is('api/*')) {
            return $response;
        }

        // Said its own mind already — the public catalogue, or anything else that means to be cached.
        if ($response->headers->has('Cache-Control') && $response->headers->get('Cache-Control') !== '' && ! $this->onlyFrameworkDefault($response)) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        // `Pragma` is HTTP/1.0 and redundant to a modern browser; it is here for the proxies that are
        // not modern browsers, which is the population this rule exists for.
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Laravel stamps `no-cache, private` on a response nobody configured.
     *
     * That is NOT a decision, and treating it as one would leave every ordinary API response exactly
     * as cacheable as it was: `no-cache` permits STORING the response and revalidating it, which is
     * the 304 above. Only `no-store` forbids keeping it.
     */
    private function onlyFrameworkDefault(Response $response): bool
    {
        $value = strtolower((string) $response->headers->get('Cache-Control'));
        $parts = array_map('trim', explode(',', $value));
        sort($parts);

        return $parts === ['no-cache', 'private'] || $parts === ['no-cache'] || $parts === ['private'];
    }
}
