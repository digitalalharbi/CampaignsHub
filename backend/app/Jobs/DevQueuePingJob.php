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

    public function __construct(public string $token) {}

    public function handle(): void
    {
        Cache::put('dev:queue:heartbeat', now(), now()->addMinutes(10));
        Cache::put('dev:queue:ping:'.$this->token, now(), now()->addMinutes(5));
    }
}
