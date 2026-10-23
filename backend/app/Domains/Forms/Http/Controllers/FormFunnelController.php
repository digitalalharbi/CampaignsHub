<?php

declare(strict_types=1);

namespace App\Domains\Forms\Http\Controllers;

use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Services\FormFunnelReport;
use App\Domains\Forms\Services\TrackingHealth;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * FORMS-DASHBOARDS-001 — the funnel of one form and of one link, for one project.
 *
 * Reading needs `leads.view` on the project. The figures are aggregates: no person is named here, and the people are in
 * the Leads Centre behind its own permissions.
 */
final class FormFunnelController extends Controller
{
    public function __construct(private readonly ProjectContext $context, private readonly ProjectAbilities $abilities, private readonly FormFunnelReport $report, private readonly TrackingHealth $health) {}

    public function form(Request $request, string $project, string $form): JsonResponse
    {
        $pid = $this->allowed($request, $project);
        $model = LeadForm::query()->where('project_id', $pid)->find($form);
        abort_if($model === null, 404, 'Form not found.');
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->report->forForm($model, $from, $to), 'Form funnel.');
    }

    public function link(Request $request, string $project, string $link): JsonResponse
    {
        $pid = $this->allowed($request, $project);
        $model = ShortLink::query()->find($link);
        abort_if($model === null, 404, 'Link not found.');
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->report->forLink($model, $pid, $from, $to), 'Link funnel.');
    }

    /** TRACKING-HEALTH-001 — whether this project's lead tracking can be trusted, and what to do where it cannot. */
    public function health(Request $request, string $project): JsonResponse
    {
        $pid = $this->allowed($request, $project);
        [$from, $to] = $this->range($request);
        $tenant = Project::query()->findOrFail($pid)->tenant_id;

        return ApiResponse::success($this->health->forProject((string) $tenant, $pid, $from, $to), 'Tracking health.');
    }

    private function allowed(Request $request, string $project): string
    {
        $pid = $this->context->projectId() ?? $project;
        $user = $request->user();
        abort_unless($user !== null && $this->abilities->allows($user, $pid, ProjectCapability::LEADS_VIEW), 403);

        return $pid;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::now();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(29);
        abort_if($from->diffInDays($to) > 400, 422, 'A funnel covers at most 400 days.');

        return [$from, $to];
    }
}
