<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use App\Domains\Reports\Services\ScheduledReportDispatcher;
use Illuminate\Console\Command;

/** Dispatches due report schedules (snapshot + honest delivery ledger). Scheduled every 5 minutes. */
final class DispatchScheduledReports extends Command
{
    protected $signature = 'reports:dispatch-scheduled';

    protected $description = 'Generate + queue delivery for due scheduled reports (honest delivery states).';

    public function handle(ScheduledReportDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatchDue();
        $this->info("Dispatched {$count} scheduled report(s).");

        return self::SUCCESS;
    }
}
