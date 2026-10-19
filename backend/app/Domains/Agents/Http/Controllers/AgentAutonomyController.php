<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Controllers;

use App\Domains\Agents\Models\AgentAutonomyGrant;
use App\Domains\Agents\Services\TrustLadder;
use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * AGENTS-AUTONOMY-001 — the ladder as measured, and an owner's switch for each class.
 *
 * Granting needs `ai.manage` AND the permission the class itself needs to be approved by a person
 * (`campaigns.pause`, or `campaigns.budget.change`), and is refused while the record is not eligible.
 */
final class AgentAutonomyController extends Controller
{
    private const PERMISSION = ['pause' => 'campaigns.pause', 'budget_decrease' => 'campaigns.budget.change'];

    public function show(Request $request, string $project, TrustLadder $ladder): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);
        $row = Project::query()->findOrFail($project);

        return ApiResponse::success($this->payload($row, $ladder), 'Autonomy.');
    }

    public function update(Request $request, string $project, TrustLadder $ladder, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'class' => ['required', Rule::in(TrustLadder::CLASSES)],
            'enabled' => ['required', 'boolean'],
        ]);
        $user = $request->user();
        abort_unless($user?->hasPermission('ai.manage') && $user->hasPermission(self::PERMISSION[$data['class']]), 403);
        $row = Project::query()->findOrFail($project);

        $active = $ladder->activeGrant($row, $data['class']);
        if ($data['enabled'] && $active === null) {
            $measured = $ladder->measure($row, $data['class']);
            abort_unless($measured['eligible'], 422, 'The record does not qualify yet: '.implode(', ', $measured['needs']).'.');
            $grant = AgentAutonomyGrant::query()->create([
                'project_id' => $row->getKey(), 'action_class' => $data['class'], 'granted_by' => $user->getKey(),
                'granted_at' => Carbon::now(), 'ladder_at_grant' => $measured,
            ]);
            $audit->log(action: 'agents.autonomy.granted', entityType: AgentAutonomyGrant::class, entityId: (string) $grant->getKey(), after: ['class' => $data['class']]);
        } elseif (! $data['enabled'] && $active !== null) {
            $active->forceFill(['revoked_at' => Carbon::now(), 'revoked_by' => $user->getKey()])->save();
            $audit->log(action: 'agents.autonomy.revoked', entityType: AgentAutonomyGrant::class, entityId: (string) $active->getKey(), after: ['class' => $data['class']]);
        }

        return ApiResponse::success($this->payload($row, $ladder), 'Autonomy updated.');
    }

    /** @return array<string, mixed> */
    private function payload(Project $project, TrustLadder $ladder): array
    {
        return [
            'classes' => array_map(fn (string $c): array => $ladder->measure($project, $c), TrustLadder::CLASSES),
            'requirements' => (array) config('agents.autonomy'),
        ];
    }
}
