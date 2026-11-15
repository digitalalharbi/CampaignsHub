<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Console;

use App\Domains\Notifications\External\Review\ReviewQueue;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/** `notifications:v2-review-sweep` — held external messages with no decision inside the window become skipped + notice (every 15 min). */
final class ReviewSweepCommand extends Command
{
    protected $signature = 'notifications:v2-review-sweep';

    protected $description = 'Skip held external messages nobody reviewed within the window, with an internal notice.';

    public function handle(ReviewQueue $reviews): int
    {
        $n = $reviews->expire();
        app(ScheduledRunRows::class)->report($n);
        $this->line('skipped: '.$n);

        return self::SUCCESS;
    }
}
