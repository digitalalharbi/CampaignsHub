<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Console;

use App\Domains\Notifications\External\Events\ExternalEventDetector;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/** `notifications:v2-external-events` — the daily detection of the default external events (continuity, improvement, new campaign, month end/start). */
final class ExternalEventsCommand extends Command
{
    protected $signature = 'notifications:v2-external-events';

    protected $description = 'Detect the default external events for subscribed projects and hand them to the engine.';

    public function handle(ExternalEventDetector $detector): int
    {
        $out = $detector->run();
        app(ScheduledRunRows::class)->report(array_sum($out));
        foreach ($out as $event => $n) {
            $this->line($event.': '.$n);
        }

        return self::SUCCESS;
    }
}
