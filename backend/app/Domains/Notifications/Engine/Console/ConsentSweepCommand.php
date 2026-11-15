<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Console;

use App\Domains\Notifications\Engine\Consent\ConfirmationRequests;
use Illuminate\Console\Command;

/** `notifications:v2-consent-sweep` — the internal notice for a confirmation request unanswered for 7 days (daily). */
final class ConsentSweepCommand extends Command
{
    protected $signature = 'notifications:v2-consent-sweep';

    protected $description = 'Raise the internal notice for external recipients who have not confirmed within 7 days.';

    public function handle(ConfirmationRequests $consent): int
    {
        $this->line('notices: '.$consent->sweepUnconfirmed());

        return self::SUCCESS;
    }
}
