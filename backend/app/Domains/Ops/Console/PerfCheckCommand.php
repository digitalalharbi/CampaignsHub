<?php

declare(strict_types=1);

namespace App\Domains\Ops\Console;

use App\Domains\Ops\Performance\PerformanceRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * PERF-RECOVERY-001 — says so when Production degrades, in the error log.
 *
 * A route is reported when its p95 passes the limit over the last hour with enough requests to mean
 * something, and a queue when its backlog passes the limit. The log is the only channel for now:
 * Production mail is refused by the SMTP server, so an alert sent by mail would reach nobody.
 */
final class PerfCheckCommand extends Command
{
    public const P95_LIMIT_MS = 2500;

    public const MIN_REQUESTS = 20;

    public const QUEUE_LIMIT = 500;

    protected $signature = 'ops:perf-check';

    protected $description = 'Log an error when a route p95 or a queue backlog passes its limit.';

    public function handle(PerformanceRecorder $recorder): int
    {
        foreach (self::degraded($recorder->requests(1)) as $route => $p95) {
            Log::error('PERF-RECOVERY-001: route over its latency limit', ['route' => $route, 'p95_ms_at_most' => $p95, 'limit_ms' => self::P95_LIMIT_MS]);
            $this->line("over limit: {$route} p95<={$p95}");
        }

        foreach (['default', 'reports'] as $queue) {
            $size = Queue::size($queue);

            if ($size > self::QUEUE_LIMIT) {
                Log::error('PERF-RECOVERY-001: queue backlog over its limit', ['queue' => $queue, 'waiting' => $size, 'limit' => self::QUEUE_LIMIT]);
                $this->line("backlog: {$queue} {$size}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{count:int, buckets:array<int,int>}>  $routes
     * @return array<string, int> route → p95 upper bound
     */
    public static function degraded(array $routes): array
    {
        $out = [];

        foreach ($routes as $route => $r) {
            $p95 = PerformanceRecorder::percentile($r['buckets'], 0.95);

            if ($r['count'] >= self::MIN_REQUESTS && $p95 !== null && $p95 > self::P95_LIMIT_MS) {
                $out[$route] = $p95;
            }
        }

        return $out;
    }
}
