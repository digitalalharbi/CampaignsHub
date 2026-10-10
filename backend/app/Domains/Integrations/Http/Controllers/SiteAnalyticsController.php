<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Integrations\Measurement\Ga4SiteAnalytics;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** GA4-ANALYTICS-PRODUCT-001 — the project's site analytics, from its selected GA4 property. */
final class SiteAnalyticsController extends Controller
{
    private const MAX_DAYS = 365;

    public function show(Request $request, Ga4SiteAnalytics $site): JsonResponse
    {
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString())->startOfDay() : Carbon::today();
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString())->startOfDay() : $to->copy()->subDays(29);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
        }

        $tenantId = (string) app(TenantContext::class)->tenantId();
        $projectId = (string) app(ProjectContext::class)->projectId();
        abort_if($tenantId === '' || $projectId === '', 400, 'No active project.');

        return ApiResponse::success($site->build($tenantId, $projectId, $from, $to), 'Site analytics.');
    }
}
