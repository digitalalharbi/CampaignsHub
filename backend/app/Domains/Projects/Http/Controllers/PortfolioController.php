<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Projects\Services\PortfolioOverview;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * PORTFOLIO-SCOPE-001 — the agency-wide scope, asked for by name.
 *
 * ## Why its own route
 *
 * «جميع المشاريع» is a decision a reader makes, and the URL is where that decision is recorded. A
 * portfolio served from the project endpoint with the project id left out would be the fallback this
 * whole unit exists to forbid: nobody would have chosen it, and no surface could tell the two apart
 * afterwards — including the one drawing the heading.
 *
 * It answers to `projects.view`, the same permission the project listing needs, and the ESTATE it
 * describes is narrowed to what the reader may reach. Holding the permission is not the same as
 * reaching every client, and `PortfolioOverview` keeps those two apart.
 */
final class PortfolioController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function overview(Request $request, PortfolioOverview $portfolio): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('projects.view'), 403);

        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        /*
         * Twenty-nine days back through today, which is the window every other agency surface
         * defaults to. Stated here rather than left to the service so the response can echo the
         * period it answered for — a portfolio figure with no period is not checkable.
         */
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : Carbon::today();
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : $to->copy()->subDays(29);

        return ApiResponse::success(
            $portfolio->for($request->user(), (string) $this->tenant->tenantId(), $from, $to),
            'Portfolio overview.',
        );
    }
}
