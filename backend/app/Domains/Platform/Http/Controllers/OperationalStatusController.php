<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Services\OperationalReadiness;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * PROD-001 — the operator's monitoring surface: is the deployment working?
 *
 * Shaped to be scraped. The HTTP status carries the verdict so an uptime check needs no JSON parsing
 * at all — 200 when everything is up, 503 when it is not — and the body says which part and what to
 * do about it. A monitor that has to read a field to learn something is broken is a monitor somebody
 * will misconfigure.
 *
 * `unverified` returns 200 deliberately. It means the heartbeats have not been seen YET, which is the
 * normal state of a deployment ninety seconds old or one whose cache was just flushed; paging on it
 * would mean paging on every release, and a monitor that cries wolf at every deploy is one nobody
 * reads by the third week.
 */
final class OperationalStatusController extends Controller
{
    public function __invoke(OperationalReadiness $readiness): JsonResponse
    {
        $status = $readiness->status();
        $ok = in_array($status['verdict'], ['healthy', 'unverified'], true);

        /*
         * The payload sits under `data` at BOTH statuses, which is why the failing case is not built
         * with `ApiResponse::error()`.
         *
         * That helper files its array under `meta` — right for a validation failure, wrong here. A
         * monitor scraping this URL would have to look in one place on a good day and another on a
         * bad one, and the bad day is the only day it matters. The envelope is the project's,
         * unchanged; only the HTTP status and the `success` flag carry the verdict, and the body
         * reads identically either way.
         */
        return $ok
            ? ApiResponse::success($status, 'Operational status.')
            : response()->json([
                'success' => false,
                'message' => 'The deployment is not fully operational.',
                'data' => $status,
                'meta' => ['request_id' => request()->headers->get('X-Request-Id')],
                'errors' => null,
            ], 503);
    }
}
