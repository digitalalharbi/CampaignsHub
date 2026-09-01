<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\CampaignOutcome;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Commerce\Services\StoreFunnelService;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Metrics\Models\MetricDefinition;
use App\Domains\Metrics\Services\AttributionTransparency;
use App\Domains\Metrics\Services\BudgetExplanation;
use App\Domains\Metrics\Services\DataFreshnessService;
use App\Domains\Metrics\Services\EntityMetricsAggregator;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\ObjectivePerformance;
use App\Domains\Metrics\Support\EntityScope;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Read-only metrics aggregation for the active project (project + tenant scope enforced by
 * middleware; requires campaigns.view). Every figure is project-currency normalized and comes from
 * daily_metrics — the same tables/queries demo and real data both flow through.
 */
final class MetricsController extends Controller
{
    /**
     * How many options one request returns.
     *
     * Matches what `FilterMulti` will draw, so the client never holds rows it cannot show — and the
     * search that reaches everything now reaches it through the server rather than through a payload
     * the browser already downloaded.
     */
    private const OPTION_LIMIT = 120;

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
             * METRICS-EMPTY-SCOPE-001 — «no rows here» is not «the platform does not report this».
             *
             * `reportedKeys()` answers by asking which metric keys are PRESENT in the scope, so an
             * empty scope returns every key false — and the strip renders «لم ترسله المنصة» under
             * each one. Narrow the objective filter to a family this project never bought and the
             * dashboard states that the platform sends no impressions, which is a claim about a
             * connector derived from an absence of campaigns.
             *
             * That is what «تغيير الأهداف يجعل كل شيء فارغًا» looks like from the inside: not a
             * broken screen, a screen confidently saying something false.
             *
             * So the payload carries whether the SCOPE holds anything at all. A reader with no rows
             * shows one honest sentence about the filter; only a scope that HAS rows may speak about
             * what the platform did or did not report inside it.
             */
            'rows_in_scope' => $this->scoped($request)->hasRows($from, $to),
            /*
             * ANALYTICS-COMPARE-001 — whether a comparison was POSSIBLE, not merely whether it moved.
             *
             * Every delta above divides by the previous period's figure and returns null when that
             * figure is 0. Both «this metric did not change from a base of nothing» and «there is no
             * previous period at all» arrive at the card as the same null, and the card renders the
             * same «— —» for each.
             *
             * Production has 15 days of rows and offers a 30-day range, so the whole comparison
             * window falls before the first row that exists. Six cards then print six mute dashes
             * under a heading that promises «مقارنة بالفترة السابقة» — the page states a comparison
             * it never had the data to make, and gives the reader no way to tell that from a flat
             * month.
             *
             * The scope answers it directly: a comparison window with no rows cannot be compared
             * against, and the page says so once instead of six times in a notation for «unchanged».
             */
            'previous_rows_in_scope' => $this->scoped($request)->hasRows($prevFrom, $prevTo),
            'previous_range' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            /*
             * HEADLINE-SCOPE-001 — the headline follows what is IN scope, not what the filter says.
             *
             * `layoutFor('all', 'all')` returns the operational row — spend, impressions, clicks,
             * CTR — and deliberately withholds cost-per and return, because a CPA computed across a
             * brand budget and a sales budget divides one objective's money by another objective's
             * events. That reasoning is right and it was being applied to the wrong question.
             *
             * «كل الأهداف» is a statement about the FILTER. A project whose campaigns are all Sales
             * has one objective in scope whether or not the reader narrowed to it, and the board was
             * withholding ROAS and cost per order from it on the grounds that the scope might be
             * mixed — when the rows themselves say it is not.
             *
             * So the scope reports the families it actually contains. One family means the board can
             * headline that family's own metrics; several still means the operational row, for the
             * original and unchanged reason.
             */
            'objective_families_in_scope' => $this->scoped($request)->objectiveFamiliesInScope($from, $to),
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

    /**
     * ANALYTICS-DRILLDOWN-001 — the accounts beneath a platform.
     *
     * The chain read Platform → Campaign, skipping the level an operator manages. A customer can
     * hold several ad accounts on one platform, and «Snapchat spent X» is not an answer when two
     * accounts run different markets from different budgets.
     */
    public function accounts(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        return ApiResponse::success(
            $this->scoped($request)->byAccount($from, $to),
            'Metrics by ad account.',
            meta: $this->meta($from, $to),
        );
    }

    /**
     * UX-MULTISELECT-SCALE-001 — the campaign filter's OPTIONS, searched on the server.
     *
     * The selector was populated from `campaigns()`, the full metric breakdown. `FilterMulti` already
     * refuses to draw more than 120 rows, so the DOM was safe — but a project with 400 campaigns
     * still shipped 400 complete metric rows over the wire to fill a dropdown, and that cost is paid
     * on every filter change by the reader with the largest estate, who is exactly the reader this
     * requirement is about.
     *
     * This returns an id and a name. It does NOT return figures: an option list that carried spend
     * would become a second source for it, and the two would eventually disagree with the breakdown
     * on the same screen.
     *
     * Deliberately NOT windowed. A campaign the reader wants to filter to may have reported nothing
     * in the current range — that is frequently WHY they are looking for it — and hiding it because
     * the window is narrow would make the filter unable to reach the campaign whose silence is the
     * question.
     */
    public function campaignOptions(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $projectId = app(ProjectContext::class)->projectId();
        abort_if($projectId === null, 400, 'A project is required to list campaign options.');

        $q = trim($request->string('q')->toString());

        /*
         * `ids` — resolving names for campaigns the reader has ALREADY chosen.
         *
         * The selection lives in the URL, so a shared link arrives carrying campaign ids and nothing
         * else. Search alone cannot answer for them: the page is the first 120 campaigns by name, and
         * a chosen campaign is very often not in it — so the control and the applied-filter chips
         * rendered the reader's own choice as a bare uuid, on exactly the deep link somebody sent a
         * colleague. That is a defect this endpoint created by moving the list to the server, and it
         * belongs here rather than in a second endpoint: the same scope, the same shape, the same
         * isolation.
         *
         * A resolution is not a search. `q` is ignored when ids are asked for, the cap does not apply
         * to a set the reader already holds, and `has_more` is false because there is no more.
         */
        $ids = array_values(array_filter(array_map(
            'trim',
            is_array($raw = $request->query('ids', [])) ? $raw : explode(',', (string) $raw),
        )));

        if ($ids !== []) {
            /*
             * Bounded anyway. The filter row cannot hold thousands of selections, and an unbounded
             * `whereIn` from a query string is a request somebody else can make expensive.
             */
            $ids = array_slice(array_unique($ids), 0, self::OPTION_LIMIT);

            /*
             * Non-uuid input is dropped rather than queried. These are uuid columns, so a hand-edited
             * link would otherwise come back as a 500 out of the driver — and a pasted-around link is
             * an ordinary thing to arrive malformed.
             */
            $ids = array_values(array_filter(
                $ids,
                static fn (string $id): bool => preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1,
            ));

            $named = $ids === [] ? collect() : UnifiedCampaign::query()
                ->where('project_id', $projectId)
                ->whereIn('id', $ids)
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name']);

            return ApiResponse::success([
                'options' => $named
                    ->map(static fn ($c): array => ['id' => (string) $c->id, 'name' => (string) $c->name])
                    ->values(),
                'has_more' => false,
                'limit' => self::OPTION_LIMIT,
            ], 'Campaign options.');
        }

        /*
         * The explicit `project_id` is legibility, NOT the isolation.
         *
         * `UnifiedCampaign` is project- and tenant-scoped by global scopes that read the request's
         * context, and that is what actually keeps one project's campaigns out of another's filter —
         * removing this line changes no behaviour, and its test still passes, which is how I know
         * which of the two is load-bearing. It stays because a reader of this query should not have
         * to know the model's scopes to see what it returns.
         */
        $rows = UnifiedCampaign::query()
            ->where('project_id', $projectId)
            ->when($q !== '', fn ($b) => $b->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%']))
            /*
             * Name, then id. The id tiebreak is not decoration: a project with many identically named
             * campaigns is made entirely of ties, and rows that swap between two identical reads tell
             * a reader something changed when nothing did — the same rule the breakdown follows.
             */
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::OPTION_LIMIT + 1)
            ->get(['id', 'name']);

        /*
         * One more than the cap is fetched so «there are more» is a FACT rather than an inference
         * from a full page. A list that silently stops tells a reader their campaign does not exist.
         */
        $more = $rows->count() > self::OPTION_LIMIT;

        return ApiResponse::success([
            'options' => $rows->take(self::OPTION_LIMIT)
                ->map(static fn ($c): array => ['id' => (string) $c->id, 'name' => (string) $c->name])
                ->values(),
            'has_more' => $more,
            'limit' => self::OPTION_LIMIT,
        ], 'Campaign options.');
    }

    public function campaigns(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        /*
         * CAMPAIGN-INTELLIGENCE-HUB — the same immediately-preceding window `summary()` compares
         * against, computed the same way, so the row's trend and the strip's deltas cannot disagree
         * about what «previous» means for one request.
         */
        $len = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($len - 1);

        return ApiResponse::success(
            $this->scoped($request)->byCampaign($from, $to, $prevFrom, $prevTo),
            'Metrics by campaign.',
            meta: $this->meta($from, $to),
        );
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
            /*
             * FUNNEL-WITHHELD-001 — the unit travels with the figure.
             *
             * `spend` here is what every `cost_per` on the chart divides, and it is not always in
             * the project's currency: when no rate exists it is the platform's own. A reader shown
             * «تكلفة 22.03» with no unit beside a project reporting in SAR reads riyals.
             */
            meta: $this->meta($from, $to) + [
                'spend' => $funnel['spend'],
                'spend_currency' => $funnel['spend_currency'],
                'spend_withheld' => $funnel['spend_withheld'],
                // Why spend/cost_per are what they are — «partial» / «mixed_currency» read as blanks otherwise.
                'spend_state' => $funnel['spend_state'],
            ],
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

    /**
     * PLATFORM-DECISION-ANALYTICS-001 — platforms inside each marketing path, never across them.
     *
     * `platforms` answers «how is each platform doing» with one row per platform over every
     * objective at once. That row cannot answer «which platform is contributing most to this
     * objective», and the comparison it invites — one number per platform across a mixed programme —
     * is the one that must never be made: a platform buying awareness and a platform buying sales
     * are not better or worse than each other, and ranking them together invents a verdict out of
     * the work each was given.
     */
    public function platformObjectives(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        $campaigns = array_values(array_filter((array) $request->input('campaign_ids', [])));

        return ApiResponse::success(
            (new ObjectivePerformance(
                campaignIds: $campaigns === [] ? null : $campaigns,
                providers: $this->providerFilter($request) === [] ? null : $this->providerFilter($request),
            ))->byPlatform($from, $to),
            'Platform contribution by objective.',
            meta: $this->meta($from, $to),
        );
    }

    /**
     * OBJECTIVE-ANALYTICS-DEPTH-001 — the strongest and weakest campaign inside each path.
     *
     * The same refusal as `platformObjectives`, one level down: a leads campaign and an awareness
     * campaign are not better or worse than each other, and a single «top campaigns» list across a
     * mixed programme ranks them by whichever metric they happen to share.
     */
    public function objectiveLeaders(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        $campaigns = array_values(array_filter((array) $request->input('campaign_ids', [])));

        return ApiResponse::success(
            (new ObjectivePerformance(
                campaignIds: $campaigns === [] ? null : $campaigns,
                providers: $this->providerFilter($request) === [] ? null : $this->providerFilter($request),
            ))->leadersByPath($from, $to),
            'Strongest and weakest campaign per objective path.',
            meta: $this->meta($from, $to),
        );
    }

    /**
     * FUNNEL-ANALYTICAL-PATTERN-001 — signal → context → explanation → evidence → action, per path.
     *
     * The funnel is the product's most-praised surface because it does not draw a chart and leave
     * the reader to interpret it. This gives the objective paths the same shape, and every step of
     * it can say nothing: a path nobody ran has no signal, a path one campaign ran has no
     * comparison, and where there is no signal there is no action — the reason travels instead.
     */
    public function objectiveExplanations(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        $campaigns = array_values(array_filter((array) $request->input('campaign_ids', [])));

        return ApiResponse::success(
            (new ObjectivePerformance(
                campaignIds: $campaigns === [] ? null : $campaigns,
                providers: $this->providerFilter($request) === [] ? null : $this->providerFilter($request),
            ))->explainByPath($from, $to),
            'Objective path explanations.',
            meta: $this->meta($from, $to),
        );
    }

    /**
     * OBJECTIVE-ANALYTICS-DEPTH-001 — each path's own trend, in the metric it was buying.
     *
     * Separate from `metrics/timeseries`, which is one line over a mixed programme: awareness rising
     * while sales falls is a flat line, and a reader watching it concludes the account is doing
     * nothing. Split by path, the same two weeks say «brand up, sales down».
     */
    public function objectiveTrend(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        $campaigns = array_values(array_filter((array) $request->input('campaign_ids', [])));

        return ApiResponse::success(
            (new ObjectivePerformance(
                campaignIds: $campaigns === [] ? null : $campaigns,
                providers: $this->providerFilter($request) === [] ? null : $this->providerFilter($request),
            ))->trendByPath($from, $to),
            'Objective path trend.',
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
     * FUNNEL-ANALYTICAL-PATTERN-001 — the pacing table, read back in the funnel's own shape.
     *
     * The table is the SIGNAL and nothing else: a column of percentages the reader has to interpret
     * every time — what 1.6 is measured against, why it happened, which figures say so, and what to
     * do about it. This returns the other four steps beside it, over the same rows, so the two
     * cannot disagree about which campaign is spending fastest.
     */
    public function budgetExplanation(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        return ApiResponse::success(
            (new BudgetExplanation)->explain(
                $this->scoped($request)->budgetPacing($from, $to, Carbon::today()),
                $from,
                $to,
            ),
            'Budget explanation.',
            meta: $this->meta($from, $to),
        );
    }

    /**
     * BUDGET-ACCOUNTS-001 — the same window, rolled up to the account that holds the payment method.
     *
     * Separate from `budget` rather than folded into it: that one answers «is this campaign pacing
     * to the plan we typed», this one answers «how close is this account to the ceiling the platform
     * will actually enforce». Different questions, different rows, and merging them would produce a
     * table where a column means one thing on some rows and something else on others.
     */
    public function budgetAccounts(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        [$from, $to] = $this->range($request);

        return ApiResponse::success(
            $this->scoped($request)->accountBudgets($from, $to),
            'Account budgets.',
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

        /*
         * The canonical predicate, not a second copy of one axis of it.
         *
         * This clause narrowed by provider and silently ignored the objective and campaign the same
         * request carried — so an operator who filtered to one campaign was told the currency and
         * timezone story of the entire project, under chips naming that campaign. Every row this
         * audit reads is a `daily_metrics` row, and every one of the three axes bounds it exactly.
         */
        $agg = $this->scoped($request);
        $scope = fn () => $agg->applyScope(
            DailyMetric::query()
                ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
        )->toBase();

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
            /* Every axis this endpoint was sent, and it narrows by all four. */
            'filter_scope' => $this->filterScope($request, ['provider', 'objective', 'campaign', 'outcome']),
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
            /*
             * Provider narrows this; objective and campaign do NOT, and the response says so.
             *
             * A source's health is a property of a connection — when Meta last answered, whether the
             * sweep failed, how many days of the window it covered. A campaign cannot make that
             * verdict truer or falser, and narrowing the day count by campaign while leaving the
             * verdict alone would produce rows reading «connected, fresh, 0 days with data», which
             * describes the filter rather than the source and reads as an outage.
             *
             * So the filter is declined here rather than half-applied, and the strip is told, so it
             * can say «across the project» instead of implying a narrowing that never happened.
             */
            'filter_scope' => $this->filterScope($request, ['provider']),
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
            $transparency->build($tenantId, $projectId, $from, $to, $this->providerFilter($request))
                /*
                 * This report compares what the platforms reported against what the store confirmed,
                 * and only ONE of those two sides has a campaign on it. Narrowing the platform side
                 * to a campaign while the store ledger stays whole would not answer a narrower
                 * question — it would invent a discrepancy out of the filter and present it as an
                 * attribution gap, which is the exact failure this endpoint exists to expose.
                 *
                 * So it declines the axis and says it declined, rather than ignoring it in silence
                 * under chips that promise otherwise.
                 */
                + ['filter_scope' => $this->filterScope($request, ['provider'])],
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
     * The window, provider, objective, campaign and attribution basis all come from the same request
     * helpers every other metric endpoint uses, so a drill-down cannot silently change basis as it
     * descends.
     *
     * That sentence used to be false. This method read the window, the parent and the attribution
     * basis and nothing else, so the ad-set and ad tables answered for the WHOLE project under chips
     * naming one campaign — directly beneath a campaign table that had narrowed correctly. A comment
     * describing an intention rather than the code is worse than no comment: it is the reason the
     * gap survived a reading.
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
            new EntityScope(
                providers: $this->providerFilter($request),
                objectives: $this->objectiveFilter($request),
                campaigns: $this->campaignFilter($request),
            ),
        );

        return ApiResponse::success([
            /*
             * Already named by the aggregator — REPORT-DETAIL-PARITY-001.
             *
             * The naming used to live here, which meant the client's shared report, calling the
             * aggregator directly, got rows with no name at all. Moving it down means both surfaces
             * are named by construction rather than by each caller remembering.
             */
            'entities' => $rows,
            'entity_type' => $level,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            // What the money on these rows is IN — the same statement every other surface makes.
            'currency' => $this->rangeCurrency($from, $to),
            'attribution_window' => $request->string('attribution_window')->toString() ?: null,
            /*
             * Every axis this endpoint is sent, and it narrows by the three it can.
             *
             * `outcome` is deliberately NOT listed: the entity grain is scoped through `EntityScope`,
             * which narrows in SQL on columns of `entity_daily_metrics`, and the action is resolved
             * from the provider payload rather than stored. Claiming it here would report an axis as
             * applied that this query does not apply — the exact dishonesty `filter_scope` exists to
             * prevent — so an ad-set drill-down says «outcome requested, not applied» instead.
             */
            'filter_scope' => $this->filterScope($request, ['provider', 'objective', 'campaign']),
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

        if ($raw === '') {
            return [];
        }

        $ids = array_values(array_filter(explode(',', $raw)));

        /*
         * These are uuid columns, and an unvalidated value goes straight into the WHERE clause: a
         * malformed `parent` came back as a 500 out of the driver rather than a refusal. A drill-down
         * is a linkable URL, so a truncated or hand-edited one is an ordinary event, not an attack —
         * and it must be told it is malformed rather than shown a stack trace or, worse, an empty list
         * that reads as «this campaign has no ad sets».
         */
        foreach ($ids as $id) {
            abort_unless(
                Str::isUuid($id),
                422,
                'A parent must be an entity id.',
            );
        }

        return $ids;
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
    /**
     * The actions filter (?outcome=native_lead_form,messaging) — CAMPAIGN-OUTCOME-DIMENSION-001.
     *
     * Values outside the enum are DROPPED rather than passed through: an unrecognised value would
     * match no campaign and fail the whole request closed, so a typo would empty the dashboard
     * instead of being ignored the way an unknown provider key is.
     *
     * @return list<string>
     */
    private function outcomeFilter(Request $request): array
    {
        return array_values(array_intersect($this->listFilter($request, 'outcome'), CampaignOutcome::values()));
    }

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

    /**
     * ANALYTICS-FILTER-TRUTH-001 — which axes this endpoint ACTUALLY narrowed by.
     *
     * The client sends `provider`, `objective` and `campaign` to every metrics endpoint. Three of
     * them read only the provider and dropped the rest on the floor, which is a worse failure than
     * frontend-only filtering rather than a milder one: the request looks filtered, the response is
     * shaped like a filtered response, and the panel sits under chips that name a campaign while
     * answering for the whole project. Nothing on the screen could tell the reader.
     *
     * So every axis the request asked for is accounted for here, and an axis this endpoint does not
     * apply is NAMED. A panel that cannot narrow is not a bug in every case — source health is a
     * property of a connection, not of a campaign, and the store side of an attribution
     * reconciliation has no campaign to narrow by at all, so narrowing only the platform side would
     * manufacture a discrepancy out of the filter. What is a bug is not saying so.
     *
     * @param  list<string>  $applies  the axes this endpoint genuinely narrows by
     * @return array{applied: list<string>, unapplied: list<string>}
     */
    private function filterScope(Request $request, array $applies): array
    {
        $requested = [];

        if ($this->providerFilter($request) !== []) {
            $requested[] = 'provider';
        }

        if ($this->objectiveFilter($request) !== []) {
            $requested[] = 'objective';
        }

        if ($this->campaignFilter($request) !== []) {
            $requested[] = 'campaign';
        }

        if ($this->outcomeFilter($request) !== []) {
            $requested[] = 'outcome';
        }

        return [
            'applied' => array_values(array_intersect($requested, $applies)),
            'unapplied' => array_values(array_diff($requested, $applies)),
        ];
    }

    /** The aggregator scoped by the dashboard's platform, objective and campaign filters. */
    private function scoped(Request $request): MetricsAggregator
    {
        $campaigns = $this->campaignFilter($request);

        $agg = $this->agg
            ->forProviders($this->providerFilter($request))
            ->forObjectives($this->objectiveFilter($request))
            /*
             * CAMPAIGN-OUTCOME-DIMENSION-001 — «what did it buy», beside «what was it for».
             *
             * `forOutcomes([])` is the no-filter case here, unlike `forCampaigns`: an operator ADDS
             * this axis to narrow, and an unset axis has never meant «nothing» on this side.
             */
            ->forOutcomes($this->outcomeFilter($request));

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
