<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Console;

use App\Domains\Notifications\Engine\Entitlements\GraceHeadsUp;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/** `notifications:v2-grace-heads-up` — tell workspace owners a grace period ends soon (daily). */
final class GraceHeadsUpCommand extends Command
{
    protected $signature = 'notifications:v2-grace-heads-up';

    protected $description = 'Raise the in-app heads-up for plan features whose grace period ends soon.';

    public function handle(GraceHeadsUp $headsUp): int
    {
        $n = $headsUp->run();
        app(ScheduledRunRows::class)->report($n);
        $this->line('heads-up: '.$n);

        return self::SUCCESS;
    }
}
