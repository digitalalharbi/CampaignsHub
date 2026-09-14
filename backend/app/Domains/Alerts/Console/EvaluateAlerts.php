<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Console;

use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/**
 * Evaluates every active alert rule across all tenants and raises alerts for fresh breaches (respecting
 * cooldown / snooze / dedup). Wired into the scheduler.
 */
final class EvaluateAlerts extends Command
{
    protected $signature = 'alerts:evaluate';

    protected $description = 'Evaluate alert rules and raise notifications for new breaches.';

    public function handle(AlertEvaluator $evaluator): int
    {
        $raised = $evaluator->evaluateAll();
        /*
         * AUTOMATION-FIRST-OPERATIONS-001 — the count the ledger is supposed to hold.
         *
         * Alerts RAISED, and not rules evaluated: the run reads every rule every time, so a quiet night
         * is «0 raised» rather than «three hundred touched».
         *
         * The number was already computed and already printed to a terminal nobody is watching at
         * 04:00, while `scheduled_runs.rows_affected` stayed null — so the ops page could say the run
         * SUCCEEDED without being able to say whether it did anything.
         */
        app(ScheduledRunRows::class)->report($raised);

        $this->info("Alerts evaluated. Newly raised: {$raised}.");

        return self::SUCCESS;
    }
}
