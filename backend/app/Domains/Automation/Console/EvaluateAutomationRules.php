<?php

declare(strict_types=1);

namespace App\Domains\Automation\Console;

use App\Domains\Automation\Models\AutomationRule;
use App\Domains\Automation\Services\RuleEvaluator;
use App\Domains\Automation\Support\RuleSchedule;
use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** AUTO-RULES-001 — evaluate every enabled rule that is due; each rule then schedules its own next run. */
final class EvaluateAutomationRules extends Command
{
    protected $signature = 'automation:evaluate';

    protected $description = 'Evaluate the automated rules that are due.';

    public function handle(RuleEvaluator $evaluator, TenantContext $tenant): int
    {
        $due = AutomationRule::withoutGlobalScopes()->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', Carbon::now()))
            ->orderBy('next_run_at')->limit(200)->get();

        foreach ($due as $rule) {
            $tenant->setTenantId((string) $rule->tenant_id);
            // Scheduled BEFORE evaluating, so a rule that throws is not retried every five minutes.
            $rule->forceFill(['next_run_at' => RuleSchedule::next($rule)])->save();
            $evaluator->evaluate($rule, 'schedule');
        }

        app(ScheduledRunRows::class)->report($due->count());
        $this->info("{$due->count()} rule(s) evaluated.");

        return self::SUCCESS;
    }
}
