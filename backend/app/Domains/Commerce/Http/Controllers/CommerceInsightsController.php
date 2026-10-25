<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Http\Controllers;

use App\Domains\Commerce\Services\CommerceInsightsService;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** GET /projects/{project}/commerce/insights — COMMERCE-INSIGHTS-001. Read-only; the project's own stores only. */
final class CommerceInsightsController extends Controller
{
    public function __construct(
        private readonly CommerceInsightsService $insights,
        private readonly ProjectContext $project,
        private readonly TenantContext $tenant,
    ) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('analytics.view'), 403);
        $data = $request->validate([
            'from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'compare' => ['sometimes', 'in:previous,none'],
        ]);

        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : Carbon::now()->endOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        return ApiResponse::success(
            $this->insights->build((string) $this->tenant->tenantId(), (string) $this->project->projectId(), $from, $to, ($data['compare'] ?? 'previous') === 'previous'),
            'Commerce insights.',
        );
    }
}
