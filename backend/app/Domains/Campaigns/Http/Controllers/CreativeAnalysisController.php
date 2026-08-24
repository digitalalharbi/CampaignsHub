<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Models\CreativeGroup;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativeFatigue;
use App\Domains\Campaigns\Services\CreativeFunnel;
use App\Domains\Campaigns\Services\CreativeInsights;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\CreativeMetricsAvailability;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Campaigns\Services\CreativePulse;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * §15 — the creative as a unit of analysis, over HTTP.
 *
 * ## One source, three surfaces
 *
 * The library, the detail view and the comparison all read `CreativeMetrics`, which reads
 * `creative_daily_metrics`. The dashboard's creative cards and the client report's creative section
 * read the same service. §15.17 makes that architectural: «أي صفحة مستقلة تستخدم مصدرًا مختلفًا عن
 * Unified Data Pipeline تعتبر خللًا معماريًا» — so there is no second aggregation anywhere, and a
 * figure that moves on one surface moves on all of them.
 *
 * ## Every list is bounded before it is read
 *
 * A project with a thousand creatives must not become a thousand metric queries. The list pages, the
 * metrics for the whole page are fetched in ONE grouped query, and the campaign names in another —
 * two queries regardless of page size, which is the N+1 rule §15.14 asks for stated as an invariant
 * rather than as an intention.
 */
final class CreativeAnalysisController extends Controller
{
    private const PER_PAGE_MAX = 60;

    /**
     * How many same-path creatives one detail page's findings are compared against.
     *
     * Bounded because the page must open in the same time for a project with 40 creatives and one
     * with 4,000, and a median stops moving long before the hundredth row. The response reports both
     * the number used and whether the cap bit, so «compared against 120 of them» never renders as
     * «compared against all of them».
     */
    private const INSIGHT_PEERS = 120;

    public function __construct(
        private readonly CreativeMetrics $metrics,
        private readonly CreativeFatigue $fatigue,
        private readonly CreativePresenter $presenter,
        private readonly CreativeRows $rows,
    ) {}

    /**
     * The library (§15.2): every creative the caller may reach, filtered, with objective-aware figures.
     *
     * `$project` is the PINNED entry — `/projects/{id}/creatives`, used by the report and campaign
     * surfaces that already know which project they are about. Called without one, from
     * `/creatives`, the library spans the caller's whole reach so that the Client and Project
     * filters §15.2 asks for have something to filter; the ceiling is then the membership's, not the
     * URL's, which is the only version of this that cannot be widened by editing an address.
     */
    public function index(Request $request, ?string $project = null): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        [$from, $to] = $this->window($request);

        $query = ExternalCreative::query();

        if ($project !== null) {
            $query->where('project_id', $project);
        }

        $this->applyReach($query, $request);
        $this->applyFilters($query, $request);

        $perPage = min((int) $request->integer('per_page', 24) ?: 24, self::PER_PAGE_MAX);
        $page = max((int) $request->integer('page', 1), 1);
        $total = (clone $query)->count();

        $creatives = $this->applySort($query, $request, $from, $to)
            ->forPage($page, $perPage)
            ->get();

        $rows = $this->present($creatives, $from, $to, withFatigue: true);

        // A status filter that depends on the ASSESSMENT can only be applied once the assessment
        // exists, so it runs here rather than in SQL — and the total is corrected so the pager never
        // promises pages that were filtered away.
        $health = $request->string('health')->toString();
        if ($health !== '') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => ($r['fatigue']['status'] ?? null) === $health));
        }

        return ApiResponse::success([
            'creatives' => $rows,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $health === '' ? $total : count($rows),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            /*
             * CREATIVE-MONEY-TRUTH-001 — what the money on these cards is IN.
             *
             * `formatMetric` defaulted its currency to «SAR», and this page's spend cell omitted the
             * argument, so every card printed a Saudi label over whatever the account actually spent.
             * Stating it here is what lets the card render the truth instead of a default.
             */
            'currency' => $this->reachCurrency($creatives),
            /*
             * CONTENT-STATE-SEMANTICS-001 — why an empty card is empty, per provider.
             *
             * Without this the page can only say «لا توجد بيانات», which is simultaneously the
             * message for a creative that did not run, a provider that has no creative-level
             * reporting at all, and a fetch that failed. Read from the sync run rather than
             * inferred from an absent value: an empty metrics object looks identical in all three.
             */
            'metrics_availability' => app(CreativeMetricsAvailability::class)->forCreatives($creatives),
            'filters' => $this->filterOptions($request, $project),
        ], 'Creative library.');
    }

    /**
     * The dashboard's creative section (§15.11) — the same creatives the library lists, read as an answer.
     *
     * It takes the SAME query as `index()`: the same reach, the same filters, the same window. That
     * is the whole design. A dashboard section built on its own query is a section that can disagree
     * with the page it links into — the operator clicks «best video», lands on the library, and finds
     * a different video at the top. Here, changing a filter changes both because there is one query
     * and one aggregation behind them.
     *
     * Query count does not grow with the number of creatives: the rows are fetched once, this window
     * and the previous one are two grouped queries, and the campaigns are a third. A project with a
     * thousand creatives costs the same four queries as one with ten (§15.14).
     */
    public function pulse(Request $request, CreativePulse $pulse, CreativeInsights $insights, ?string $project = null): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        [$from, $to] = $this->window($request);

        $query = ExternalCreative::query();

        if ($project !== null) {
            $query->where('project_id', $project);
        }

        $this->applyReach($query, $request);
        $this->applyFilters($query, $request);

        $creatives = $query->get();
        $rows = $this->present($creatives, $from, $to, withFatigue: true, withPrevious: true);

        return ApiResponse::success(
            $pulse->build($rows, $from, $to) + [
                /*
                 * CREATIVE-MONEY-TRUTH-001 — what these figures are IN, so nothing has to assume.
                 *
                 * This section hard-coded «SAR» in its formatter. On production, where the account
                 * spends USD and no USD→SAR rate exists, that printed a USD number under a Saudi
                 * label — a wrong figure wearing the right one's clothes, which is worse than the
                 * withheld zero this product already fixed once.
                 */
                'currency' => $this->reachCurrency($creatives),
                /*
                 * The same options the library's filter bar is built from.
                 *
                 * The section carries its own controls on a dashboard that has none of its own — and
                 * a control populated from an enum would offer platforms this account has never run,
                 * which is a filter that returns nothing and reads as «no data». Derived from the
                 * rows in reach, exactly as the library's are.
                 */
                'filters' => $this->filterOptions($request, $project),
                /*
                 * §15.10 — the same rows, read as findings rather than as rankings.
                 *
                 * Built from the array the section is already holding, so an insight can never cite
                 * a figure the cards beside it disagree with. Empty is a legitimate answer: an
                 * account where nothing moved materially has nothing to be told.
                 */
                'insights' => $insights->build($rows, $from, $to),
            ],
            'Creative pulse.',
        );
    }

    /**
     * One creative, in depth, pinned to a project — `/projects/{project}/creatives/{creative}`.
     */
    public function show(
        Request $request,
        CreativeInsights $insights,
        CreativeFunnel $funnel,
        string $project,
        string $creative,
    ): JsonResponse {
        return $this->creativeDetail($request, $insights, $funnel, $creative, $project);
    }

    /**
     * The same creative, addressed across the caller's whole reach — `/creatives/{creative}`.
     *
     * This is what the Creative Details PAGE opens (§15.6). The library spans projects and a card
     * does not carry a project id, so a page that needed one could only be reached by first asking
     * which project a creative belongs to — and an address the reader cannot construct is not a deep
     * link. The ceiling here is the MEMBERSHIP's, which is the only version of this that cannot be
     * widened by editing the URL.
     */
    public function detail(
        Request $request,
        CreativeInsights $insights,
        CreativeFunnel $funnel,
        string $creative,
    ): JsonResponse {
        return $this->creativeDetail($request, $insights, $funnel, $creative, null);
    }

    /**
     * One creative, in depth: its asset, its figures, its funnel, its trend, its findings, and how it
     * did per platform and per campaign (§15.6).
     *
     * ## Reach is applied to the LOOKUP, not checked after it
     *
     * The creative is fetched through the same bounded query the library uses, so a creative outside
     * the caller's clients is not found rather than found-and-refused. That is why the answer is 404:
     * a 403 would confirm the id exists and is merely someone else's, which is the fact the ceiling
     * is there to withhold. It also means there is no second check to forget — cross-tenant,
     * cross-client and cross-project all fail at the same line, for the same reason.
     */
    private function creativeDetail(
        Request $request,
        CreativeInsights $insights,
        CreativeFunnel $funnel,
        string $creative,
        ?string $project,
    ): JsonResponse {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        $query = ExternalCreative::query()->whereKey($creative);

        if ($project !== null) {
            $query->where('project_id', $project);
        }

        $this->applyReach($query, $request);

        $model = $query->first();
        abort_if($model === null, 404, 'Creative not found.');

        [$from, $to] = $this->window($request);

        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        $id = (string) $model->getKey();
        $current = $this->metrics->forCreatives([$id], $from, $to)[$id] ?? null;
        $previous = $this->metrics->forCreatives([$id], $prevFrom, $prevTo)[$id] ?? null;

        $campaign = $model->campaign_id === null ? null : UnifiedCampaign::query()->find($model->campaign_id);
        $objective = $campaign?->objective;

        /*
         * The siblings a creative is judged against.
         *
         * Only creatives on the SAME marketing path, because «this one is below the project average»
         * is a useful sentence only when the average is of content doing the same job. Averaging an
         * awareness video's CPM into a sales benchmark produces a comparison nobody should act on.
         */
        $peers = $this->peerAverages((string) $model->project_id, $objective, $from, $to, exclude: $id);

        $trend = $this->trend($id, $from, $to);
        $findings = $this->findingsFor($request, $insights, $model, $objective, $from, $to);

        return ApiResponse::success([
            'creative' => $this->presenter->detail($model, $campaign),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'previous_period' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'metrics' => $current,
            'previous' => $previous,
            // The detail page is looking at ONE creative's window, so `$current` is exactly the
            // availability this creative has (CONTENT-KPI-AVAILABILITY-001).
            'headline_metrics' => $this->metrics->headline($objective, $current),
            'path' => $this->metrics->pathFor($objective)->value,
            'fatigue' => $this->fatigue->assess($current ?? ['active_days' => 0], $previous),
            // The funnel is a reshaping of `metrics` above — same figures, no second query, and only
            // the steps this platform actually reported (§15.6).
            'funnel' => $funnel->build($current),
            'trend' => $trend,
            // Rolled up from the daily rows already in hand, so the two charts cannot disagree.
            'weekly' => $this->weekly($trend),
            'by_platform' => $this->byPlatform($model, $from, $to),
            'by_campaign' => $this->byCampaign($model, $from, $to),
            'peers' => $peers,
            'group' => $this->groupShape($model),
            'insights' => $findings,
            /*
             * REPORT-OBJECTIVE-005 and §15.15 — what these figures ARE, beside them.
             *
             * A creative's numbers are what the ad platform reported about its own delivery, inside
             * the platform's own attribution window. Fixed rather than computed: a field that
             * sometimes said «store confirmed» because a join happened to succeed would be worse than
             * no field at all.
             */
            'attribution' => [
                'source' => 'platform_reported',
                'note_ar' => 'الأرقام كما أبلغت عنها المنصة الإعلانية نفسها، ضمن نافذة العزو المعتمدة لديها.',
                'note_en' => 'Figures as the ad platform reported them, inside its own attribution window.',
            ],
        ] + $this->projectContext($model), 'Creative analysis.');
    }

    /**
     * Two or more creatives, side by side (§15.7).
     *
     * The response deliberately carries no overall «winner». When the selection spans marketing
     * paths, `comparable` is false and the reason travels with it; the per-metric winners are
     * reported either way, because «best CTR» is a real answer even between an awareness video and a
     * sales image, while «better creative» is not.
     */
    public function compare(Request $request, ?string $project = null): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        $data = $request->validate([
            'creative_ids' => ['required', 'array', 'min:2', 'max:6'],
            'creative_ids.*' => ['string'],
        ]);

        [$from, $to] = $this->window($request);

        $query = ExternalCreative::query()->whereIn('id', $data['creative_ids']);

        if ($project !== null) {
            $query->where('project_id', $project);
        }

        // The ceiling applies to the ids the CALLER supplied, which is the whole point: a comparison
        // is the one place a list of ids arrives straight from the browser, so an id outside the
        // caller's clients has to be dropped here rather than trusted because it was asked for.
        $this->applyReach($query, $request);

        $creatives = $query->get();

        abort_if($creatives->count() < 2, 422, 'At least two reachable creatives are needed to compare.');

        $rows = $this->present($creatives, $from, $to, withFatigue: false);

        $objectives = array_values(array_unique(array_map(
            static fn (array $r): ?string => $r['objective'],
            $rows,
        )));

        $verdict = count($objectives) <= 1
            ? ['comparable' => true, 'reason' => null, 'reason_ar' => null]
            : $this->metrics->comparable($objectives[0], $objectives[1]);

        return ApiResponse::success([
            'creatives' => $rows,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'comparable' => $verdict['comparable'],
            'reason' => $verdict['reason'],
            'reason_ar' => $verdict['reason_ar'],
            'winners' => $this->winners($rows),
            /*
             * CREATIVE-MONEY-TRUTH-001 — and here it carries a second meaning.
             *
             * A comparison is the one place a caller supplies the ids, so two creatives from
             * projects reporting in DIFFERENT currencies can end up side by side. `reachCurrency`
             * returns null then, and the table refuses each money figure rather than presenting
             * two incomparable amounts as though one had beaten the other.
             */
            'currency' => $this->reachCurrency($creatives),
        ], 'Creative comparison.');
    }

    /**
     * Group creatives as one asset across platforms, or split a group apart (§15.8).
     *
     * A group created here is `manual` or `confirmed` — a person's judgement. Automatic grouping by
     * file hash happens in the sync, and a person confirming one is what turns it into `confirmed`.
     */
    public function group(Request $request, AuditLogger $audit, string $project): JsonResponse
    {
        $creatives = $this->mergeCandidates($request, ExternalCreative::query()->where('project_id', $project));

        return $this->mergeCreatives($request, $audit, $creatives, $project);
    }

    /**
     * The same merge, addressed across the caller's whole reach — `POST /creatives/group` (§15.13).
     *
     * The library spans projects and a card carries no project id, so the page that lists creatives
     * cannot construct the pinned address above. The project is DERIVED from the creatives instead,
     * and a selection spanning two of them is refused: a group is the same asset on more than one
     * platform, and two projects are two clients' books. Merging across them would put one client's
     * spend inside another's roll-up, which no later split undoes in a report already sent.
     */
    public function merge(Request $request, AuditLogger $audit): JsonResponse
    {
        $creatives = $this->mergeCandidates($request, ExternalCreative::query());

        $projects = $creatives->pluck('project_id')->map(static fn ($id): string => (string) $id)->unique();
        abort_if($projects->count() > 1, 422, 'Creatives from two different projects cannot be one asset.');

        return $this->mergeCreatives($request, $audit, $creatives, (string) $projects->first());
    }

    /** Split a creative out of its group — the undo §15.8 requires for a wrong automatic match. */
    public function ungroup(Request $request, AuditLogger $audit, string $project, string $creative): JsonResponse
    {
        return $this->splitCreative(
            $request,
            $audit,
            ExternalCreative::query()->where('project_id', $project),
            $creative,
        );
    }

    /** The same split, across the caller's reach — `DELETE /creatives/{creative}/group` (§15.13). */
    public function split(Request $request, AuditLogger $audit, string $creative): JsonResponse
    {
        return $this->splitCreative($request, $audit, ExternalCreative::query(), $creative);
    }

    /**
     * Every group the caller may reach, with the roll-up each one actually supports (§15.13).
     *
     * The members are read through `CreativeRows` — the library's own selection — so a group's figures
     * are the sum of the rows the library shows, and cannot drift from them. `CreativeMetrics::aggregate`
     * does the summing, which is why an average like frequency is impression-weighted here rather than
     * meaned into a number that describes nobody.
     */
    public function groups(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        [$from, $to] = $this->window($request);

        $bounded = ExternalCreative::query()->whereNotNull('creative_group_id');
        $this->applyReach($bounded, $request);

        $groupIds = (clone $bounded)->distinct()->pluck('creative_group_id')
            ->filter()->map(static fn ($id): string => (string) $id)->values();

        $total = $groupIds->count();
        $perPage = min((int) $request->integer('per_page', 24) ?: 24, self::PER_PAGE_MAX);
        $page = max((int) $request->integer('page', 1), 1);

        $groups = CreativeGroup::query()->whereIn('id', $groupIds->all())
            ->orderBy('name')->orderBy('id')
            ->forPage($page, $perPage)->get();

        // One members query and one presentation for the whole page — a per-group lookup would make
        // this page's cost grow with the number of groups on it (§15.14).
        $members = (clone $bounded)->whereIn('creative_group_id', $groups->modelKeys())->get();
        $rows = $this->present($members, $from, $to, withFatigue: true, withPrevious: true);
        $byGroup = $this->rowsByGroup($members, $rows);

        return ApiResponse::success([
            'groups' => $groups->map(fn (CreativeGroup $g): array => $this->groupSummary($g, $byGroup[(string) $g->getKey()] ?? []))->all(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            // The same statement the library makes — a group's money is no more self-describing.
            'currency' => $this->reachCurrency($members),
        ], 'Creative groups.');
    }

    /**
     * One group: its roll-up, its members, its per-platform split, its provenance and its audit trail.
     *
     * Reach is applied to the MEMBER query and the group is derived from what survived, so a group
     * belonging to another client is not found rather than found-and-refused — 404 for the same reason
     * a creative outside the ceiling gives 404.
     */
    public function groupShow(Request $request, string $group): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        [$from, $to] = $this->window($request);

        $bounded = ExternalCreative::query()->where('creative_group_id', $group);
        $this->applyReach($bounded, $request);
        $members = $bounded->get();

        abort_if($members->isEmpty(), 404, 'Creative group not found.');

        $model = CreativeGroup::query()->find($group);
        abort_if($model === null, 404, 'Creative group not found.');

        $rows = $this->present($members, $from, $to, withFatigue: true, withPrevious: true);

        return ApiResponse::success(
            $this->groupSummary($model, $rows) + [
                'members' => $rows,
                'by_platform' => $this->groupByPlatform($rows),
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                // A group spans platforms and can span projects — see `reachCurrency`.
                'currency' => $this->reachCurrency($members),
                'audit' => $this->groupAudit($model),
            ],
            'Creative group.',
        );
    }

    // ---- internals ----------------------------------------------------------------------------

    /*
     * The six methods below are one-line delegations to {@see CreativeRows}, which is where the
     * query, the ordering, the two-query metric fetch and the presentation actually live.
     *
     * They moved out of this controller when the client's report needed the SAME selection with a
     * different ceiling — the share's, not the signed-in user's. Copying them would have created a
     * second definition of «this project's creatives in this window», and the first time the two
     * drifted, the operator's dashboard and the client's report would both have looked correct while
     * disagreeing about the same creative. The wrappers stay so the call sites above read as they
     * did; they hold no logic.
     */

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(Request $request): array
    {
        return $this->rows->window(
            $request->filled('from') ? $request->string('from')->toString() : null,
            $request->filled('to') ? $request->string('to')->toString() : null,
        );
    }

    private function applyReach(mixed $query, Request $request): void
    {
        $this->rows->applyReach($query, $request->user());
    }

    private function applyFilters(mixed $query, Request $request): void
    {
        /*
         * Only the axes an OPERATOR may narrow by.
         *
         * `CreativeRows` also understands `creative_ids`, `creative_group_ids` and
         * `excluded_creative_ids`, because a shared link is built out of exactly those. They are not
         * forwarded from the query string here: on this surface they would be a filter nothing in
         * the UI sets, and enumerating what a request may contain is cheaper to keep right than
         * remembering what it may not.
         */
        $this->rows->applyFilters($query, $request->only([
            'provider', 'providers', 'format', 'formats', 'status', 'statuses',
            'campaign_ids', 'ad_set_ids', 'ad_ids', 'project_ids', 'client_ids',
            'search', 'kinds', 'objectives', 'paths',
        ]));
    }

    private function applySort(mixed $query, Request $request, Carbon $from, Carbon $to): mixed
    {
        return $this->rows->applySort($query, $request->string('sort')->toString(), $from, $to);
    }

    /**
     * @param  Collection<int, ExternalCreative>  $creatives
     * @return list<array<string, mixed>>
     */
    private function present(mixed $creatives, Carbon $from, Carbon $to, bool $withFatigue, bool $withPrevious = false): array
    {
        return $this->rows->present($creatives, $from, $to, $withFatigue, $withPrevious);
    }

    /** Daily figures for the trend chart — one query, ordered, no gaps invented. */
    private function trend(string $creativeId, Carbon $from, Carbon $to): array
    {
        return DB::table('creative_daily_metrics')
            ->where('creative_id', $creativeId)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('metric_date')
            ->get([
                'metric_date', 'spend', 'impressions', 'clicks', 'conversions', 'revenue',
                'video_views', 'video_p100', 'frequency',
            ])
            ->map(static fn ($r): array => [
                'date' => (string) $r->metric_date,
                'spend' => (float) $r->spend,
                'impressions' => (float) $r->impressions,
                'clicks' => (float) $r->clicks,
                'conversions' => (float) $r->conversions,
                'revenue' => (float) $r->revenue,
                // Nulls survive the mapping: a day the platform reported no video data is not a day
                // of zero views.
                'video_views' => $r->video_views === null ? null : (float) $r->video_views,
                'video_p100' => $r->video_p100 === null ? null : (float) $r->video_p100,
                'frequency' => $r->frequency === null ? null : (float) $r->frequency,
            ])->all();
    }

    /**
     * The same asset's figures per platform — meaningful only for a grouped creative.
     *
     * A lone creative belongs to one platform by definition, so this returns its own row and the UI
     * shows no comparison rather than a chart of one bar.
     */
    private function byPlatform(ExternalCreative $creative, Carbon $from, Carbon $to): array
    {
        $siblings = $creative->creative_group_id === null
            ? ExternalCreative::query()->whereKey($creative->getKey())->get()
            : ExternalCreative::query()->where('creative_group_id', $creative->creative_group_id)->get();

        $figures = $this->metrics->forCreatives(array_map('strval', $siblings->modelKeys()), $from, $to);

        return $siblings->map(fn (ExternalCreative $c): array => [
            'creative_id' => (string) $c->getKey(),
            'provider' => $c->provider,
            'metrics' => $figures[(string) $c->getKey()] ?? null,
            // Platform-reported, always — a creative's figures come from the ad platform, and only a
            // store-confirmed order can claim otherwise (REPORT-OBJECTIVE-005).
            'source' => 'platform_reported',
        ])->all();
    }

    /** How this creative did in each campaign that ran it. */
    private function byCampaign(ExternalCreative $creative, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('creative_daily_metrics')
            ->where('creative_id', $creative->getKey())
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('campaign_id')
            ->select('campaign_id')
            ->selectRaw('SUM(spend) spend, SUM(impressions) impressions, SUM(clicks) clicks, SUM(conversions) conversions, SUM(revenue) revenue')
            ->get();

        $names = UnifiedCampaign::query()
            ->whereIn('id', $rows->pluck('campaign_id')->filter()->all())
            ->pluck('name', 'id');

        return $rows->map(static fn ($r): array => [
            'campaign_id' => $r->campaign_id === null ? null : (string) $r->campaign_id,
            'campaign_name' => $r->campaign_id === null ? null : ($names[$r->campaign_id] ?? null),
            'spend' => (float) $r->spend,
            'impressions' => (float) $r->impressions,
            'clicks' => (float) $r->clicks,
            'conversions' => (float) $r->conversions,
            'revenue' => (float) $r->revenue,
        ])->all();
    }

    /**
     * §15.10's findings, for ONE creative — the same engine the dashboard section runs.
     *
     * ## Why it is handed peers rather than one row
     *
     * Ten of the fifteen rules compare a creative to ITSELF in the previous window and would fire on
     * a single row. The other five — «scaling opportunity», «strong hook, weak completion», and the
     * rest — compare it to the median of content doing the same job, and on a one-row set that median
     * IS the creative, so every one of them would silently never fire. The detail page would then be
     * quietly missing a third of the analysis while looking complete.
     *
     * So the peer set is fetched and the whole set is assessed, exactly as `pulse()` does, and the
     * items for this creative are kept. Nothing is recomputed: same service, same thresholds, same
     * medians — an insight here says what the same insight says on the dashboard.
     *
     * The peer set is capped, and the response SAYS it was capped and against how many. A comparison
     * silently taken against the top 120 spenders reads as a comparison against everything.
     *
     * @return array<string, mixed>
     */
    private function findingsFor(
        Request $request,
        CreativeInsights $insights,
        ExternalCreative $model,
        ?string $objective,
        Carbon $from,
        Carbon $to,
    ): array {
        $path = $this->metrics->pathFor($objective)->value;

        $peers = ExternalCreative::query()->where('project_id', $model->project_id);
        $this->applyReach($peers, $request);
        $this->rows->applyFilters($peers, ['paths' => [$path]]);

        // Highest spend first, so the cap keeps the creatives a median should actually be taken
        // against — and so the same request twice returns the same set.
        $set = $this->rows->applySort($peers, 'spend', $from, $to)->limit(self::INSIGHT_PEERS)->get();

        /*
         * The subject itself, if the cap or the path filter left it out.
         *
         * A creative whose campaign carries no objective is not matched by a path filter at all, and
         * one below the top of the spend list falls off the end — either way the page would show no
         * findings for the creative it is about, which reads as «nothing to report» rather than as
         * «it was not looked at».
         */
        if (! $set->contains(static fn (ExternalCreative $c): bool => $c->is($model))) {
            $set->push($model);
        }

        $rows = $this->present($set, $from, $to, withFatigue: true, withPrevious: true);
        $built = $insights->build($rows, $from, $to);

        $id = (string) $model->getKey();
        $mine = array_values(array_filter(
            $built['items'] ?? [],
            static fn (array $item): bool => ($item['creative_id'] ?? null) === $id,
        ));

        return [
            'items' => $mine,
            'total' => count($mine),
            'evidence' => $built['evidence'] ?? null,
            'period' => $built['period'] ?? null,
            'previous_period' => $built['previous_period'] ?? null,
            'compared_against' => [
                'path' => $path,
                'creatives' => count($rows),
                // «هذه المقارنة ضد أعلى 120 إنفاقًا» is a different claim from «ضد كل المحتويات»,
                // and a reader cannot tell which they are looking at unless it is stated.
                'capped' => $set->count() >= self::INSIGHT_PEERS,
                'cap' => self::INSIGHT_PEERS,
            ],
        ];
    }

    /**
     * The daily rows already in hand, rolled up by week — no second query and no second aggregation.
     *
     * Weeks run from the FIRST day of the window rather than from a calendar Monday, so the first
     * bucket is never a two-day stub that reads as a collapse in delivery. A key nobody reported on
     * any day of a week stays null: summing nulls into 0 here would undo the care `CreativeMetrics`
     * took to keep «not provided» apart from «none».
     *
     * @param  list<array<string, mixed>>  $trend
     * @return list<array<string, mixed>>
     */
    private function weekly(array $trend): array
    {
        if ($trend === []) {
            return [];
        }

        $keys = ['spend', 'impressions', 'clicks', 'conversions', 'revenue', 'video_views', 'video_p100'];
        $start = Carbon::parse((string) $trend[0]['date']);
        $buckets = [];

        foreach ($trend as $day) {
            $date = Carbon::parse((string) $day['date']);
            $index = intdiv((int) $start->diffInDays($date), 7);

            if (! isset($buckets[$index])) {
                $buckets[$index] = [
                    'week' => $index + 1,
                    'from' => $date->toDateString(),
                    'to' => $date->toDateString(),
                    'days' => 0,
                ] + array_fill_keys($keys, null);
            }

            $buckets[$index]['to'] = $date->toDateString();
            $buckets[$index]['days']++;

            foreach ($keys as $key) {
                if (! is_numeric($day[$key] ?? null)) {
                    continue;
                }

                $buckets[$index][$key] = ($buckets[$index][$key] ?? 0.0) + (float) $day[$key];
            }
        }

        ksort($buckets);

        return array_values($buckets);
    }

    /**
     * The currency and timezone these figures were normalised INTO, and whether they are demo.
     *
     * Read from `daily_metrics`, which is where the pipeline records what it converted to — not from
     * a config default, which would confidently print «SAR» for a project reporting in AED. Null when
     * the project has no daily rows yet, and the page says «not provided» rather than guessing.
     *
     * @return array<string, mixed>
     */
    /**
     * The one currency these creatives are reported in, or null when there is no single answer.
     *
     * Read from what the pipeline RECORDED converting to, never from a config default — a default
     * would confidently print «SAR» for a project reporting in AED. The agency view spans projects,
     * and two projects reporting in different currencies have no shared currency to name: null then,
     * and the reader says «conversion unavailable» rather than picking one.
     *
     * @param  Collection<int, ExternalCreative>  $creatives
     */
    private function reachCurrency(mixed $creatives): ?string
    {
        $projectIds = $creatives->pluck('project_id')->filter()->unique()->values()->all();

        if ($projectIds === []) {
            return null;
        }

        $currencies = DB::table('daily_metrics')
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('project_currency')
            ->distinct()
            ->pluck('project_currency')
            ->all();

        return count($currencies) === 1 ? (string) $currencies[0] : null;
    }

    private function projectContext(ExternalCreative $model): array
    {
        $row = DB::table('daily_metrics')
            ->where('project_id', $model->project_id)
            ->whereNotNull('project_currency')
            ->orderByDesc('metric_date')
            ->first(['project_currency', 'project_timezone']);

        return [
            'currency' => $row?->project_currency === null ? null : (string) $row->project_currency,
            'timezone' => $row?->project_timezone === null ? null : (string) $row->project_timezone,
            'project_id' => (string) $model->project_id,
        ];
    }

    /**
     * The average of creatives doing the SAME job, so «below average» means something.
     *
     * @return array<string, float|null>|null
     */
    private function peerAverages(string $project, ?string $objective, Carbon $from, Carbon $to, string $exclude): ?array
    {
        $path = $this->metrics->pathFor($objective);

        /*
         * On this creative's path, and only on it.
         *
         * This used to select every creative in the project whose campaign existed — the objective
         * was never consulted, despite the paragraph above saying it was. So an awareness video's CPM
         * was benchmarked against an average that included sales images, and the page said «above
         * average» about a comparison nobody should act on. The subquery now carries the objectives
         * the path contains, which is the same rule `CreativeRows` applies to the `paths` filter.
         */
        $objectives = array_values(array_filter(
            array_map(static fn (CampaignObjective $case): string => $case->value, CampaignObjective::cases()),
            fn (string $value): bool => $this->metrics->pathFor($value)->value === $path->value,
        ));

        $peerIds = ExternalCreative::query()
            ->where('project_id', $project)
            ->whereKeyNot($exclude)
            ->whereIn('campaign_id', function ($sub) use ($objectives): void {
                $sub->select('id')->from('unified_campaigns')->whereIn('objective', $objectives);
            })
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();

        if ($peerIds === []) {
            return null;
        }

        $figures = $this->metrics->forCreatives($peerIds, $from, $to);
        if ($figures === []) {
            return null;
        }

        $average = static function (string $key) use ($figures): ?float {
            $values = array_values(array_filter(
                array_map(static fn (array $f) => $f[$key] ?? null, $figures),
                static fn ($v): bool => is_numeric($v),
            ));

            return $values === [] ? null : round(array_sum($values) / count($values), 4);
        };

        $out = ['count' => count($figures), 'path' => $path->value];
        foreach (['ctr', 'cpc', 'cpm', 'cpa', 'roas', 'view_rate', 'completion_rate'] as $key) {
            $out[$key] = $average($key);
        }

        return $out;
    }

    /**
     * Per-metric winners — never an overall one (§15.7).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{creative_id: string, value: float}>
     */
    private function winners(array $rows): array
    {
        // metric => higher is better
        $axes = [
            'impressions' => true, 'reach' => true, 'ctr' => true, 'cpc' => false, 'cpm' => false,
            'cpa' => false, 'roas' => true, 'conversion_rate' => true,
            'view_rate' => true, 'completion_rate' => true, 'video_avg_watch_seconds' => true,
        ];

        $out = [];
        foreach ($axes as $metric => $higherWins) {
            $best = null;
            foreach ($rows as $row) {
                $value = $row['metrics'][$metric] ?? null;
                if (! is_numeric($value)) {
                    continue;
                }
                if ($best === null || ($higherWins ? $value > $best['value'] : $value < $best['value'])) {
                    $best = ['creative_id' => $row['id'], 'value' => (float) $value];
                }
            }
            if ($best !== null) {
                $out[$metric] = $best;
            }
        }

        return $out;
    }

    /**
     * The creatives a merge may actually touch, under the caller's ceiling.
     *
     * `campaigns.link` — the permission that already means «say two platform records are one thing».
     * Grouping creatives across platforms is the same judgement one level down, and inventing a
     * `campaigns.manage` key nobody grants would have made this unreachable for every existing role.
     *
     * The reach is applied to the SELECTION, not checked after it, so an id the caller may not see is
     * dropped on the way in rather than merged because it was asked for. A list of ids arriving
     * straight from a browser is exactly the shape that gets trusted by accident.
     *
     * @return Collection<int, ExternalCreative>
     */
    private function mergeCandidates(Request $request, mixed $scope): Collection
    {
        abort_unless($request->user()?->hasPermission('campaigns.link'), 403);

        $data = $request->validate([
            'creative_ids' => ['required', 'array', 'min:2'],
            'creative_ids.*' => ['string'],
            'name' => ['nullable', 'string', 'max:200'],
        ]);

        $this->applyReach($scope, $request);

        $creatives = $scope->whereIn('id', $data['creative_ids'])->get();

        abort_if($creatives->count() < 2, 422, 'At least two creatives you can reach are needed to group.');

        return $creatives;
    }

    /**
     * Put a selection into one group — `manual`, because a person said so.
     *
     * Automatic grouping by file hash happens in the sync; a person confirming one is what turns it
     * into `confirmed`. A creative that was already in another group MOVES, and any group left holding
     * fewer than two members is dissolved — the same rule the split applies, because a group of one is
     * a badge that promises company nobody is keeping.
     *
     * @param  Collection<int, ExternalCreative>  $creatives
     */
    private function mergeCreatives(Request $request, AuditLogger $audit, Collection $creatives, string $project): JsonResponse
    {
        $vacated = $creatives->pluck('creative_group_id')->filter()
            ->map(static fn ($id): string => (string) $id)->unique()->values();

        $name = $request->string('name')->toString();

        /*
         * The DISPLAYED name, not the raw one.
         *
         * `CreativePresenter::card()` shows `client_display_name ?: name`, so falling back to `name`
         * here produced a group called «Creative 0 — video» sitting directly above two members both
         * labelled «Hero Video» — one asset apparently named two different things on the same screen.
         */
        $first = $creatives->first();

        $group = CreativeGroup::create([
            'project_id' => $project,
            'name' => $name !== '' ? $name : ($first->client_display_name ?: $first->name),
            'method' => 'manual',
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => Carbon::now(),
        ]);

        ExternalCreative::query()->whereIn('id', $creatives->modelKeys())
            ->update(['creative_group_id' => $group->getKey()]);

        $dissolved = [];
        foreach ($vacated as $old) {
            if ($this->dissolveIfAlone($old)) {
                $dissolved[] = $old;
            }
        }

        $audit->log(
            action: 'creative.group.created',
            entityType: CreativeGroup::class,
            entityId: (string) $group->getKey(),
            after: [
                'creatives' => $creatives->modelKeys(),
                'method' => 'manual',
                'groups_dissolved' => $dissolved,
            ],
        );

        return ApiResponse::success([
            'id' => (string) $group->getKey(),
            'name' => $group->name,
            'method' => $group->method,
            'creative_ids' => array_map('strval', $creatives->modelKeys()),
        ], 'Creatives grouped.', status: 201);
    }

    private function splitCreative(Request $request, AuditLogger $audit, mixed $scope, string $creative): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.link'), 403);

        $this->applyReach($scope, $request);

        $model = $scope->whereKey($creative)->first();
        abort_if($model === null, 404, 'Creative not found.');

        $groupId = $model->creative_group_id === null ? null : (string) $model->creative_group_id;

        abort_if($groupId === null, 422, 'This creative is not grouped.');

        $model->forceFill(['creative_group_id' => null])->save();

        $dissolved = $this->dissolveIfAlone($groupId);

        $audit->log(
            action: 'creative.group.split',
            entityType: CreativeGroup::class,
            entityId: $groupId,
            after: ['creative_id' => (string) $model->getKey(), 'group_dissolved' => $dissolved],
        );

        return ApiResponse::success([
            'creative_id' => (string) $model->getKey(),
            'group_dissolved' => $dissolved,
        ], 'Creative split from its group.');
    }

    /**
     * A group of one is not a group.
     *
     * Counted WITHOUT the caller's reach on purpose: whether a group still has company is a fact about
     * the group, not about who is looking. Counting only the members this caller can see would dissolve
     * a live cross-client grouping because the person doing the split could see one half of it.
     */
    private function dissolveIfAlone(string $groupId): bool
    {
        if (ExternalCreative::query()->where('creative_group_id', $groupId)->count() >= 2) {
            return false;
        }

        ExternalCreative::query()->where('creative_group_id', $groupId)->update(['creative_group_id' => null]);
        CreativeGroup::query()->whereKey($groupId)->delete();

        return true;
    }

    /**
     * @param  Collection<int, ExternalCreative>  $members
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function rowsByGroup(Collection $members, array $rows): array
    {
        $groupOf = [];
        foreach ($members as $member) {
            $groupOf[(string) $member->getKey()] = (string) $member->creative_group_id;
        }

        $out = [];
        foreach ($rows as $row) {
            $group = $groupOf[(string) ($row['id'] ?? '')] ?? null;
            if ($group !== null) {
                $out[$group][] = $row;
            }
        }

        return $out;
    }

    /**
     * One group, read as a unit — and honest about when it cannot be read as one.
     *
     * Spend and impressions add across platforms. CPA and ROAS do not add across OBJECTIVES: an
     * awareness cut and a sales cut of the same film are the same asset and are not the same question,
     * and a single blended figure over both is the mixing §14 forbids. So when the members disagree
     * about the objective, this says so and offers NO headline set — the per-platform table below it
     * is the answer, not a number that averages two questions.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function groupSummary(CreativeGroup $group, array $rows): array
    {
        $objectives = array_values(array_unique(array_filter(
            array_map(static fn (array $r): ?string => $r['objective'] ?? null, $rows),
            static fn (?string $o): bool => $o !== null && $o !== '',
        )));
        $paths = array_values(array_unique(array_map(static fn (array $r): string => (string) ($r['path'] ?? ''), $rows)));

        // Aggregated once: the group's headline now depends on what the group can actually answer,
        // and computing it twice would let the payload and the selection drift apart.
        $groupFigures = $this->metrics->aggregate(array_map(
            static fn (array $r): mixed => $r['metrics'] ?? null,
            $rows,
        ));
        $shared = count($objectives) === 1 ? $objectives[0] : null;
        $mixed = count($objectives) > 1 || count($paths) > 1;

        return [
            'id' => (string) $group->getKey(),
            'name' => $group->name,
            'method' => $group->method,
            'confirmed' => $group->isConfirmed(),
            'confirmed_at' => $group->confirmed_at?->toIso8601String(),
            'project_id' => (string) $group->project_id,
            'creative_count' => count($rows),
            'providers' => array_values(array_unique(array_map(
                static fn (array $r): string => (string) ($r['provider'] ?? ''),
                $rows,
            ))),
            'objectives' => $objectives,
            'paths' => $paths,
            'objective' => $shared,
            'mixed_objectives' => $mixed,
            'headline_metrics' => $mixed ? [] : $this->metrics->headline($shared, $groupFigures),
            'metrics' => $groupFigures,
            'mixed_reason_ar' => $mixed
                ? 'أعضاء هذه المجموعة لا يشتركون في هدف واحد، فلا يُعلن رقم موحّد للتكلفة أو العائد.'
                : null,
            'mixed_reason_en' => $mixed
                ? 'The members of this group do not share one objective, so no single cost or return figure is stated.'
                : null,
        ];
    }

    /**
     * The group per platform (§15.13) — two ads on one platform roll up into that platform's line.
     *
     * The same `aggregate()` the group total uses, applied one level down, so the platform lines add
     * back to the total by construction rather than by both being computed correctly.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function groupByPlatform(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $buckets[(string) ($row['provider'] ?? 'unknown')][] = $row;
        }
        ksort($buckets);

        $out = [];
        foreach ($buckets as $provider => $group) {
            $out[] = [
                'provider' => $provider,
                'creative_count' => count($group),
                'creative_ids' => array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $group),
                'metrics' => $this->metrics->aggregate(array_map(
                    static fn (array $r): mixed => $r['metrics'] ?? null,
                    $group,
                )),
            ];
        }

        return $out;
    }

    /**
     * Who merged or split this group, and when (§15.13).
     *
     * Read by entity id from the append-only log rather than kept on the group row, so a split that
     * dissolved a group still has its record — the trail has to outlive the thing it is about, or the
     * only history is of decisions that were never reversed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupAudit(CreativeGroup $group): array
    {
        $entries = AuditLog::query()
            ->where('entity_type', CreativeGroup::class)
            ->where('entity_id', (string) $group->getKey())
            ->when($group->tenant_id !== null, fn ($q) => $q->where('tenant_id', $group->tenant_id))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'action', 'user_id', 'after', 'created_at']);

        $actors = User::query()
            ->whereIn('id', $entries->pluck('user_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return $entries->map(static fn (AuditLog $entry): array => [
            'id' => (string) $entry->getKey(),
            'action' => $entry->action,
            'at' => $entry->created_at?->toIso8601String(),
            'actor' => $entry->user_id === null ? null : ($actors[$entry->user_id] ?? null),
            'creative_ids' => array_map('strval', (array) ($entry->after['creatives'] ?? array_filter([$entry->after['creative_id'] ?? null]))),
            'group_dissolved' => (bool) ($entry->after['group_dissolved'] ?? false),
        ])->all();
    }

    /** @return array<string, mixed>|null */
    private function groupShape(ExternalCreative $creative): ?array
    {
        if ($creative->creative_group_id === null) {
            return null;
        }

        $group = CreativeGroup::query()->find($creative->creative_group_id);
        if ($group === null) {
            return null;
        }

        return [
            'id' => (string) $group->getKey(),
            'name' => $group->name,
            'method' => $group->method,
            'confirmed' => $group->isConfirmed(),
            'members' => ExternalCreative::query()
                ->where('creative_group_id', $group->getKey())
                ->get(['id', 'provider', 'name'])
                ->map(static fn (ExternalCreative $c): array => [
                    'id' => (string) $c->getKey(),
                    'provider' => $c->provider,
                    'name' => $c->name,
                ])->all(),
        ];
    }

    /** The values the filters can actually take for THIS project, under THIS caller's reach. */
    private function filterOptions(Request $request, ?string $project): array
    {
        return $this->rows->filterOptions(function () use ($request, $project) {
            $q = ExternalCreative::query();
            if ($project !== null) {
                $q->where('project_id', $project);
            }
            $this->applyReach($q, $request);

            return $q;
        });
    }
}
