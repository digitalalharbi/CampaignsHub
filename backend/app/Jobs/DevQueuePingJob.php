<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * DEV heartbeat job: when a worker processes it, it refreshes the queue heartbeat the /dev/status page reads.
 * Used by `php artisan dev:queue-ping` to prove the worker is actually draining jobs.
 */
final class DevQueuePingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Once. A heartbeat that retries is a heartbeat that lies.
     *
     * The whole value of this job is that a worker drained it NOW — `dev:queue-ping` waits for the
     * cache key and reports the queue healthy when it appears. A retry an hour later would write
     * `now()` from that later moment and refresh a heartbeat for a worker that had been dead the
     * whole time, which is worse than no heartbeat: it is a green light nobody can distinguish from
     * a real one.
     *
     * It also had no `$tries` at all, which Laravel reads as «retry forever» — an unbounded dev job
     * on a shared queue.
     */
    public int $tries = 1;

    public function __construct(public string $token) {}

    public function handle(): void
    {
        Cache::put('dev:queue:heartbeat', now(), now()->addMinutes(10));
        Cache::put('dev:queue:ping:'.$this->token, now(), now()->addMinutes(5));
    }
}
