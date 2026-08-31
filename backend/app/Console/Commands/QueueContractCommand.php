<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Queue\QueueContract;
use Illuminate\Console\Command;

/**
 * SNAP-STRUCTURE-RETRY-001 — print the queue's timeout contract from the process that holds it.
 *
 * A repository can say `retry_after` is 1200 while the container serving production holds 90, because
 * `config:cache`, an environment file and a worker that booted an hour ago are three different
 * answers to the same question. This command asks the running application, which is the only one of
 * the three that matters, and fails the deploy if the answer is wrong.
 */
final class QueueContractCommand extends Command
{
    protected $signature = 'queue:contract';

    protected $description = 'Print the effective queue timeout contract and fail if it is violated';

    public function handle(): int
    {
        $this->line('Active queue connection: '.config('queue.default'));

        $this->newLine();
        $this->line('retry_after, per connection:');
        foreach (QueueContract::retryAfterByConnection() as $connection => $retryAfter) {
            $marker = $connection === config('queue.default') ? ' ← active' : '';
            $this->line("  {$connection}: {$retryAfter}s{$marker}");
        }

        $this->newLine();
        $this->line('Worker timeouts:');
        foreach (QueueContract::workerTimeouts() as $where => $timeout) {
            $this->line("  {$where}: {$timeout}s");
        }

        $this->newLine();
        $this->line('Job timeouts:');
        foreach (QueueContract::jobTimeouts() as $job => $timeout) {
            $this->line('  '.class_basename($job).': '.($timeout === null ? "worker's" : "{$timeout}s"));
        }

        $problems = QueueContract::violations();

        $this->newLine();

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Contract holds: longest job %ds <= worker %ds < retry_after %ds.',
            QueueContract::longestJobTimeout(),
            max(QueueContract::workerTimeouts()),
            min(QueueContract::retryAfterByConnection()),
        ));

        return self::SUCCESS;
    }
}
