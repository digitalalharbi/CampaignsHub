<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Http\Controllers;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertRuleEvaluator;
use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Manage a project's alert rules (alerts.view / alerts.manage). Rules evaluate against real metrics. */
final class AlertRuleController extends Controller
{
    public function index(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()->hasPermission('alerts.view'), 403);

        return ApiResponse::success(['rules' => AlertRule::query()->latest()->get()], 'Alert rules retrieved.');
    }

    public function store(Request $request, string $project, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('alerts.manage'), 403);
        $data = $this->validated($request);

        $rule = AlertRule::create($data + [
            'project_id' => app(ProjectContext::class)->projectId(),
            'created_by' => $request->user()->id,
        ]);
        $audit->log(action: 'alert_rule.created', entityType: AlertRule::class, entityId: (string) $rule->id, after: ['type' => $rule->rule_type]);

        return ApiResponse::success($rule, 'Alert rule created.', status: 201);
    }

    public function update(Request $request, string $project, string $rule, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('alerts.manage'), 403);
        $model = AlertRule::query()->findOrFail($rule);
        $model->update($this->validated($request, creating: false));
        $audit->log(action: 'alert_rule.updated', entityType: AlertRule::class, entityId: (string) $model->id);

        return ApiResponse::success($model->fresh(), 'Alert rule updated.');
    }

    public function destroy(Request $request, string $project, string $rule): JsonResponse
    {
        abort_unless($request->user()->hasPermission('alerts.manage'), 403);
        AlertRule::query()->findOrFail($rule)->delete();

        return ApiResponse::success(null, 'Alert rule deleted.');
    }

    /** Evaluate all of the project's rules now (also runs on a schedule). */
    public function evaluate(Request $request, string $project, AlertRuleEvaluator $evaluator): JsonResponse
    {
        abort_unless($request->user()->hasPermission('alerts.view'), 403);
        $result = $evaluator->evaluateProject(
            (string) app(TenantContext::class)->tenantId(),
            (string) app(ProjectContext::class)->projectId(),
        );

        return ApiResponse::success($result, 'Alert rules evaluated.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'rule_type' => [$creating ? 'required' : 'sometimes', Rule::in(AlertRule::TYPES)],
            'metric' => ['nullable', 'string', 'max:40'],
            'operator' => ['nullable', Rule::in(['gt', 'gte', 'lt', 'lte', 'eq'])],
            'threshold' => ['nullable', 'numeric'],
            'evaluation_window' => ['nullable', 'integer', 'between:1,90'],
            'severity' => ['nullable', Rule::in(['info', 'warning', 'critical'])],
            'cooldown' => ['nullable', 'integer', 'between:300,604800'],
            'quiet_hours' => ['nullable', 'array'],
            'channels' => ['nullable', 'array'],
            'is_active' => ['boolean'],
            'unified_campaign_id' => ['nullable', 'uuid'],
        ]);
    }
}
