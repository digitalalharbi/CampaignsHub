<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Console;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertRuleEvaluator;
use Illuminate\Console\Command;

/**
 * Evaluate every project's active alert rules. Runs on a schedule (and can be invoked manually). Each
 * project is evaluated independently so one project's failure never blocks another.
 */
final class EvaluateAlertsCommand extends Command
{
    protected $signature = 'alerts:evaluate {--project= : limit to one project id}';

    protected $description = 'Evaluate active alert rules and raise/resolve notifications';

    public function handle(AlertRuleEvaluator $evaluator): int
    {
        $query = AlertRule::withoutGlobalScopes()->where('is_active', true)
            ->select('tenant_id', 'project_id')->distinct();
        if ($pid = $this->option('project')) {
            $query->where('project_id', $pid);
        }

        $totals = ['raised' => 0, 'resolved' => 0, 'skipped' => 0];
        foreach ($query->get() as $scope) {
            try {
                $r = $evaluator->evaluateProject((string) $scope->tenant_id, (string) $scope->project_id);
                foreach ($totals as $k => $_) {
                    $totals[$k] += $r[$k];
                }
            } catch (\Throwable $e) {
                $this->warn("project {$scope->project_id}: {$e->getMessage()}"); // failure isolation
            }
        }

        $this->info("Alerts evaluated — raised {$totals['raised']}, resolved {$totals['resolved']}, skipped {$totals['skipped']}.");

        return self::SUCCESS;
    }
}
