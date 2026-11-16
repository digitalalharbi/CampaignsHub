<?php

declare(strict_types=1);

namespace App\Domains\Agency\Http\Controllers;

use App\Domains\Agency\Services\RoleDashboardBuilder;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DASH-PERF-SPLIT-001 — GET /api/v1/me/dashboard/performance[?days=7|14|30|90]
 *
 * The manager page's performance panels — the leading campaigns, the platform comparison, the spend share and the
 * window's spend against the window before it — over every campaign the signed-in member can reach. Read apart from
 * `GET /me/dashboard` so the page's figures are never held for them.
 */
final class RoleDashboardPerformanceController extends Controller
{
    public function __invoke(Request $request, RoleDashboardBuilder $builder): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $days = (int) $request->integer('days', RoleDashboardBuilder::DEFAULT_DAYS);

        return ApiResponse::success($builder->performanceFor($user, $days), 'Dashboard performance.');
    }
}
