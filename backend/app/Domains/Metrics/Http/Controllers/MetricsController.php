<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Campaigns\Models\UnifiedCampaign;
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

        $current = $this->scoped($request)->totals($from, $to);
        $previous = $this->scoped($request)->totals($prevFrom, $prevTo);

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

        return ApiResponse::success($this->scoped($request)->timeseries($from, $to), 'Metrics time series.', meta: $this->meta($from, $to));
    }

    public function platforms(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->scoped($request)->byProvider($from, $to), 'Metrics by platform.', meta: $this->meta($from, $to));
    }

    /**
     * CAMPAIGN-020: compare 2–5 campaigns of the SAME project side by side over one window.
     *
     * Campaign ids are validated to exist inside the active project, so a caller cannot pull another
     * project's (or tenant's) campaign into a comparison. Mixed objectives are returned as-is with each
     * campaign's own objective attached — the UI must not blend KPIs across different objectives.
     */
    public function compare(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        $data = $request->validate([
            'campaign_ids' => ['required', 'array', 'min:2', 'max:5'],
            'campaign_ids.*' => ['required', 'uuid'],
        ]);

        // Fail closed: only ids that really belong to the active project survive.
        $ids = UnifiedCampaign::query()
            ->whereIn('id', $data['campaign_ids'])
            ->pluck('id')
            ->all();

        abort_if(count($ids) < 2, 422, 'Pick at least two campaigns from this project to compare.');

        $rows = $this->scoped($request)->compare($ids, $from, $to);
        $objectives = array_values(array_unique(array_filter(array_column($rows, 'objective'))));

        return ApiResponse::success([
            'campaigns' => $rows,
            // The UI shows a warning instead of a blended total when this is true.
            'mixed_objectives' => count($objectives) > 1,
            'objectives' => $objectives,
        ], 'Campaign comparison.', meta: $this->meta($from, $to));
    }

    public function campaigns(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->scoped($request)->byCampaign($from, $to), 'Metrics by campaign.', meta: $this->meta($from, $to));
    }

    public function funnel(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->scoped($request)->funnel($from, $to), 'Conversion funnel.', meta: $this->meta($from, $to));
    }

    public function budget(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        return ApiResponse::success(
            $this->scoped($request)->budgetPacing($from, $to, Carbon::today()),
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

        $providerFilter = $this->providerFilter($request);
        $providers = DailyMetric::query()
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->when($providerFilter !== [], fn ($q) => $q->whereIn('provider', $providerFilter))
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

    /**
     * The dashboard platform filter from the request (?provider=meta,google_ads — comma-list or repeated).
     * Empty when absent. Backend-supported so every metric respects it (never a React-only filter).
     *
     * @return list<string>
     */
    private function providerFilter(Request $request): array
    {
        $raw = $request->query('provider', []);
        $list = is_array($raw) ? $raw : ($raw === '' ? [] : explode(',', (string) $raw));

        return array_values(array_filter(array_map('trim', $list)));
    }

    /** The objective filter from the request (?objective=sales,leads). Empty when absent. @return list<string> */
    private function objectiveFilter(Request $request): array
    {
        $raw = $request->query('objective', []);
        $list = is_array($raw) ? $raw : ($raw === '' ? [] : explode(',', (string) $raw));

        return array_values(array_filter(array_map('trim', $list)));
    }

    /** The aggregator scoped by the dashboard platform + objective filters (backend-supported). */
    private function scoped(Request $request): MetricsAggregator
    {
        return $this->agg
            ->forProviders($this->providerFilter($request))
            ->forObjectives($this->objectiveFilter($request));
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
