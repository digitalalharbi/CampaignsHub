<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Services\ProjectReportViews;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** REPORT-VIEWS-001 — GET /projects/{project}/report-views/summary. Agency-only, needs `reports.share` like the per-link analytics. */
final class ProjectReportViewsController extends Controller
{
    public function show(Request $request, ProjectReportViews $views): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        $tenantId = (string) app(TenantContext::class)->tenantId();
        $projectId = (string) app(ProjectContext::class)->projectId();
        abort_if($tenantId === '' || $projectId === '', 400, 'No active project.');

        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::today();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(29);

        return ApiResponse::success($views->build($tenantId, $projectId, $from, $to), 'Report link opens.');
    }
}
