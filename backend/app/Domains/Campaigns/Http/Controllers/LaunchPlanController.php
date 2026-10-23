<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Launch\JourneyPlanner;
use App\Domains\Campaigns\Launch\LaunchPlan;
use App\Domains\Campaigns\Launch\LaunchPlanService;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * LAUNCH-001 — the launcher's endpoints. Reading needs campaigns.view; a draft needs campaigns.create; sending
 * the plan to the platforms needs an explicit `confirm` on top, and running the campaigns needs campaigns.launch
 * (checked in the service, before any provider is called).
 */
final class LaunchPlanController extends Controller
{
    public function __construct(private readonly LaunchPlanService $plans, private readonly ProjectContext $context) {}

    public function options(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        return ApiResponse::success($this->plans->options($request->user(), $this->projectId($project)), 'Launch options.');
    }

    public function strategy(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $goal = (string) $request->query('goal', 'sales');
        abort_unless(in_array($goal, JourneyPlanner::GOALS, true), 422, 'Unknown business goal.');

        return ApiResponse::success($this->plans->strategy($request->user(), $this->projectId($project), $goal), 'Launch strategy.');
    }

    public function index(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $rows = LaunchPlan::query()->where('project_id', $this->projectId($project))->orderByDesc('created_at')->limit(50)->get();

        return ApiResponse::success($rows->map(fn (LaunchPlan $p) => $this->shape($p))->values()->all(), 'Launch plans.');
    }

    public function show(Request $request, string $project, string $plan): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $model = $this->find($project, $plan);

        return ApiResponse::success($this->shape($model) + ['review' => $this->plans->review($request->user(), $this->projectId($project), $model)], 'Launch plan.');
    }

    public function store(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.create'), 403);
        $model = $this->plans->prepare($request->user(), $this->project($project), $request->all());

        return ApiResponse::success($this->shape($model) + ['review' => $this->plans->review($request->user(), $this->projectId($project), $model)], 'Launch plan saved.', status: 201);
    }

    public function update(Request $request, string $project, string $plan): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.create'), 403);
        $model = $this->find($project, $plan);
        try {
            $model = $this->plans->prepare($request->user(), $this->project($project), $request->all(), $model);
        } catch (InvalidArgumentException $e) {
            abort(409, $e->getMessage());
        }

        return ApiResponse::success($this->shape($model) + ['review' => $this->plans->review($request->user(), $this->projectId($project), $model)], 'Launch plan saved.');
    }

    /** POST …/publish — sends the plan to the platforms. Nothing is sent without `confirm`. */
    public function publish(Request $request, string $project, string $plan): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.create'), 403);
        $request->validate(['confirm' => ['required', 'accepted']], ['confirm.*' => 'Publishing to the platforms needs an explicit confirmation.']);
        $model = $this->find($project, $plan);
        try {
            $model = $this->plans->publish($request->user(), $this->project($project), $model);
        } catch (InvalidArgumentException $e) {
            abort(403, $e->getMessage());
        }

        $status = $model->status === LaunchPlan::PUBLISHED ? 200 : ($model->status === LaunchPlan::FAILED ? 422 : 207);

        return response()->json(['data' => $this->shape($model) + ['review' => $this->plans->review($request->user(), $this->projectId($project), $model)], 'message' => match ($model->status) {
            LaunchPlan::PUBLISHED => 'Published on every platform.',
            LaunchPlan::PARTIAL => 'Published on some platforms — the rest can be retried.',
            default => 'Nothing was published.',
        }], $status);
    }

    private function find(string $project, string $plan): LaunchPlan
    {
        $model = LaunchPlan::query()->where('project_id', $this->projectId($project))->find($plan);
        abort_if($model === null, 404, 'Launch plan not found.');

        return $model;
    }

    private function project(string $project): Project
    {
        $model = Project::query()->find($this->projectId($project));
        abort_if($model === null, 404, 'Project not found.');

        return $model;
    }

    private function projectId(string $project): string
    {
        return $this->context->projectId() ?? $project;
    }

    private function shape(LaunchPlan $p): array
    {
        return [
            'id' => (string) $p->id,
            'name' => $p->name,
            'objective' => $p->objective,
            'status' => $p->status,
            'launch' => $p->launch,
            'unified_campaign_id' => $p->unified_campaign_id,
            'spec' => $p->spec,
            'steps' => $p->steps,
            'published_at' => $p->published_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
