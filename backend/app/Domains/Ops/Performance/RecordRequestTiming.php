<?php

declare(strict_types=1);

namespace App\Domains\Ops\Performance;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** PERF-RECOVERY-001 — one duration per request, filed under the route's pattern and never its address. */
final class RecordRequestTiming
{
    public function __construct(private readonly PerformanceRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);

        $response = $next($request);

        $route = $request->route();
        $pattern = is_object($route) ? $route->uri() : 'unmatched';
        // PERF-RECOVERY-001 — a live read answered from its last build is timed apart from one that built.
        $variant = $request->attributes->get('perf_variant');
        if (is_string($variant) && $variant !== '') {
            $pattern .= ' ['.$variant.']';
        }
        $this->recorder->noteRequest($request->method().' '.$pattern, (hrtime(true) - $started) / 1e6);

        return $response;
    }
}
