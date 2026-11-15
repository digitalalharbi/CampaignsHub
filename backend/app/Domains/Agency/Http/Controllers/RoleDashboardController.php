<?php

declare(strict_types=1);

namespace App\Domains\Agency\Http\Controllers;

use App\Domains\Agency\Services\RoleDashboardBuilder;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ROLE-DASHBOARD-001 — GET /api/v1/me/dashboard[?view=manager|pm|finance|marketer]
 *
 * The signed-in member's landing page, filtered on the server to what their role, sections and clients allow. `view` is
 * honoured only for the workspace manager (to preview another role's page); anybody else always gets their own.
 */
final class RoleDashboardController extends Controller
{
    public function __invoke(Request $request, RoleDashboardBuilder $builder): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return ApiResponse::success($builder->build($user, $request->string('view')->toString() ?: null), 'Dashboard.');
    }
}
