<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\LiveDrilldown;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REPORT-DRILLDOWN-001 — the drill-downs of a live link, for the operator who owns it.
 *
 * The operator never holds the raw token (only its hash is stored), so the public addresses are not
 * theirs to call. These are the same drill-downs addressed by the share's id, behind a session and
 * `reports.view` on the project, and answered by the same {@see LiveDrilldown}: an operator checking
 * what a client will see gets the client's view — the share's ceiling, hide flags and breakdowns —
 * not a wider operator view under the same name.
 *
 * Isolation is layered, and each layer answers 404:
 *   - the report is read through its project scope, so another project's report does not resolve;
 *   - the share is read through its tenant scope AND must belong to that report, so a share id from
 *     another report — even in the same project — does not resolve;
 *   - a snapshot link has no live drill-down.
 */
final class LiveDrilldownController extends Controller
{
    public function platform(Request $request, LiveDrilldown $drilldown, string $project, string $report, string $share, string $provider): JsonResponse
    {
        $model = $this->findShare($report, $share);
        $payload = $model === null ? null : $drilldown->platform($model, $provider, $request->query());

        return $payload === null
            ? ApiResponse::error('هذه المنصة غير متاحة في هذا الرابط.', status: 404)
            : ApiResponse::success($payload, 'Live platform.');
    }

    public function content(Request $request, LiveDrilldown $drilldown, string $project, string $report, string $share, string $key): JsonResponse
    {
        $model = $this->findShare($report, $share);
        $payload = $model === null ? null : $drilldown->content($model, $key, $request->query());

        return $payload === null
            ? ApiResponse::error('هذا المحتوى غير متاح في هذا الرابط.', status: 404)
            : ApiResponse::success($payload, 'Live content.');
    }

    private function findShare(string $report, string $share): ?ReportShare
    {
        $owner = Report::query()->find($report);
        if ($owner === null) {
            return null;
        }

        return ReportShare::query()->whereKey($share)->where('report_id', $owner->getKey())->first();
    }
}
