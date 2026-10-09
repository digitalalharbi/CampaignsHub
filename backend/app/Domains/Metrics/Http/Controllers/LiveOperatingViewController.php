<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Metrics\Services\LiveOperatingView;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\AdPlatforms;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** LIVE-OPERATING-VIEW-001 — the consolidated live operating view for one project. */
final class LiveOperatingViewController extends Controller
{
    public function show(Request $request, LiveOperatingView $view): JsonResponse
    {
        $user = $request->user();
        $projectId = app(ProjectContext::class)->projectId();
        // The same ability the metrics endpoints ask for: a reader who may see the campaigns may see how fresh they are.
        abort_unless(
            $user !== null && $projectId !== null
                && app(ProjectAbilities::class)->allows($user, (string) $projectId, ProjectCapability::CAMPAIGNS_VIEW),
            403,
        );

        $providers = array_values(array_filter(array_map(
            static fn (mixed $p): string => AdPlatforms::canonical(is_string($p) ? $p : null),
            (array) $request->query('providers', []),
        )));

        return ApiResponse::success(
            $view->build((string) app(TenantContext::class)->tenantId(), (string) $projectId, $providers === [] ? null : $providers),
            'Live operating view.',
        );
    }
}
