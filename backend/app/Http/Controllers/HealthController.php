<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

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
            ['status' => 'ok', 'service' => 'mediabuying-api'],
            'Service is alive.',
        );
    }

    /** Readiness: dependencies (database, redis) are reachable. */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo()),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
        ];

        $ready = ! in_array('down', $checks, true);

        return $ready
            ? ApiResponse::success(['status' => 'ready', 'checks' => $checks], 'All dependencies are healthy.')
            : ApiResponse::error('One or more dependencies are unavailable.', null, ['checks' => $checks], 503);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'up';
        } catch (Throwable) {
            return 'down';
        }
    }
}
