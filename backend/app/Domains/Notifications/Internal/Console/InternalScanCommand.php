<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Internal\Console;

use App\Domains\Notifications\Internal\InternalScan;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/** `notifications:v2-internal-scan` — raise the internal facts nobody «calls» (every 10 minutes). Reading only. */
final class InternalScanCommand extends Command
{
    protected $signature = 'notifications:v2-internal-scan';

    protected $description = 'Raise internal notifications and follow-up tasks for facts found by looking (no budget, held messages, unsubscribes, plan limits).';

    public function handle(InternalScan $scan): int
    {
        $out = $scan->run();
        app(ScheduledRunRows::class)->report(array_sum($out));
        $this->line(json_encode($out));

        return self::SUCCESS;
    }
}
