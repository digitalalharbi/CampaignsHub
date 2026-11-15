<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup\Console;

use App\Domains\Notifications\Followup\MorningBriefRunner;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/** `notifications:v2-morning-brief` — each person's brief at the time they chose, once per workspace day (every 5 minutes). */
final class MorningBriefCommand extends Command
{
    protected $signature = 'notifications:v2-morning-brief';

    protected $description = 'Create and send the follow-up assistant morning brief to each responsible person whose time has come.';

    public function handle(MorningBriefRunner $runner): int
    {
        $n = $runner->run();
        app(ScheduledRunRows::class)->report($n);
        $this->line('briefs: '.$n);

        return self::SUCCESS;
    }
}
