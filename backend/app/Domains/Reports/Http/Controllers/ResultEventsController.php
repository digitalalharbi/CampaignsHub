<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Support\ResultEvents;
use App\Http\Controllers\Controller;
use App\Support\AdPlatforms;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RESULT-EVENTS-001 — which reported metric is a project's «Result», per platform and acquisition path.
 *
 * Nothing is inferred and nothing is defaulted: an unconfigured project has no Results on any client report. Changing the mapping changes
 * how a report is READ, never what a provider was told — no campaign, pixel or conversion setting is touched.
 */
final class ResultEventsController extends Controller
{
    public function show(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        return ApiResponse::success($this->shape(Project::query()->findOrFail($project)));
    }

    public function update(Request $request, AuditLogger $audit, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.share'), 403);

        $request->validate([
            'result_events' => ['present', 'array'],
            'result_events.*' => ['array'],
            'result_events.*.*' => ['string', Rule::in(ResultEvents::METRICS)],
        ]);
        foreach (array_keys((array) $request->input('result_events')) as $platform) {
            abort_unless(in_array(AdPlatforms::canonical((string) $platform), AdPlatforms::ORDER, true), 422, "Unknown platform: {$platform}");
        }
        foreach ((array) $request->input('result_events') as $paths) {
            abort_unless(array_diff(array_keys((array) $paths), ResultEvents::PATHS) === [], 422, 'Unknown acquisition path.');
        }

        $model = Project::query()->findOrFail($project);
        $before = (array) (($model->meta ?? [])['result_events'] ?? []);
        $events = ResultEvents::fromArray((array) $request->input('result_events'));
        $meta = (array) ($model->meta ?? []);
        if ($events->configured()) {
            $meta['result_events'] = $events->toArray();
        } else {
            unset($meta['result_events']);
        }
        $model->update(['meta' => $meta]);

        $audit->log(action: 'project.result_events_changed', entityType: Project::class, entityId: (string) $model->getKey(), before: $before, after: $events->toArray());

        return ApiResponse::success($this->shape($model->refresh()), 'Result events saved.');
    }

    /** @return array<string, mixed> */
    private function shape(Project $project): array
    {
        return [
            'result_events' => (object) (array) (($project->meta ?? [])['result_events'] ?? []),
            'platforms' => AdPlatforms::ORDER,
            'paths' => ResultEvents::PATHS,
            'metrics' => ResultEvents::METRICS,
        ];
    }
}
