<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Metrics\Services\PeriodCoverage;
use App\Domains\Metrics\Services\PeriodResolver;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Context\ProjectContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * DATE-RANGE-001 — the period and what is missing inside it.
 *
 * `coverage` answers, per bound account, which complete days of the period have no figures — from the
 * sync history, never from a guess — and flags accounts that keep another time zone.
 */
final class PeriodController extends Controller
{
    public function coverage(Request $request, PeriodCoverage $coverage): JsonResponse
    {
        $user = $request->user();
        $projectId = (string) app(ProjectContext::class)->projectId();

        abort_unless(
            $user !== null && $projectId !== '' && app(ProjectAbilities::class)->allows($user, $projectId, ProjectCapability::CAMPAIGNS_VIEW),
            403,
        );

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'provider' => ['sometimes', 'string'],
        ]);

        $providers = isset($data['provider']) ? array_values(array_filter(explode(',', $data['provider']))) : null;

        return ApiResponse::success($coverage->forProject($projectId, $data['from'], $data['to'], $providers), 'Period coverage.');
    }

    /** The Riyadh dates a named period or a custom span resolves to, plus its comparison. */
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['sometimes', 'string'],
            'from' => ['required_without:period', 'date_format:Y-m-d'],
            'to' => ['required_without:period', 'date_format:Y-m-d'],
            'compare' => ['sometimes', 'in:previous,last_year'],
        ]);

        try {
            $period = isset($data['period']) ? PeriodResolver::preset($data['period']) : PeriodResolver::custom($data['from'], $data['to']);
        } catch (InvalidArgumentException) {
            abort(422, 'Unknown period.');
        }

        return ApiResponse::success([
            'period' => $period,
            'comparison' => isset($data['compare']) ? PeriodResolver::comparison($period['from'], $period['to'], $data['compare']) : null,
            'timezone' => PeriodResolver::TIMEZONE,
        ], 'Period.');
    }
}
