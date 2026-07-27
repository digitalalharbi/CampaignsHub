<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiting is a PRODUCTION security control. Per-IP limits are correct against real traffic, but in
 * local/dev every request (and the whole E2E suite) originates from a single IP, so those same limits produce
 * spurious 429s that have nothing to do with abuse. This wrapper enforces the real ThrottleRequests behaviour
 * in production and is a no-op everywhere else. Named limiters + inline throttles keep their production
 * semantics unchanged; only non-production is relaxed.
 */
final class ConditionalThrottle extends ThrottleRequests
{
    /**
     * @param  string  ...$args  the same arguments Laravel passes to throttle:… (limiter name or maxAttempts,decay)
     */
    public function handle($request, Closure $next, ...$args): Response
    {
        if (! app()->environment('production')) {
            return $next($request);
        }

        return parent::handle($request, $next, ...$args);
    }
}
