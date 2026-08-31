<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Http\Controllers;

use App\Domains\Commerce\Services\StoreFunnelExplanation;
use App\Domains\Commerce\Services\StoreFunnelService;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * FUNNEL-001 — «الفانل والمتجر», project-scoped.
 *
 * The window is validated rather than trusted: an unbounded range over a busy store is a query that
 * reads every order the tenant has ever placed, from an endpoint any project member can call.
 */
final class StoreFunnelController extends Controller
{
    public function __construct(
        private readonly StoreFunnelService $funnel,
        private readonly ProjectContext $project,
        private readonly TenantContext $tenant,
    ) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.view'), 403);

        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : Carbon::now()->endOfDay();
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : $to->copy()->subDays(29)->startOfDay();

        // A year is more than any client report asks for and bounds the read.
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        $funnel = $this->funnel->build((string) $this->tenant->tenantId(), (string) $this->project->projectId(), $from, $to);

        /*
         * FUNNEL-ANALYTICAL-PATTERN-001 — the reading travels with the stages it reads.
         *
         * Computed here rather than on the client because the drop between two stages is a claim
         * about the data, and a claim computed twice is a claim that will eventually be made twice
         * differently. It is derived from the stages in this same response, so the two cannot
         * disagree about which fall is the largest.
         */
        $funnel['reading'] = (new StoreFunnelExplanation)->explain($funnel['stages'] ?? []);

        return ApiResponse::success($funnel, 'Store funnel.');
    }
}
