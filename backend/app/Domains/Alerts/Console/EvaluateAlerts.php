<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Console;

use App\Domains\Alerts\Services\AlertEvaluator;
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
        $this->info("Alerts evaluated. Newly raised: {$raised}.");

        return self::SUCCESS;
    }
}
