<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DevQueuePingJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Dispatches a heartbeat job and waits for a worker to process it — proves the queue worker is draining jobs.
 * Exit 0 = processed, 1 = timed out (no worker). Dev/local aid used by scripts/dev-up.sh.
 */
final class DevQueuePing extends Command
{
    protected $signature = 'dev:queue-ping {--timeout=15}';

    protected $description = 'Dispatch a test job and confirm the queue worker processes it';

    public function handle(): int
    {
        $token = (string) Str::uuid();
        Cache::forget('dev:queue:ping:'.$token);
        DevQueuePingJob::dispatch($token);

        $deadline = time() + (int) $this->option('timeout');
        while (time() < $deadline) {
            if (Cache::get('dev:queue:ping:'.$token) !== null) {
                $this->info('queue worker OK (test job processed)');

                return self::SUCCESS;
            }
            usleep(400_000);
        }

        $this->error('queue worker did NOT process the test job within timeout');

        return self::FAILURE;
    }
}
