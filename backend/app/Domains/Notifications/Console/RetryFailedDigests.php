<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Services\DigestDispatcher;
use Illuminate\Console\Command;

/** EMAIL-DELIVERY-LEDGER-001 — retries the digests whose backoff has passed. See `DigestDispatcher::retryDue()`. */
final class RetryFailedDigests extends Command
{
    protected $signature = 'notifications:retry-failed';

    protected $description = 'Retry failed daily, weekly and monthly digests whose backoff has passed.';

    public function handle(DigestDispatcher $dispatcher): int
    {
        $counts = $dispatcher->retryDue();

        $this->info($counts === [] ? 'nothing due' : http_build_query($counts, '', ' '));

        return self::SUCCESS;
    }
}
