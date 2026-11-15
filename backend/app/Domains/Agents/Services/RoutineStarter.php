<?php

declare(strict_types=1);

namespace App\Domains\Agents\Services;

use App\Domains\Agents\Engine\ModelClientFactory;
use App\Domains\Agents\Jobs\RunAgentTurnJob;
use App\Domains\Agents\Models\AgentMessage;
use App\Domains\Agents\Models\AgentRoutine;
use App\Domains\Agents\Models\AgentThread;
use App\Domains\Agents\Support\AgentRoles;
use App\Domains\Agents\Support\AgentRoutineCatalog;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * AGENTS-ROUTINES-001 — start ONE routine for ONE project: the schedule's run and «تشغيل الآن» go through the same door.
 *
 * Each run is a new shared thread in that project's Agent Center with the routine's fixed instruction as its first message, and
 * the reply is queued like any other. A project without an AI key, or whose last run of this routine is still answering, is
 * skipped and the reason is stored on the routine row. The run has no person in it, so its outcome is the project's.
 */
final class RoutineStarter
{
    public function __construct(private readonly ModelClientFactory $models) {}

    /** @return string the outcome stored on the routine row: queued | skipped_busy | no_ai_credential | model_unavailable | unavailable */
    public function start(AgentRoutine $row): string
    {
        $routine = AgentRoutineCatalog::all()[$row->routine] ?? null;
        $role = $routine === null ? null : AgentRoles::get($routine['agent']);
        $project = Project::withoutGlobalScopes()->find($row->project_id);
        if ($routine === null || $project === null || $project->status !== 'active' || $role === null || ! $role['enabled']) {
            return 'unavailable';
        }

        $busy = $row->last_thread_id !== null
            && AgentThread::withoutGlobalScopes()->whereKey($row->last_thread_id)->where('status', 'thinking')->exists();
        if ($busy) {
            $row->forceFill(['last_outcome' => 'skipped_busy'])->save();

            return 'skipped_busy';
        }

        try {
            $this->models->for($project, $routine['agent']);   // a project without a key is skipped, not failed
        } catch (RuntimeException $e) {
            $outcome = $e->getMessage() === 'no_ai_credential' ? 'no_ai_credential' : 'model_unavailable';
            $row->forceFill(['last_run_at' => Carbon::now(), 'last_outcome' => $outcome])->save();

            return $outcome;
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

        return 'queued';
    }
}
