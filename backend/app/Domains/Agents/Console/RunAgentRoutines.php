<?php

declare(strict_types=1);

namespace App\Domains\Agents\Console;

use App\Domains\Agents\Models\AgentRoutine;
use App\Domains\Agents\Services\RoutineStarter;
use App\Domains\Agents\Support\AgentRoutineCatalog;
use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Console\Command;

/**
 * AGENTS-ROUTINES-001 — start one routine for every project that switched it on.
 *
 * Each run is a new thread in that project's Agent Center, with the routine's fixed instruction as its
 * first message, and the reply is queued like any other. A project without an AI key, or whose last run
 * of this routine is still answering, is skipped and the reason is stored on the routine row.
 */
final class RunAgentRoutines extends Command
{
    protected $signature = 'agents:run-routines {routine : daily_analysis|budget_guard|creative_review|weekly_report}';

    protected $description = 'Start a scheduled agent routine for every project that enabled it.';

    public function handle(RoutineStarter $starter, TenantContext $tenant): int
    {
        $key = (string) $this->argument('routine');
        if (! AgentRoutineCatalog::has($key)) {
            $this->error("Unknown routine {$key}.");

            return self::INVALID;
        }

        $started = 0;
        foreach (AgentRoutine::withoutGlobalScopes()->where('routine', $key)->where('enabled', true)->cursor() as $row) {
            $tenant->setTenantId((string) $row->tenant_id);
            if ($starter->start($row) === 'queued') {
                $started++;
            }
        }

        // The number of conversations started is what this run did; the ops page shows it.
        app(ScheduledRunRows::class)->report($started);
        $this->info("{$key}: {$started} started.");

        return self::SUCCESS;
    }
}
