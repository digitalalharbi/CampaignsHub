<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricDefinition;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\Query\Builder;
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

    /**
     * NORM-001 — what was done to these numbers before they were shown.
     *
     * Every `daily_metrics` row already carries its own provenance: the currency it arrived in and the
     * one it was converted to, the rate used, the platform's timezone and the project's, the attribution
     * window, whether the row came from an API or from demo data, and when it was fetched. None of it
     * reached a reader. Spend was displayed converted with no statement that a conversion had happened,
     * and `meta()` announced `SAR` as a constant — a claim the data was never asked to support.
     *
     * The distinction this endpoint exists to make is between a figure and a figure's basis. Two
     * campaigns whose spend was collected under different attribution windows are not comparable, and a
     * dashboard that shows them side by side without saying so is not wrong in its arithmetic — it is
     * wrong in what the reader will conclude. So each section reports what is ACTUALLY in the range,
     * including the cases nobody wants: more than one project currency, more than one attribution
     * window, demo rows mixed with real ones.
     *
     * Everything here is derived from the rows in range. Nothing is defaulted, and an empty section is
     * returned as an empty list with its own count rather than omitted, so «no conversions happened»
     * and «this was never computed» cannot be confused.
     */
    public function normalization(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        $scope = fn () => DailyMetric::query()
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->when($this->providerFilter($request) !== [], fn ($q) => $q->whereIn('provider', $this->providerFilter($request)))
            ->toBase();

        // Money rows only. `original_currency` is null on impressions and clicks — a count has no
        // currency, and treating those nulls as an unknown currency would invent a warning.
        $currencies = $scope()
            ->whereNotNull('original_currency')
            ->select('original_currency', 'project_currency')
            ->selectRaw('COUNT(*) AS rows_count')
            ->selectRaw('MIN(exchange_rate) AS rate_min')
            ->selectRaw('MAX(exchange_rate) AS rate_max')
            ->selectRaw('MAX(metric_date) AS latest_date')
            ->groupBy('original_currency', 'project_currency')
            ->get()
            ->map(fn ($r) => [
                'from' => (string) $r->original_currency,
                'to' => (string) $r->project_currency,
                'converted' => $r->original_currency !== $r->project_currency,
                'rows' => (int) $r->rows_count,
                'rate_min' => $r->rate_min !== null ? round((float) $r->rate_min, 6) : null,
                'rate_max' => $r->rate_max !== null ? round((float) $r->rate_max, 6) : null,
                'latest_date' => $r->latest_date ? Carbon::parse($r->latest_date)->toDateString() : null,
            ])
            ->values()
            ->all();

        $timezones = $scope()
            ->whereNotNull('original_timezone')
            ->select('original_timezone', 'project_timezone')
            ->selectRaw('COUNT(*) AS rows_count')
            ->groupBy('original_timezone', 'project_timezone')
            ->get()
            ->map(fn ($r) => [
                'from' => (string) $r->original_timezone,
                'to' => (string) $r->project_timezone,
                'shifted' => $r->original_timezone !== $r->project_timezone,
                'rows' => (int) $r->rows_count,
            ])
            ->values()
            ->all();

        $windows = $scope()
            ->select('attribution_window')
            ->selectRaw('COUNT(*) AS rows_count')
            ->groupBy('attribution_window')
            ->orderByDesc('rows_count')
            ->get()
            ->map(fn ($r) => ['window' => (string) $r->attribution_window, 'rows' => (int) $r->rows_count])
            ->all();

        $sources = $scope()
            ->select('source_type', 'is_demo')
            ->selectRaw('COUNT(*) AS rows_count')
            ->groupBy('source_type', 'is_demo')
            ->orderByDesc('rows_count')
            ->get()
            ->map(fn ($r) => [
                'source_type' => (string) $r->source_type,
                'is_demo' => (bool) $r->is_demo,
                'rows' => (int) $r->rows_count,
            ])
            ->all();

        // The project currency the figures are actually expressed in. More than one is a real state —
        // a project re-denominated mid-period — and it is REPORTED rather than resolved by taking the
        // first, because picking one silently is how a total ends up labelled in a currency half of it
        // is not in.
        $projectCurrencies = array_values(array_unique(array_filter(array_map(
            fn (array $c) => $c['to'],
            $currencies,
        ))));

        return ApiResponse::success([
            'project_currency' => $projectCurrencies[0] ?? null,
            'project_currencies' => $projectCurrencies,
            'currencies' => $currencies,
            'timezones' => $timezones,
            'attribution_windows' => $windows,
            'sources' => $sources,
            'objectives' => $this->objectivesInRange($scope()),
            'catalogue' => $this->catalogue(),
            'unread_metric_keys' => $this->unreadMetricKeys($scope()),
        ], 'How these numbers were normalized.', meta: $this->meta($from, $to));
    }

    /**
     * The objectives present in the range, and what may be compared across them.
     *
     * A cost-per-result is only a like-for-like number when the two campaigns count the same result.
     * Spend, impressions and clicks mean the same thing whatever the campaign was for; leads, installs
     * and purchases do not, and neither do the costs derived from them. The split is returned rather
     * than a boolean so the UI can name the metrics that survive a mixed-objective comparison instead
     * of refusing the whole comparison or — worse — allowing it silently.
     *
     * @return array<string, mixed>
     */
    private function objectivesInRange(Builder $scoped): array
    {
        /*
         * The campaign ids come from the SCOPED metric query, and the campaigns are read through the
         * model so `TenantScope` and the project scope both apply.
         *
         * The first version of this reached for `DB::table('daily_metrics')` inside a subquery, which
         * has no global scopes at all: it answered with every objective in the INSTALLATION. The live
         * review caught it because the page contradicted itself — every other row said «no data in this
         * period» while this one confidently reported campaigns. It would not have contradicted itself
         * on a project that had data, and then it would simply have been another tenant's answer,
         * printed without a mark.
         */
        $campaignIds = (clone $scoped)
            ->whereNotNull('unified_campaign_id')
            ->distinct()
            ->pluck('unified_campaign_id')
            ->all();

        $rows = $campaignIds === [] ? [] : UnifiedCampaign::query()
            ->whereIn('id', $campaignIds)
            ->toBase()
            ->select('objective')
            ->selectRaw('COUNT(*) AS campaigns')
            ->groupBy('objective')
            ->orderByDesc('campaigns')
            ->get()
            ->map(fn ($r) => ['objective' => (string) ($r->objective ?? 'unset'), 'campaigns' => (int) $r->campaigns])
            ->all();

        return [
            'present' => $rows,
            'mixed' => count($rows) > 1,
            // Comparable whatever the objective: media delivery and its direct costs.
            'comparable_metrics' => ['spend', 'impressions', 'clicks', 'reach', 'ctr', 'cpc', 'cpm', 'frequency'],
            // Objective-defined: the same column holds a different event per objective.
            'objective_specific_metrics' => [
                'conversions', 'leads', 'qualified_leads', 'purchases', 'installs', 'registrations',
                'in_app_events', 'engagements', 'revenue', 'cpa', 'cpl', 'cpi', 'cpe', 'aov', 'roas',
                'conversion_rate', 'engagement_rate',
            ],
        ];
    }

    /**
     * The canonical metric catalogue, so a reader can find out what a column means and whether it may
     * be summed. Empty until `MetricDefinitionSeeder` has run; `available` says which it is rather than
     * letting an empty list read as «this product defines no metrics».
     *
     * @return array<string, mixed>
     */
    private function catalogue(): array
    {
        $rows = MetricDefinition::query()
            ->orderByDesc('is_additive')
            ->orderBy('key')
            ->get(['key', 'name', 'unit', 'value_type', 'default_aggregation', 'is_currency', 'is_additive']);

        return [
            'available' => $rows->isNotEmpty(),
            'metrics' => $rows->map(fn (MetricDefinition $d) => [
                'key' => $d->key,
                'name' => $d->name,
                'unit' => $d->unit,
                'aggregation' => $d->default_aggregation,
                'is_currency' => (bool) $d->is_currency,
                'is_additive' => (bool) $d->is_additive,
            ])->all(),
        ];
    }

    /**
     * Metric keys stored in this project's data that no KPI on any surface reads.
     *
     * Measured against the union of the aggregator's pivot AND the funnel's stages, not the pivot
     * alone: `add_to_cart` and `checkout` are absent from `PIVOT` but are funnel stages, so measuring
     * against `PIVOT` would report two keys as ignored when both are read. A silent omission here
     * would let a page that counts eight of ten stored metrics read as if it counted all ten.
     *
     * @return list<string>
     */
    private function unreadMetricKeys(Builder $scoped): array
    {
        $stored = $scoped->distinct()->pluck('metric_key')->map(fn ($k) => (string) $k)->all();

        return array_values(array_diff($stored, MetricsAggregator::readKeys()));
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

    /**
     * `currency` was the literal `'SAR'` on every metrics response (NORM-001).
     *
     * It was right for this installation and wrong as a statement: it said the same thing for a project
     * denominated in anything else, and it said it whether or not there was a single money row in the
     * range to be denominated. It is read from the rows now, and is `null` when the range holds no
     * money — which is the honest answer, and one a caller can act on, where a confident «SAR» over an
     * empty period is not.
     */
    private function meta(Carbon $from, Carbon $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $this->rangeCurrency($from, $to),
            'data_source' => 'daily_metrics',
        ];
    }

    /** The project currency the money rows in this range are actually expressed in, or null if none are. */
    private function rangeCurrency(Carbon $from, Carbon $to): ?string
    {
        $value = DailyMetric::query()
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('project_currency')
            ->toBase()
            ->value('project_currency');

        return $value !== null ? (string) $value : null;
    }
}
