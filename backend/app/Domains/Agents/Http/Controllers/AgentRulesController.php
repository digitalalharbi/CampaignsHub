<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Controllers;

use App\Domains\Agents\Models\ClientAgentRule;
use App\Domains\Agents\Services\AgentReadTools;
use App\Domains\Agents\Support\ProposalRules;
use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * AGENTS-CLIENT-RULES-001 — a project's goals and decision rules, and the gateway limits beside them.
 *
 * The limits (`max_increase_percent`, `daily_budget_ceiling`) are the ones `ProposalRules` enforces; they
 * are stored on the project so the gateway reads them without this table, and edited here so an owner
 * sets goals and limits in one place.
 */
final class AgentRulesController extends Controller
{
    public function show(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);
        $model = Project::query()->findOrFail($project);

        return ApiResponse::success([
            'rules' => ClientAgentRule::query()->where('project_id', $project)->orderBy('kpi')->get()->map(fn (ClientAgentRule $r): array => $this->shape($r))->all(),
            'limits' => app(ProposalRules::class)->limitsFor($model),
            'kpis' => ClientAgentRule::KPIS,
        ], 'Agent rules.');
    }

    /** Replace the whole set: an owner edits the list as a list, and an omitted row is a removed one. */
    public function update(Request $request, AuditLogger $audit, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.manage'), 403);
        $model = Project::query()->findOrFail($project);

        $data = $request->validate([
            'rules' => ['present', 'array', 'max:50'],
            'rules.*.platform' => ['nullable', 'string', 'max:40'],
            'rules.*.kpi' => ['required', Rule::in(ClientAgentRule::KPIS)],
            'rules.*.direction' => ['required', Rule::in(['max', 'min'])],
            'rules.*.target_value' => ['required', 'numeric', 'gt:0'],
            'rules.*.min_spend_to_judge' => ['nullable', 'numeric', 'gte:0'],
            'rules.*.special_days' => ['nullable', 'array', 'max:60'],
            'rules.*.special_days.*.date' => ['required', 'date_format:Y-m-d'],
            'rules.*.special_days.*.label' => ['nullable', 'string', 'max:80'],
            'rules.*.forbidden_words' => ['nullable', 'array', 'max:100'],
            'rules.*.forbidden_words.*' => ['string', 'max:60'],
            'rules.*.notes' => ['nullable', 'string', 'max:2000'],
            'limits' => ['sometimes', 'array'],
            'limits.max_increase_percent' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'limits.daily_budget_ceiling' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $keys = [];
        foreach ($data['rules'] as $r) {
            $key = ($r['platform'] ?? '*').'|'.$r['kpi'];
            abort_if(isset($keys[$key]), 422, 'Each KPI may have one rule per platform.');
            $keys[$key] = true;
        }

        DB::transaction(function () use ($model, $data, $request): void {
            ClientAgentRule::query()->where('project_id', $model->getKey())->delete();
            foreach ($data['rules'] as $r) {
                ClientAgentRule::query()->create([
                    'tenant_id' => $model->tenant_id, 'project_id' => $model->getKey(),
                    'platform' => $r['platform'] ?? null, 'kpi' => $r['kpi'], 'direction' => $r['direction'],
                    'target_value' => $r['target_value'], 'min_spend_to_judge' => $r['min_spend_to_judge'] ?? null,
                    'special_days' => $r['special_days'] ?? null, 'forbidden_words' => $r['forbidden_words'] ?? null,
                    'notes' => $r['notes'] ?? null, 'created_by' => $request->user()->getKey(),
                ]);
            }

            if (array_key_exists('limits', $data)) {
                $meta = (array) $model->meta;
                $meta['agent_limits'] = array_filter([
                    'max_increase_percent' => $data['limits']['max_increase_percent'] ?? null,
                    'daily_budget_ceiling' => $data['limits']['daily_budget_ceiling'] ?? null,
                ], static fn ($v) => $v !== null);
                $model->forceFill(['meta' => $meta])->save();
            }
        });

        $audit->log(action: 'agents.rules.updated', entityType: Project::class, entityId: (string) $model->getKey(), after: ['rules' => count($data['rules'])]);

        return $this->show($request, $project);
    }

    /** The comparison every agent starts from: each campaign against the client's own rules. */
    public function compare(Request $request, AgentReadTools $tools, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);
        $days = (int) $request->integer('days', 7);

        return ApiResponse::success($tools->compareTargets(Project::query()->findOrFail($project), $days), 'Targets compared.');
    }

    /** @return array<string, mixed> */
    private function shape(ClientAgentRule $r): array
    {
        return [
            'id' => (string) $r->getKey(), 'platform' => $r->platform, 'kpi' => $r->kpi, 'direction' => $r->direction,
            'target_value' => $r->target_value, 'min_spend_to_judge' => $r->min_spend_to_judge,
            'special_days' => $r->special_days ?? [], 'forbidden_words' => $r->forbidden_words ?? [], 'notes' => $r->notes,
        ];
    }
}
