<?php

declare(strict_types=1);

namespace App\Domains\Agents\Console;

use App\Domains\Agents\Engine\ModelClientFactory;
use App\Domains\Agents\Jobs\RunAgentTurnJob;
use App\Domains\Agents\Models\AgentMessage;
use App\Domains\Agents\Models\AgentRoutine;
use App\Domains\Agents\Models\AgentThread;
use App\Domains\Agents\Support\AgentRoles;
use App\Domains\Agents\Support\AgentRoutineCatalog;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

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

    public function handle(ModelClientFactory $models, TenantContext $tenant): int
    {
        $key = (string) $this->argument('routine');
        if (! AgentRoutineCatalog::has($key)) {
            $this->error("Unknown routine {$key}.");

            return self::INVALID;
        }
        $routine = AgentRoutineCatalog::all()[$key];
        $role = AgentRoles::get($routine['agent']);

        $started = 0;
        foreach (AgentRoutine::withoutGlobalScopes()->where('routine', $key)->where('enabled', true)->cursor() as $row) {
            $project = Project::withoutGlobalScopes()->find($row->project_id);
            if ($project === null || $project->status !== 'active' || $role === null || ! $role['enabled']) {
                continue;
            }
            $tenant->setTenantId((string) $row->tenant_id);

            $busy = $row->last_thread_id !== null
                && AgentThread::withoutGlobalScopes()->whereKey($row->last_thread_id)->where('status', 'thinking')->exists();
            if ($busy) {
                $row->forceFill(['last_outcome' => 'skipped_busy'])->save();

                continue;
            }

            try {
                $models->for($project, $routine['agent']);   // a project without a key is skipped, not failed
            } catch (RuntimeException $e) {
                $row->forceFill(['last_run_at' => Carbon::now(), 'last_outcome' => $e->getMessage() === 'no_ai_credential' ? 'no_ai_credential' : 'model_unavailable'])->save();

                continue;
            }

            $thread = AgentThread::withoutGlobalScopes()->create([
                'tenant_id' => $row->tenant_id, 'project_id' => $row->project_id, 'agent_key' => $routine['agent'],
                'title' => $routine['ar'].' — '.Carbon::now('Asia/Riyadh')->format('Y-m-d H:i'), 'status' => 'thinking',
            ]);
            AgentMessage::withoutGlobalScopes()->create([
                'tenant_id' => $row->tenant_id, 'thread_id' => $thread->getKey(), 'role' => 'user',
                'content' => [['type' => 'text', 'text' => $routine['instruction']]], 'text' => $routine['instruction'],
            ]);
            RunAgentTurnJob::dispatch((string) $thread->getKey(), null);

            $row->forceFill(['last_run_at' => Carbon::now(), 'last_thread_id' => $thread->getKey(), 'last_outcome' => 'queued'])->save();
            $started++;
        }

        $this->info("{$key}: {$started} started.");

        return self::SUCCESS;
    }
}
