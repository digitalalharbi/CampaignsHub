<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Platform\Services\OperationalReadiness;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Liveness and readiness probes. Intentionally minimal and safe to expose publicly — it never
 * leaks framework/server versions or internal hostnames.
 */
final class HealthController extends Controller
{
    /** Liveness: the app process is up and can respond. */
    public function health(): JsonResponse
    {
        return ApiResponse::success(
            /*
             * The product's own name — BRAND-001.
             *
             * It read `mediabuying-api`, which is what this platform was called before it was
             * CampaignsHub. A health endpoint is a public surface: it is what a monitor, a status
             * page and an uptime checker quote back, so the one place the old name survived was
             * also one of the few that a customer could see.
             */
            ['status' => 'ok', 'service' => config('brand.name').' API'],
            'Service is alive.',
        );
    }

    /**
     * Readiness: can THIS node serve a request.
     *
     * Narrow on purpose (PROD-001). It probes the datastores the application is actually configured
     * to use — the previous version pinged Redis unconditionally, so a deployment on the database
     * queue and database sessions, which is what `config/queue.php` still defaults to, was reported
     * unready for a dependency it does not have and would never have entered rotation.
     *
     * It deliberately does NOT fail on a stopped queue worker or scheduler. Those are serious faults
     * and they belong on the operator's status endpoint, where somebody can act on them; failing
     * readiness would pull healthy web nodes out of the load balancer and turn a delayed report into
     * an outage.
     */
    public function ready(OperationalReadiness $readiness): JsonResponse
    {
        $result = $readiness->serving();

        return $result['ready']
            ? ApiResponse::success(['status' => 'ready', 'checks' => $result['checks']], 'All dependencies are healthy.')
            : ApiResponse::error('One or more dependencies are unavailable.', null, ['checks' => $result['checks']], 503);
    }
}
