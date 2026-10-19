<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Controllers;

use App\Domains\Agents\Models\AgentRoutine;
use App\Domains\Agents\Support\AgentRoutineCatalog;
use App\Domains\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** AGENTS-ROUTINES-001 — a project's routines: what each does, when, whether it is on, and its last run. */
final class AgentRoutinesController extends Controller
{
    public function index(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);

        return ApiResponse::success(['routines' => $this->rows($project)], 'Routines.');
    }

    public function update(Request $request, string $project, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.manage'), 403);
        $data = $request->validate([
            'routine' => ['required', Rule::in(array_keys(AgentRoutineCatalog::all()))],
            'enabled' => ['required', 'boolean'],
        ]);

        $row = AgentRoutine::query()->firstOrNew(['project_id' => $project, 'routine' => $data['routine']]);
        $before = (bool) $row->enabled;
        $row->forceFill(['enabled' => $data['enabled'], 'updated_by' => $request->user()->getKey()])->save();

        $audit->log(action: 'agents.routine.updated', entityType: AgentRoutine::class, entityId: (string) $row->getKey(),
            before: ['enabled' => $before], after: ['routine' => $data['routine'], 'enabled' => (bool) $data['enabled']]);

        return ApiResponse::success(['routines' => $this->rows($project)], 'Routine updated.');
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $project): array
    {
        $stored = AgentRoutine::query()->where('project_id', $project)->get()->keyBy('routine');

        return collect(AgentRoutineCatalog::all())->map(function (array $r, string $k) use ($stored): array {
            /** @var AgentRoutine|null $row */
            $row = $stored->get($k);

            return [
                'key' => $k, 'agent' => $r['agent'], 'ar' => $r['ar'], 'en' => $r['en'], 'when_ar' => $r['when_ar'], 'when_en' => $r['when_en'],
                'enabled' => (bool) ($row->enabled ?? false),
                'last_run_at' => $row?->last_run_at?->toIso8601String(),
                'last_outcome' => $row?->last_outcome,
                'last_thread_id' => $row?->last_thread_id !== null ? (string) $row->last_thread_id : null,
            ];
        })->values()->all();
    }
}
