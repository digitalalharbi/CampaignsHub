<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Console;

use App\Domains\Notifications\Engine\Entitlements\GraceHeadsUp;
use Illuminate\Console\Command;

/** `notifications:v2-grace-heads-up` — tell workspace owners a grace period ends soon (daily). */
final class GraceHeadsUpCommand extends Command
{
    protected $signature = 'notifications:v2-grace-heads-up';

    protected $description = 'Raise the in-app heads-up for plan features whose grace period ends soon.';

    public function handle(GraceHeadsUp $headsUp): int
    {
        $this->line('heads-up: '.$headsUp->run());

        return self::SUCCESS;
    }
}
