<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Commerce\Services\StoreFunnelService;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Metrics\Models\MetricDefinition;
use App\Domains\Metrics\Services\AttributionTransparency;
use App\Domains\Metrics\Services\DataFreshnessService;
use App\Domains\Metrics\Services\EntityMetricsAggregator;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\ObjectivePerformance;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
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
    public function __construct(
        private readonly MetricsAggregator $agg,
        private readonly DataFreshnessService $freshness,
    ) {}

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
            /*
             * UX-METRICS-001 — which of those figures are measurements and which are absences.
             *
             * `current` coalesces to 0, so on its own a KPI card cannot tell «this platform does not
             * report landing-page views» from «it reported none». The map says which, per base
             * metric, and the strip renders «لم ترسله المنصة» rather than a zero for the first.
             */
            'reported' => $this->scoped($request)->reportedKeys($from, $to),
            /*
             * MONEY-TRUTH-001 — the currency the converted figures are IN.
             *
             * It was in `meta` only, and `meta` is not carried through the summary hook, so every
             * money surface had to assume one. A generic helper defaulting to SAR states the wrong
             * unit the first time a project reports in anything else, silently — so the payload says
             * it rather than leaving each caller to guess.
             *
             * Null when this range holds no money rows at all: there is then no currency to name, and
             * inventing one would be the same class of lie one level up.
             */
            'currency' => $this->rangeCurrency($from, $to),
            /*
             * ANALYTICS-PROVENANCE-001 — live, demo, both, or nothing.
             *
             * The badge was rendered unconditionally on the dashboard, campaigns and analytics, so a
             * project syncing real Snapchat spend was labelled «بيانات تجريبية · Demo» beside its own
             * money. Derived from `is_demo` on the rows actually in scope — not from the environment
             * and not from a frontend constant, neither of which knows whose rows these are.
             */
            'provenance' => $this->scoped($request)->provenance($from, $to),
            'commerce' => $this->commerce($request, $from, $to),
            /*
             * REPORT-OBJECTIVE-005 — what the single «conversions» figure above is.
             *
             * It is the SUM of each platform's own claim, and those claims overlap: one sale clicked
             * from two platforms is reported in full by both, and no shared key exists that would let
             * us prove they are the same sale. So it is not a count of unique orders, and the payload
             * says so rather than leaving every page to remember.
             */
            'conversions_basis' => $this->scoped($request)->conversionsBasis($from, $to),
        ], 'Metrics summary.', meta: $this->meta($from, $to));
    }

    /**
     * The store's own figures on the dashboard — taken from the funnel service, never recomputed here.
     *
     * ## Why the dashboard needs them at all
     *
     * `daily_metrics` carries `revenue` as the ad platforms report it: a pixel's estimate of what it
     * believes its clicks caused. The shop's ledger is a different and better number, and after
     * COMMERCE-001 the product holds both. A dashboard that showed only the first, while the analytics
     * tab beside it showed the second, gave two answers to «كم بعنا؟» with nothing to say which was
     * which — so the store block is labelled as the store's, and sits next to the platforms' rather
     * than replacing them.
     *
     * ## Why the page's filters do not narrow it, and why it says so
     *
     * Spend and impressions narrow to the platform and objective the operator picked; an order does
     * not. A large share of orders carry no usable attribution at all — that is exactly what
     * `unattributed_orders` counts — so «Meta's share of the shop's revenue» is not a quantity that
     * exists to be shown beside «Meta's spend».
     *
     * The first cut of this suppressed the block whenever a filter was on. That was wrong in practice
     * as well as unhelpful: the dashboard opens on an objective filter, so the block would have shown
     * a refusal permanently and its figures never. Whole-shop numbers with `filtered_view` set — and a
     * line on the card saying the block is not filtered — carry the same warning without withholding
     * the answer. The misreading the flag guards against is «this is Meta's revenue»; a label that
     * says otherwise, on the card, closes it.
     *
     * @return array<string,mixed>|null
     */
    private function commerce(Request $request, Carbon $from, Carbon $to): ?array
    {
        $projectId = app(ProjectContext::class)->projectId();

        if ($projectId === null) {
            return null;
        }

        $funnel = app(StoreFunnelService::class)->build(
            (string) app(TenantContext::class)->tenantId(),
            (string) $projectId,
            $from,
            $to,
        );

        if (($funnel['coverage']['stores'] ?? 0) === 0) {
            return null;
        }

        return [
            'available' => true,
            /*
             * True when the rest of the page is narrowed and this block is not. The card renders a
             * sentence off this, so nobody reads a whole-shop figure as one platform's share.
             */
            'filtered_view' => $this->providerFilter($request) !== []
                || $this->objectiveFilter($request) !== []
                || $this->campaignFilter($request) !== [],
            'unfiltered_note_ar' => 'أرقام المتجر لكامل المتجر ولا تتأثر بفلتر المنصة أو الهدف، لأن جزءًا من الطلبات يصل بلا إسناد.',
            'unfiltered_note_en' => 'Store figures cover the whole shop and are not narrowed by the platform or objective filter — some orders arrive with no attribution.',
            'orders' => $funnel['totals']['orders'],
            'revenue' => $funnel['totals']['revenue'],
            // COMMERCE-FX-001 — the currency the money below is stated in, and how many orders are
            // missing from it. The dashboard strip printed «SAR» from a constant either way.
            'reporting_currency' => $funnel['totals']['reporting_currency'],
            'orders_with_money_withheld' => $funnel['coverage']['orders_with_money_withheld'],
            'money_withheld_currencies' => $funnel['coverage']['money_withheld_currencies'],
            // COMMERCE-TZ-001 — which clock this window was measured on, and how many orders had
            // their zone assumed rather than stated. An assumption nobody can see is the defect.
            'reporting_timezone' => $funnel['totals']['reporting_timezone'],
            'orders_with_assumed_timezone' => $funnel['coverage']['orders_with_assumed_timezone'],
            'attributed_orders' => $funnel['totals']['attributed_orders'],
            'attributed_revenue' => $funnel['totals']['attributed_revenue'],
            'unattributed_orders' => $funnel['totals']['unattributed_orders'],
            'aov' => $funnel['derived']['aov'],
            'roas' => $funnel['derived']['roas'],
            'cac' => $funnel['derived']['cac'],
            'stores' => $funnel['coverage']['stores'],
            'store_last_synced_at' => $funnel['coverage']['store_last_synced_at'],
        ];
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

        // `data` stays the stage list every caller already renders; the spend it is all derived from
        // rides in `meta`, so the funnel can be reconciled against the dashboard without changing
        // the shape of the payload (UNIFIED-002).
        $funnel = $this->scoped($request)->funnel($from, $to);

        return ApiResponse::success(
            $funnel['stages'],
            'Conversion funnel.',
            meta: $this->meta($from, $to) + ['spend' => $funnel['spend']],
        );
    }

    /**
     * Performance separated by marketing path, with Direct and Blended kept apart
     * (REPORT-OBJECTIVE-001/003).
     *
     * The endpoint every objective-aware report reads. It never returns a bare `cpa` at the top
     * level: `direct.cpa` is the sales path's own cost per order, `blended.blended_cpa` is what the
     * whole programme cost per order, and both carry the formula and the campaigns they counted.
     * Printing the second as «CPA» is the critical defect this exists to prevent.
     */
    public function objectivePerformance(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        $campaigns = array_values(array_filter((array) $request->input('campaign_ids', [])));

        return ApiResponse::success(
            (new ObjectivePerformance(campaignIds: $campaigns === [] ? null : $campaigns))->build($from, $to),
            'Performance by objective.',
            meta: $this->meta($from, $to),
        );
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
            // FX-001. A row whose conversion was WITHHELD carries its original amount and no value,
            // and it must be countable: `SUM` skips nulls, so a total that quietly excluded these
            // would under-report and look exactly like a total that included everything.
            ->selectRaw('COUNT(*) FILTER (WHERE value IS NULL) AS withheld_count')
            ->groupBy('original_currency', 'project_currency')
            ->get()
            ->map(fn ($r) => [
                'from' => (string) $r->original_currency,
                'to' => (string) $r->project_currency,
                'converted' => $r->original_currency !== $r->project_currency,
                'rows' => (int) $r->rows_count,
                // Rows excluded from every monetary total in this window, because no rate for their
                // date could be vouched for. Zero is the normal answer and the one to expect.
                'withheld' => (int) $r->withheld_count,
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

    /**
     * Data freshness for the active project — every source, one set of rules (UNIFIED-001).
     *
     * This used to be a query in this method over `daily_metrics` and `metric_sync_runs`, which meant
     * the dashboard strip could only ever describe the ad platforms. A project whose store had not been
     * swept in a week showed nothing amiss here while revenue and ROAS on the same dashboard came off
     * that store. It reads {@see DataFreshnessService} now, so stores appear as sources beside the
     * platforms, and the verdict on the badge is the same verdict the client link and the client
     * analytics header show.
     *
     * The response keeps its previous per-provider shape and adds the store rows and a rolled-up
     * `summary` — no field was removed, so the existing strip keeps rendering.
     */
    public function freshness(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        $projectId = app(ProjectContext::class)->projectId();
        $tenantId = (string) app(TenantContext::class)->tenantId();

        if ($projectId === null) {
            return ApiResponse::success([], 'Data freshness.', meta: $this->meta($from, $to));
        }

        $providerFilter = $this->providerFilter($request);
        $state = $this->freshness->state(
            $tenantId,
            [(string) $projectId],
            $from,
            $to,
            $providerFilter === [] ? null : $providerFilter,
        );

        // Days-with-data stays per provider: it is the one figure the strip shows that is about THIS
        // window rather than about the source, and the service reports gaps for the project as a whole.
        $daysWithData = DailyMetric::query()
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->when($providerFilter !== [], fn ($q) => $q->whereIn('provider', $providerFilter))
            ->toBase()
            ->select('provider')
            ->selectRaw('COUNT(DISTINCT metric_date) AS days_with_data')
            ->groupBy('provider')
            ->pluck('days_with_data', 'provider');

        $periodDays = $from->diffInDays(Carbon::today()->min($to)) + 1;

        $out = array_map(function (array $source) use ($daysWithData, $periodDays): array {
            $days = $source['kind'] === 'ad_platform' ? (int) ($daysWithData[$source['provider']] ?? 0) : null;

            return [
                'kind' => $source['kind'],
                'provider' => $source['provider'],
                'account_id' => $source['account_id'],
                'name' => $source['name'],
                'latest_metric_date' => $source['latest_metric_date'],
                'data_freshness_at' => $source['data_as_of'],
                'days_with_data' => $days,
                'missing_days' => $days === null ? null : max(0, $periodDays - $days),
                'last_sync_status' => $source['state'],
                'last_sync_at' => $source['last_checked_at'],
                'last_sync_error' => $source['last_sync_error'],
            ];
        }, $state['sources']);

        return ApiResponse::success($out, 'Data freshness.', meta: $this->meta($from, $to) + [
            'summary' => [
                'state' => $state['state'],
                'last_sync_at' => $state['last_sync_at'],
                'missing_days' => $state['missing_days'],
                'sync_failed' => $state['sync_failed'],
            ],
        ]);
    }

    /**
     * REPORT-OBJECTIVE-005 — who is answering «كم بعنا؟», and what may be added up.
     *
     * Every figure here comes from the same two places the rest of the product reads: `daily_metrics`
     * for what the platforms reported, and {@see ProjectOrders} for what the store confirmed. This
     * endpoint computes no sales of its own — it states the provenance of sales already counted
     * elsewhere, which is the only way its numbers can be guaranteed to match the pages it explains.
     */
    public function attribution(Request $request, AttributionTransparency $transparency): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        $tenantId = (string) app(TenantContext::class)->tenantId();
        $projectId = (string) app(ProjectContext::class)->projectId();

        abort_if($tenantId === '' || $projectId === '', 400, 'No active project.');

        return ApiResponse::success(
            $transparency->build($tenantId, $projectId, $from, $to, $this->providerFilter($request)),
            'Attribution transparency and de-duplication.',
            meta: $this->meta($from, $to),
        );
    }

    // ---- helpers ----------------------------------------------------------------------------------

    /**
     * ANALYTICS-DRILLDOWN-001 — the ad-squad and ad rungs, for the Analytics tabs that had no data.
     *
     * ## Why this endpoint exists
     *
     * Analytics could show Overview, Platform and Campaign because `daily_metrics` answers at the
     * campaign grain. It had no Ad Set tab and no Ads tab because there was no table beneath that —
     * 187 ad squads and 5,706 ads on the live account with nowhere to read a number from.
     * `entity_daily_metrics` now holds them and `EntityMetricsAggregator` reads them; this is the
     * only thing between that data and a screen.
     *
     * ## Filters narrow the QUERY, never the response
     *
     * `parent` is applied inside the aggregator's SQL, not by filtering rows after the fact. The
     * difference matters on an account with 5,706 ads: post-filtering means fetching all of them to
     * show twenty, and it means a paginated total that lies about how many there are.
     *
     * The window, provider, objective and attribution basis all come from the same request helpers
     * every other metric endpoint uses, so a drill-down cannot silently change basis as it descends.
     */
    public function entities(Request $request, string $project, string $level): JsonResponse
    {
        $this->authorizeView($request);

        /*
         * Only the two rungs this table holds. An unknown grain is refused rather than answered
         * emptily — an empty list reads as «this ad set has no data», which is a different and
         * wrong statement from «there is no such level».
         */
        // $project is the group's own {project} binding and is named here only so $level lands on
        // the right argument — Laravel passes route parameters positionally to a controller action.
        unset($project);

        abort_unless(
            in_array($level, [EntityDailyMetric::AD_SET, EntityDailyMetric::AD], true),
            404,
            'Unknown entity level.',
        );

        [$from, $to] = $this->range($request);

        $projectId = app(ProjectContext::class)->projectId();
        abort_if($projectId === null, 400, 'A project is required to read entity metrics.');

        $parents = $this->parentFilter($request);

        $rows = app(EntityMetricsAggregator::class)->byEntity(
            (string) $projectId,
            $level,
            $from,
            $to,
            $parents,
            $request->filled('attribution_window') ? $request->string('attribution_window')->toString() : null,
        );

        return ApiResponse::success([
            'entities' => $this->nameEntities($level, $rows),
            'entity_type' => $level,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            // What the money on these rows is IN — the same statement every other surface makes.
            'currency' => $this->rangeCurrency($from, $to),
            'attribution_window' => $request->string('attribution_window')->toString() ?: null,
        ], 'Entity metrics.');
    }

    /**
     * The parents to narrow to, or null for «every entity of this grain».
     *
     * An EXPLICITLY EMPTY `parent=` is not the same as an absent one: it means «the parent I chose
     * has no children», and answering it with everything in the project is how a drill-down stops
     * being a drill-down.
     *
     * @return list<string>|null
     */
    private function parentFilter(Request $request): ?array
    {
        if (! $request->has('parent')) {
            return null;
        }

        $raw = $request->string('parent')->toString();

        return $raw === '' ? [] : array_values(array_filter(explode(',', $raw)));
    }

    /**
     * Put a NAME on each row — a drill-down of provider ids is not a screen anybody can use.
     *
     * The figures come from `entity_daily_metrics` and the identity from the structure tables, joined
     * here rather than denormalised into the metrics rows: a renamed ad squad must not need its
     * metrics rewritten, and the metrics table has no business holding a name that can change.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function nameEntities(string $entityType, array $rows): array
    {
        $ids = array_values(array_filter(array_column($rows, 'entity_id')));

        if ($ids === []) {
            return [];
        }

        $named = $entityType === EntityDailyMetric::AD
            ? ExternalAd::withoutGlobalScopes()->whereIn('id', $ids)
                ->get(['id', 'name', 'status', 'external_id', 'external_ad_set_id', 'external_campaign_id'])
            : ExternalAdSet::withoutGlobalScopes()->whereIn('id', $ids)
                ->get(['id', 'name', 'status', 'external_id', 'external_campaign_id']);

        $byId = $named->keyBy(fn ($m): string => (string) $m->getKey());

        return array_map(static function (array $row) use ($byId): array {
            $entity = $byId->get((string) $row['entity_id']);

            return [
                ...$row,
                // Null rather than a placeholder: a row whose entity the structure sweep has since
                // removed is a real state, and inventing «Unknown ad set» would hide it.
                'name' => $entity?->name,
                'status' => $entity?->status,
            ];
        }, $rows);
    }

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
        $objectives = $this->listFilter($request, 'objective');

        /*
         * ANALYTICS-OBJECTIVE-FILTERS-001 — a FAMILY narrows the query, not just the KPI order.
         *
         * `?objective=` takes exact objective values, which is right for a precise filter and wrong
         * for the control an operator actually wants: «show me the awareness work» means awareness
         * AND reach, and «sales» means sales, conversions, purchases and add-to-cart. Making the
         * user tick four boxes to mean one thing is how a filter stops being used.
         *
         * Expanded to member objectives HERE, so the narrowing happens in the aggregator's SQL. A
         * family resolved in the frontend would filter rows already aggregated across every
         * objective — the totals would still be the unfiltered ones, and the page would look
         * filtered while telling the truth about nothing.
         */
        foreach ($this->listFilter($request, 'objective_family') as $family) {
            $case = ObjectiveFamily::tryFrom($family);

            if ($case === null) {
                continue;
            }

            foreach (CampaignObjective::cases() as $objective) {
                if ($objective->family() === $case) {
                    $objectives[] = $objective->value;
                }
            }
        }

        return array_values(array_unique($objectives));
    }

    /**
     * The campaign filter from the request (?campaign=<uuid>,<uuid>). Empty when absent.
     *
     * UX-DASH-001 put a campaign control on the dashboard's visible filter row, and a control on the
     * page has to narrow the figures on the page — the contract's own words are that a filter which
     * does not work is worse than no filter. So it is read here and applied through the aggregator's
     * existing campaign bound rather than by a component filtering rows it already fetched: every
     * KPI, the chart, the funnel and the pacing table narrow together, which a client-side filter
     * over one endpoint's response could not do.
     *
     * @return list<string>
     */
    private function campaignFilter(Request $request): array
    {
        return $this->listFilter($request, 'campaign');
    }

    /** A comma-list or repeated query parameter, trimmed, with the empties dropped. @return list<string> */
    private function listFilter(Request $request, string $key): array
    {
        $raw = $request->query($key, []);
        $list = is_array($raw) ? $raw : ($raw === '' ? [] : explode(',', (string) $raw));

        return array_values(array_filter(array_map('trim', $list)));
    }

    /** The aggregator scoped by the dashboard's platform, objective and campaign filters. */
    private function scoped(Request $request): MetricsAggregator
    {
        $campaigns = $this->campaignFilter($request);

        $agg = $this->agg
            ->forProviders($this->providerFilter($request))
            ->forObjectives($this->objectiveFilter($request));

        /*
         * Applied only when the operator asked for one — `forCampaigns([])` means «no campaigns» to
         * this aggregator, not «all of them» (it is the fail-closed bound a shared link's ceiling
         * uses). Passing the empty filter straight through would have emptied the dashboard for
         * everybody who had not picked a campaign.
         */
        return $campaigns === [] ? $agg : $agg->forCampaigns($campaigns);
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
