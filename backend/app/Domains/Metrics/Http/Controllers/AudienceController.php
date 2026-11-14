<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Metrics\Audience\AudienceCenter;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** AUDIENCE-001 — the project's audience reading for the Analytics «الجمهور» panel (internal readers, `analytics.view`). */
final class AudienceController extends Controller
{
    private const MAX_DAYS = 365;

    public function show(Request $request, AudienceCenter $center): JsonResponse
    {
        $tenantId = (string) app(TenantContext::class)->tenantId();
        $projectId = (string) app(ProjectContext::class)->projectId();
        abort_if($tenantId === '' || $projectId === '', 400, 'No active project.');

        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'providers' => ['nullable', 'string', 'max:200']]);
        $to = $request->filled('to') ? Carbon::parse((string) $request->input('to'))->startOfDay() : Carbon::today();
        $from = $request->filled('from') ? Carbon::parse((string) $request->input('from'))->startOfDay() : $to->copy()->subDays(29);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
        }
        $providers = $request->filled('providers')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $request->input('providers')))))
            : null;

        // Money per breakdown only for a reader who may see money on THIS project (MONEY_VIEW).
        $user = $request->user();
        $hideMoney = $user === null || ! app(ProjectAbilities::class)->allows($user, $projectId, ProjectCapability::MONEY_VIEW);

        return ApiResponse::success($center->build($tenantId, $projectId, $from, $to, false, $hideMoney, $providers), 'Audience.');
    }
}
