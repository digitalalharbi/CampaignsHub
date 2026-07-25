<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Read-only metrics aggregation for the active project (project + tenant scope enforced by
 * middleware; requires campaigns.view). Every figure is project-currency normalized and comes from
 * daily_metrics — the same tables/queries demo and real data both flow through.
 */
final class MetricsController extends Controller
{
    public function __construct(private readonly MetricsAggregator $agg) {}

    /** KPI totals for the period + the same-length previous period, with per-metric deltas. */
    public function summary(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);
        $len = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($len - 1);

        $current = $this->agg->totals($from, $to);
        $previous = $this->agg->totals($prevFrom, $prevTo);

        $deltas = [];
        foreach ($current as $k => $v) {
            $p = $previous[$k] ?? null;
            $deltas[$k] = is_numeric($v) && is_numeric($p) && $p != 0 ? round(($v - $p) / abs($p), 4) : null;
        }

        return ApiResponse::success([
            'current' => $current,
            'previous' => $previous,
            'delta' => $deltas,
        ], 'Metrics summary.', meta: $this->meta($from, $to));
    }

    public function timeseries(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->agg->timeseries($from, $to), 'Metrics time series.', meta: $this->meta($from, $to));
    }

    public function platforms(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->agg->byProvider($from, $to), 'Metrics by platform.', meta: $this->meta($from, $to));
    }

    public function campaigns(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->agg->byCampaign($from, $to), 'Metrics by campaign.', meta: $this->meta($from, $to));
    }

    public function funnel(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->agg->funnel($from, $to), 'Conversion funnel.', meta: $this->meta($from, $to));
    }

    public function budget(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        return ApiResponse::success(
            $this->agg->budgetPacing($from, $to, Carbon::today()),
            'Budget pacing.',
            meta: $this->meta($from, $to),
        );
    }

    /** Per-platform data freshness: last sync run + newest metric date, and any missing days in range. */
    public function freshness(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        $runs = MetricSyncRun::query()
            ->orderByDesc('finished_at')
            ->get()
            ->groupBy('provider')
            ->map(fn ($g) => $g->first());

        $providers = DailyMetric::query()
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->select('provider')
            ->selectRaw('MAX(metric_date) AS latest_date')
            ->selectRaw('MAX(data_freshness_at) AS freshness_at')
            ->selectRaw('COUNT(DISTINCT metric_date) AS days_with_data')
            ->groupBy('provider')
            ->get();

        $periodDays = $from->diffInDays(Carbon::today()->min($to)) + 1;
        $out = $providers->map(function ($p) use ($runs, $periodDays) {
            $run = $runs->get($p->provider);

            return [
                'provider' => $p->provider,
                'latest_metric_date' => $p->latest_date ? Carbon::parse($p->latest_date)->toDateString() : null,
                'data_freshness_at' => $p->freshness_at ? Carbon::parse($p->freshness_at)->toIso8601String() : null,
                'days_with_data' => (int) $p->days_with_data,
                'missing_days' => max(0, $periodDays - (int) $p->days_with_data),
                'last_sync_status' => $run?->status,
                'last_sync_at' => $run?->finished_at?->toIso8601String(),
                'last_sync_error' => $run?->error,
            ];
        })->all();

        return ApiResponse::success($out, 'Data freshness.', meta: $this->meta($from, $to));
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : Carbon::today();
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())
            : $to->copy()->subDays(29);

        return [$from->startOfDay(), $to->startOfDay()];
    }

    private function meta(Carbon $from, Carbon $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => 'SAR',
            'data_source' => 'daily_metrics',
        ];
    }
}
