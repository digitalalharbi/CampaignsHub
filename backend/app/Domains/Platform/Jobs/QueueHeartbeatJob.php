<?php

declare(strict_types=1);

namespace App\Domains\Platform\Jobs;

use App\Domains\Platform\Services\OperationalReadiness;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * PROD-001 — proof that a worker is alive, written by the worker itself.
 *
 * The scheduler dispatches this every minute and the worker records the moment it RUNS it. That
 * ordering is the whole design: a check that asked «is the queue reachable?» would pass with no
 * worker attached at all, because pushing a job onto Redis needs no worker — which is exactly the
 * failure the product kept having. Only a job that came back out proves somebody is consuming.
 *
 * It carries no payload and does no work, so it costs a worker nothing and leaks nothing into
 * Horizon's job list — where payloads are visible across tenants.
 */
final class QueueHeartbeatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * One try, no retry.
     *
     * A heartbeat that retried would keep reporting a healthy worker off a job dispatched minutes
     * ago, which is the opposite of what it is for. If this one is lost, the next minute's replaces
     * it.
     */
    public int $tries = 1;

    public function handle(OperationalReadiness $readiness): void
    {
        $readiness->markQueue();
    }
}
