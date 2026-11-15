<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Console;

use App\Domains\Notifications\Engine\Scheduling\DigestRunner;
use Illuminate\Console\Command;

/** `notifications:v2-digests` — queue the external digests that are due (every five minutes). Sends nothing itself. */
final class RunDigestsCommand extends Command
{
    protected $signature = 'notifications:v2-digests';

    protected $description = 'Queue due external digest deliveries into the notifications outbox (sends nothing).';

    public function handle(DigestRunner $runner): int
    {
        $r = $runner->run();
        $this->line("subscriptions: {$r['subscriptions']}  intents: {$r['intents']}  empty periods: {$r['skipped_empty_period']}");

        return self::SUCCESS;
    }
}
