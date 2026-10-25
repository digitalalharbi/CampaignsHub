<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Support\ReportMetricTemplate;
use App\Domains\Reports\Support\ReportMetricVisibility;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REPORT-KPI-VISIBILITY-001 — the client's reusable «metrics my reports hide» template.
 *
 * Read by anyone who can build a report for the project; written by whoever manages reports. It only changes where the NEXT report
 * starts: reports and links that already exist keep the list they were made with.
 */
final class ReportMetricTemplateController extends Controller
{
    /** GET /projects/{project}/reports/metric-template — the catalog, the client's saved list and the product preset. */
    public function show(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);
        $model = Project::query()->findOrFail($project);

        return ApiResponse::success([
            'catalog' => ReportMetricVisibility::catalog(),
            'preset' => ReportMetricVisibility::clientPreset(),
            'template' => ReportMetricTemplate::forProject($model),
            'starting_point' => ReportMetricTemplate::startingPoint($model),
        ]);
    }

    /** PUT /projects/{project}/reports/metric-template — save the list new client reports start from. */
    public function update(Request $request, AuditLogger $audit, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);
        $data = $request->validate([
            'hidden_metrics' => ['present', 'array'],
            'hidden_metrics.*' => ['string', 'max:40'],
        ]);
        $model = Project::query()->findOrFail($project);

        $hidden = ReportMetricVisibility::normalise($data['hidden_metrics']);
        ReportMetricTemplate::save($model, $hidden);
        $audit->log(action: 'report.metric_template_saved', entityType: Project::class, entityId: (string) $model->id, after: ['hidden_metrics' => $hidden]);

        return ApiResponse::success(['template' => $hidden, 'starting_point' => $hidden], 'Template saved.');
    }
}
