<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a stable request id (and honours an inbound correlation id) so every response and log
 * line can be tied together. The id is exposed back to the client via the X-Request-Id header and
 * echoed inside the response envelope meta.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-Id') ?: 'req_'.bin2hex(random_bytes(8));
        $correlationId = $request->headers->get('X-Correlation-Id') ?: $requestId;

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('correlation_id', $correlationId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
