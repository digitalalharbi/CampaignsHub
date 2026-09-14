<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use App\Domains\Ops\Services\ScheduledRunRows;
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
        /*
         * AUTOMATION-FIRST-OPERATIONS-001 — the count the ledger is supposed to hold.
         *
         * The reports actually dispatched. A schedule that was not due is not a row this run touched.
         *
         * The number was already computed and already printed to a terminal nobody is watching at
         * 04:00, while `scheduled_runs.rows_affected` stayed null — so the ops page could say the run
         * SUCCEEDED without being able to say whether it did anything.
         */
        app(ScheduledRunRows::class)->report($count);

        $this->info("Dispatched {$count} scheduled report(s).");

        return self::SUCCESS;
    }
}
