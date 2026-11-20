<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Tenancy\Context\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * ANALYTICS-READ-CACHE-001 — Owner 2026-11-20: «التخزين المؤقت للتحليلات — نفّذه».
 *
 * The analytics page asks the same heavy read several times a minute (each panel, each refetch on focus), and on a one-CPU host
 * every one of them recomputed the same figures. A successful JSON answer to a GET is kept for a short time (60 seconds by
 * default) and served again to the SAME person for the SAME address: the key carries the tenant, the user, the full URL (path,
 * project, period, filters) and the language, so nobody is ever served another member's figures or another filter's answer.
 * Figures are at most a minute older than a fresh read; a sync lands every thirty minutes, so nothing a reader can act on is lost.
 */
final class CacheReadResponse
{
    public function handle(Request $request, Closure $next, string $seconds = '60'): Response
    {
        $user = $request->user();
        // Off in the test suite unless a test turns it on: suites that change data and read again in one request cycle
        // assert fresh figures, and a cache that outlives their write would make them test the cache, not the figures.
        $enabled = (bool) config('cache.read_cache', ! app()->runningUnitTests());
        if (! $enabled || ! $request->isMethod('GET') || $user === null) {
            return $next($request);
        }

        $key = 'read-cache|'.(string) app(TenantContext::class)->tenantId().'|'.(string) $user->getAuthIdentifier().'|'
            .sha1($request->getRequestUri().'|'.app()->getLocale().'|'.(string) $request->header('X-Locale'));

        $hit = Cache::get($key);
        if (is_string($hit)) {
            return response($hit, 200, ['Content-Type' => 'application/json', 'X-Read-Cache' => 'hit']);
        }

        $response = $next($request);
        if ($response->getStatusCode() === 200 && str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            $body = $response->getContent();
            if (is_string($body)) {
                Cache::put($key, $body, max(1, (int) $seconds));
            }
        }

        return $response;
    }
}
