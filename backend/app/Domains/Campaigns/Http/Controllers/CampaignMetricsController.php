<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Per-campaign metrics for the Campaign Command Center. Everything is scoped to ONE unified campaign
 * inside the active project (tenant + project global scopes + a fail-closed campaign lookup), so a
 * cross-project or unknown campaign id returns 404 — never another campaign's numbers.
 *
 * Reuses {@see MetricsAggregator} via forCampaign() so the KPI/derivation logic is identical to the
 * project-level analytics (ROAS/CPA/CTR/CPC/CPM computed from sums, project-currency normalized).
 */
final class CampaignMetricsController extends Controller
{
    public function __construct(private readonly MetricsAggregator $agg) {}

    /** KPI totals + previous-period deltas for the campaign. */
    public function summary(Request $request, string $project, string $campaign): JsonResponse
    {
        $id = $this->resolve($request, $campaign);
        [$from, $to] = $this->range($request);
        $len = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($len - 1);

        $agg = $this->agg->forCampaign($id);
        $current = $agg->totals($from, $to);
        $previous = $agg->totals($prevFrom, $prevTo);

        $deltas = [];
        foreach ($current as $k => $v) {
            $p = $previous[$k] ?? null;
            $deltas[$k] = is_numeric($v) && is_numeric($p) && $p != 0 ? round(($v - $p) / abs($p), 4) : null;
        }

        return ApiResponse::success(
            ['current' => $current, 'previous' => $previous, 'delta' => $deltas],
            'Campaign metrics summary.',
            meta: $this->meta($from, $to),
        );
    }

    /** Daily time series (spend/revenue/results/…) for the campaign — powers the Performance tab. */
    public function performance(Request $request, string $project, string $campaign): JsonResponse
    {
        $id = $this->resolve($request, $campaign);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->agg->forCampaign($id)->timeseries($from, $to), 'Campaign performance.', meta: $this->meta($from, $to));
    }

    /** Per-platform contribution within this campaign. */
    public function platforms(Request $request, string $project, string $campaign): JsonResponse
    {
        $id = $this->resolve($request, $campaign);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->agg->forCampaign($id)->byProvider($from, $to), 'Campaign platforms.', meta: $this->meta($from, $to));
    }

    /** Budget pacing for the campaign's linked spend. */
    public function budget(Request $request, string $project, string $campaign): JsonResponse
    {
        $id = $this->resolve($request, $campaign);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->agg->forCampaign($id)->budgetPacing($from, $to, Carbon::today()), 'Campaign budget.', meta: $this->meta($from, $to));
    }

    /** Conversion funnel for the campaign. */
    public function funnel(Request $request, string $project, string $campaign): JsonResponse
    {
        $id = $this->resolve($request, $campaign);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->agg->forCampaign($id)->funnel($from, $to), 'Campaign funnel.', meta: $this->meta($from, $to));
    }

    /** Fail-closed campaign lookup: 403 without permission, 404 for cross-project/unknown ids. */
    private function resolve(Request $request, string $campaign): string
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        return (string) UnifiedCampaign::query()->findOrFail($campaign)->id;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : Carbon::today();
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : $to->copy()->subDays(29);

        return [$from->startOfDay(), $to->startOfDay()];
    }

    /** @return array<string, string> */
    private function meta(Carbon $from, Carbon $to): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => 'SAR', 'data_source' => 'daily_metrics'];
    }
}
