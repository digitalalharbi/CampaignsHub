<?php

declare(strict_types=1);

namespace App\Domains\Tracking\Http\Controllers;

use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tracking\Services\TrackingSummary;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * TRACKING-SUMMARY-001 — GET /projects/{project}/tracking/summary. Behind `module:advanced_tracking`: a project that
 * has not switched tracking on is refused with the module named, not shown an empty chart that looks like «no clicks».
 */
final class TrackingSummaryController extends Controller
{
    public function show(Request $request, TrackingSummary $summary): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        $tenantId = (string) app(TenantContext::class)->tenantId();
        $projectId = (string) app(ProjectContext::class)->projectId();
        abort_if($tenantId === '' || $projectId === '', 400, 'No active project.');

        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::today();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(29);

        return ApiResponse::success($summary->build($tenantId, $projectId, $from, $to), 'Link activity summary.');
    }
}
