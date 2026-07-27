<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiting is a security control that stays fully enforced in production AND staging. The ONLY situation
 * it may be relaxed is a local E2E run, where the entire browser suite originates from a single IP and would
 * otherwise hit per-IP limits that have nothing to do with abuse.
 *
 * Relaxation requires an EXPLICIT opt-in (E2E_RELAX_RATE_LIMITS=true, default false) AND is hard-refused in
 * production and staging regardless of that flag — a mis-set env can never disable the limits there. The
 * `testing` (PHPUnit) environment keeps limits active by default so rate-limit behaviour stays testable.
 */
final class ConditionalThrottle extends ThrottleRequests
{
    /** @param  string  ...$args  same args Laravel passes to throttle:… (limiter name, or maxAttempts,decay) */
    public function handle($request, Closure $next, ...$args): Response
    {
        if (self::relaxationAllowed()) {
            return $next($request);
        }

        return parent::handle($request, $next, ...$args);
    }

    /**
     * Whether per-IP throttling may be skipped. Allow-listed to the `local` environment ONLY (never
     * production, staging, or testing — PHPUnit keeps limits active and testable), and only with the explicit
     * opt-in flag. Public + static so the security invariant is directly unit-testable.
     */
    public static function relaxationAllowed(): bool
    {
        // Positive allow-list: any environment other than local always enforces throttling.
        if (! app()->environment('local')) {
            return false;
        }

        return (bool) config('security.relax_rate_limits', false);
    }
}
