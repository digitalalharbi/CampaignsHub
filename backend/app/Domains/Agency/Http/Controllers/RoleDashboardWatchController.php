<?php

declare(strict_types=1);

namespace App\Domains\Agency\Http\Controllers;

use App\Domains\Agency\Services\RoleDashboardBuilder;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WATCH-BOARD-001 — GET /api/v1/me/dashboard/watch[?days=7|14|30|90][&unit=<client id | p:project id>]
 *
 * «لوحة المتابعة»: the leading and weakest campaigns of every platform, the platform comparison, the daily trend and the
 * leading content, over the member's own reach — narrowed to one client when asked. A unit he cannot reach is ignored.
 */
final class RoleDashboardWatchController extends Controller
{
    public function __invoke(Request $request, RoleDashboardBuilder $builder): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($user->hasPermission('campaigns.view'), 403);

        $days = (int) $request->integer('days', RoleDashboardBuilder::DEFAULT_DAYS);
        $unit = $request->query('unit');

        return ApiResponse::success($builder->watchFor($user, $days, is_string($unit) && $unit !== '' ? $unit : null, $request->string('project')->toString() ?: null), 'Dashboard watch board.');
    }
}
