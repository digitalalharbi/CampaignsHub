<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Console;

use App\Domains\Notifications\Engine\Consent\ConfirmationRequests;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/** `notifications:v2-consent-sweep` — the internal notice for a confirmation request unanswered for 7 days (daily). */
final class ConsentSweepCommand extends Command
{
    protected $signature = 'notifications:v2-consent-sweep';

    protected $description = 'Raise the internal notice for external recipients who have not confirmed within 7 days.';

    public function handle(ConfirmationRequests $consent): int
    {
        $n = $consent->sweepUnconfirmed();
        app(ScheduledRunRows::class)->report($n);
        $this->line('notices: '.$n);

        return self::SUCCESS;
    }
}
