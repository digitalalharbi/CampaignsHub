<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\Commerce\Services\StoreFunnelService;
use App\Domains\Metrics\Services\DataFreshnessService;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\ObjectivePerformance;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Analytics\ObjectiveAnalyticsInput;
use App\Domains\Reports\Analytics\ObjectiveAnalyticsSection;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Support\AccountCampaignCeiling;
use App\Domains\Reports\Support\ContentCopy;
use App\Domains\Reports\Support\ContentKey;
use App\Domains\Reports\Support\ReportBreakdowns;
use App\Domains\Reports\Support\ReportComposition;
use App\Domains\Reports\Support\ReportScope;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;

/**
 * LIVEREP-001 — the figures behind a live shared link, recomputed on every request.
 *
 * ## The rule this class exists to enforce
 *
 * **A client's filters may only ever NARROW what the link was given.** The share carries a ceiling —
 * one project, a chosen set of campaigns, a set of platforms, an earliest and a latest date — and every
 * incoming filter is intersected with it. Ask for a campaign that is not in the ceiling and you get the
 * ceiling's campaigns; ask for a date before it starts and you get its start. There is no path through
 * this class by which a parameter widens anything, which is why the intersection happens here, once,
 * rather than in the controller where a later endpoint could forget it.
 *
 * That matters more here than anywhere else in the product, because this is the only surface reachable
 * **without a session**. The tenant and project scopes that protect every other query are driven by the
 * signed-in user; there is no user here. So the scope is entered explicitly from the share's own row,
 * and the aggregator is additionally bounded by campaign id — belt and braces, because a project bound
 * alone would still expose a sibling campaign the client was never shown.
 *
 * ## Honesty about freshness
 *
 * «Live» is a claim about THIS system recomputing, not about the ad platforms having just reported. The
 * two are different and conflating them would be a lie the client cannot check. So every response
 * carries, per platform: when its data was last refreshed, whether a sync has ever succeeded, and
 * whether it is connected at all. A platform with no credentials reports `awaiting_credentials` and is
 * shown as such — never as a zero, which reads as «we spent nothing» rather than «we cannot see it».
 */
final class LiveReportService
{
    /** Filters a client may narrow by. Anything else in the query string is ignored, not honoured. */
    private const NARROWABLE = ['from', 'to', 'providers', 'campaigns'];

    public function __construct(
        private readonly MetricsAggregator $metrics,
        private readonly TenantContext $tenants,
        private readonly ProjectContext $projects,
        private readonly ReportAds $ads,
        private readonly CreativeRows $rows,
        private readonly CreativeMetrics $creativeMetrics,
    ) {}

    /**
     * WHICH form this link is — asked of the share, once, by everything on this path.
     *
     * The link builder writes the operator's choice to `report_shares.form` and creates the report
     * row without a form at all, so `reports.form` falls to its column default of `detailed`. This
     * class read that default directly, which meant the operator's choice never reached the payload:
     * on a link created by the product's own builder the page was told «executive summary» by
     * `PublicReportController` (which does consult `formOr`) while the payload it rendered was
     * composed as «detailed». Two sources of truth for one setting, disagreeing on every real link,
     * and the disagreement is silent — which is why the roster cap that is supposed to distinguish
     * the two products had been permanently off in production.
     *
     * `formOr()` is the share's own resolution — its choice, else the report's — and is what the
     * `show` endpoint has always used. Asking it here is what makes the page and the payload agree.
     */
    private function formFor(ReportShare $share): string
    {
        return $share->formOr($share->report?->form);
    }

    /**
     * One piece of content, opened from a client link — the platform → content drilldown.
     *
     * Resolved by the share-bound `ContentKey`, inside the SAME content bound the payload's lists use
     * (`contentFilters()`), so a key opens only a creative those lists could have shown. A key that
     * matches nothing in the bound is `null`, and the caller answers 404 — never «not yours», which
     * would confirm the creative exists.
     *
     * The trend is not a second aggregation. Every point is `CreativeMetrics::forCreatives()` over
     * that bucket — the reader the content card itself was built from — so the ad-grain fallback, the
     * demo policy and withheld money all come with it, and the points add back to the card's total
     * by construction. A bucket with no rows is `reported: false` with no figures: a creative that did
     * not deliver that day has no numbers, which is not the same as numbers of zero.
     *
     * Daily up to 92 days, weekly beyond: a year of daily points is a chart nobody can read.
     *
     * @param  array<string, mixed>  $requested
     * @return array<string, mixed>|null
     */
    public function content(ReportShare $share, string $key, array $requested): ?array
    {
        // The client path is platform → content: a drill-down never narrows by campaign (see `platform()`).
        unset($requested['campaigns']);

        $scope = $this->ceiling($share);
        $applied = $this->intersect($scope, $requested);

        $this->tenants->setTenantId((string) $share->tenant_id);
        $this->projects->setProjectId($scope['project_id'] === '' ? ReportScope::IMPOSSIBLE : $scope['project_id']);

        $from = Carbon::parse($applied['from']);
        $to = Carbon::parse($applied['to']);

        $query = ExternalCreative::query();
        $this->rows->applyFilters($query, $this->contentFilters($share, $applied, $scope) + [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);

        $id = $query->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->first(static fn (string $id): bool => ContentKey::matches($key, $share, $id));

        if ($id === null) {
            return null;
        }

        $creative = ExternalCreative::query()->whereKey($id)->get();
        $row = $this->rows->lean($creative, $from, $to, withPreview: true)[0] ?? null;
        if ($row === null) {
            return null;
        }

        $days = $from->diffInDays($to) + 1;
        $step = $days > 92 ? 7 : 1;
        $trend = [];
        for ($cursor = $from->copy(); $cursor->lessThanOrEqualTo($to); $cursor->addDays($step)) {
            $end = $cursor->copy()->addDays($step - 1)->min($to);
            $figures = $this->creativeMetrics->forCreatives([$id], $cursor->copy(), $end)[$id] ?? null;
            $trend[] = ['date' => $cursor->toDateString(), 'date_to' => $end->toDateString(), 'reported' => $figures !== null]
                + ($figures ?? []);
        }

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'granularity' => $step === 1 ? 'day' : 'week',
            'content' => ClientEntityBoundary::roster(ContentCopy::rows(ContentKey::attach([$row], $share), $share->creativeVisibility()))[0],
            'trend' => $trend,
        ];
    }

    /**
     * The content bound of this link — project, platforms, campaigns and the ad-account ceiling.
     *
     * One statement for the lists in the payload AND for resolving a content key, so a key can only
     * ever open a creative the lists could have shown.
     *
     * @param  array<string, mixed>  $applied
     * @param  array{project_id: string, campaign_ids: list<string>, providers: list<string>, earliest: string, latest: string}  $scope
     * @return array<string, list<string>>
     */
    private function contentFilters(ReportShare $share, array $applied, array $scope): array
    {
        $shareList = static fn (string $key): array => array_values(array_filter(
            (array) ($share->scope[$key] ?? []),
            static fn ($v): bool => is_scalar($v) && trim((string) $v) !== '',
        ));

        return [
            'project_ids' => $scope['project_id'] === '' ? [ReportScope::IMPOSSIBLE] : [$scope['project_id']],
            'providers' => $applied['providers'] !== [] ? $applied['providers'] : $scope['providers'],
            // The account ceiling, through the same rule `SharedCreativeView` applies to the same link.
            'campaign_ids' => AccountCampaignCeiling::campaigns(
                $applied['campaigns'] !== [] ? $applied['campaigns'] : $scope['campaign_ids'],
                array_values(array_filter((array) ($share->scope['account_ids'] ?? []))),
            ),
            /*
             * The operator's content choices on the link — which creatives or groups it names, which it
             * excludes, and the objective/path axes the metrics are already bound by. `SharedCreativeView`
             * honoured these and the live lists did not, so the Content mode listed exactly the creative
             * an operator had taken out. Read from the share only: no reader control sets them.
             */
            'creative_ids' => $shareList('creative_ids'),
            'creative_group_ids' => $shareList('creative_group_ids'),
            'excluded_creative_ids' => $shareList('excluded_creative_ids'),
            'objectives' => $shareList('objectives'),
            'paths' => $shareList('paths'),
        ];
    }

    /**
     * The ads section for a live link — the deck's own builder, narrowed to this link's scope.
     *
     * @param  array<string, mixed>  $applied
     * @param  array{project_id: string, campaign_ids: list<string>, providers: list<string>, earliest: string, latest: string}  $scope
     * @return array<string, mixed>
     */
    private function adsFor(ReportShare $share, array $applied, array $scope, Carbon $from, Carbon $to): array
    {
        $objective = (string) ($share->report->campaign_objective ?? 'custom');

        $built = $this->ads->for($objective, $from, $to, $this->contentFilters($share, $applied, $scope), $this->formFor($share), liveMedia: true);

        // Each content row gets its share-bound handle before the boundary removes the ids.
        foreach (['ads', 'worst', 'groups', 'platform_groups', 'roster'] as $list) {
            $built[$list] = ContentCopy::rows(ContentKey::attach($built[$list] ?? [], $share), $share->creativeVisibility());
        }

        return [
            /*
             * CLIENT-REPORT-ENTITY-BOUNDARY-001 — the ad, without our primary key for it.
             *
             * These rows carried `id` and `campaign_id` — our own UUIDs — to a link whose whole
             * point is that it names nothing internal. The snapshot path has stripped `campaign_id`
             * since it was written; this one never did.
             */
            /*
             * REPORT-CREATIVE-MEDIA-001 — the media arrives with the rows, not after them.
             *
             * The first version of this fix put a refresh in `PublicReportController::live()`,
             * after this method returned, and it did nothing at all: the boundary calls here strip
             * the creative `id` the resolution is keyed on, so it walked rows it could not match.
             * The whole backend suite stayed green because every case exercised the SNAPSHOT route,
             * and the owner's own report — a LIVE share — went on printing «لا يوجد غلاف».
             *
             * The second version attached it here, before the boundary. That was correct, and it
             * re-loaded creatives `ReportAds` had just read — on a 1,539-creative report, every one
             * of them queried and hydrated twice per open. `liveMedia` asks the builder to resolve
             * it while the models are in hand instead. On the sixty-row seed the two measure the
             * same, so this is a removed redundancy rather than a demonstrated speed-up.
             *
             * The ranked lists below need nothing: `present()` has always carried a preview. The
             * ROSTER was the only section without one, which is exactly what the owner saw.
             */
            'ads' => ClientEntityBoundary::ads($built['ads']),
            'ads_level' => $built['level'],
            'ads_groups' => ClientEntityBoundary::ads($built['groups']),
            /*
             * REPORT-DETAIL-PARITY-001 — the same ads on the platform axis, past the same boundary.
             *
             * `ClientEntityBoundary::ads()` recurses through a group's own `ads` key and stops there,
             * which is correct for an objective group and one rung short for this one: a platform
             * entry holds `groups`, and its ads are two rungs down. Handing the platform entries
             * straight to `ads()` would have stripped nothing at all — the walk never reaches a row —
             * so the boundary is applied to each platform's GROUPS, which is the shape it knows.
             */
            'ads_platform_groups' => array_map(
                static fn (array $platform): array => [
                    ...$platform,
                    'groups' => ClientEntityBoundary::ads($platform['groups'] ?? []),
                ],
                $built['platform_groups'],
            ),
            'ads_absent_reason' => $built['reason'],
            // The same reading the generated deck carries, from the same two ranked lists.
            'ads_reading' => (new AdsExplanation)->explain($built['ads'], $built['worst'], $objective),
            /*
             * REPORT-CREATIVE-TRUTH-001 §B — the inventory, and how much of it this link holds.
             *
             * Through its OWN boundary, not the ranked lists'. A presented row is a different shape:
             * it carries the campaign NAME, and its own `ads` key holds the platform's ad objects —
             * which `ads()` would have walked into as though they were group members. See
             * {@see ClientEntityBoundary::roster()}.
             */
            'ads_roster' => ClientEntityBoundary::roster($built['roster']),
            /*
             * The weakest content, ranked by the same objective metric as `ads`. The Owner asks for
             * «best-performing and weakest content»; `ReportAds` has always computed it and the live
             * payload threw it away after reading it into one sentence.
             */
            'ads_weakest' => ClientEntityBoundary::ads($built['worst']),
            'creatives_in_scope' => $built['creatives_in_scope'],
            'creatives_withheld' => $built['creatives_withheld'],
            'form' => $this->formFor($share),
        ];
    }

    /**
     * Build the payload for a live share, with the caller's filters intersected against its ceiling.
     *
     * @param  array<string, mixed>  $requested  raw query input — untrusted, never used unintersected
     * @param  string  $currency  the report's own currency; every figure here is already normalised to it
     * @return array<string, mixed>
     */
    public function build(ReportShare $share, array $requested, string $currency = ReportingCurrency::DEFAULT): array
    {
        $scope = $this->ceiling($share);
        $applied = $this->intersect($scope, $requested);

        /*
         * Enter the share's own tenant and project.
         *
         * There is no authenticated user on this request, so nothing has set these. They come from the
         * share row — the one thing on this request whose provenance is known, because it was written by
         * an authenticated operator when the link was created.
         */
        $this->tenants->setTenantId((string) $share->tenant_id);
        /*
         * A ceiling with no project sets the IMPOSSIBLE id, never the empty string.
         *
         * The explicit filter uses that sentinel already — «an empty value matches NOTHING rather
         * than everything» — but the ambient project context was set to the raw value, and the global
         * `ProjectScope` then added a second condition binding `''` to a uuid column. Postgres refuses
         * it, so the endpoint answered 500 rather than an empty page: `invalid input syntax for type
         * uuid: ""`. Shares carrying no scope at all are not hypothetical — `DemoAccountsSeeder`
         * creates them, and so does any link minted before the scope existed.
         *
         * Fail-closed and fail-QUIET: the same «matches nothing» the filter means, expressed in the
         * one place that was saying it differently.
         */
        $this->projects->setProjectId($scope['project_id'] === '' ? ReportScope::IMPOSSIBLE : $scope['project_id']);

        $from = Carbon::parse($applied['from']);
        $to = Carbon::parse($applied['to']);

        $engine = $this->scopedEngine($share, $scope, $applied['providers']);

        /*
         * The ceiling's OTHER axes (§14.5) — accounts, objectives, marketing paths, ad sets and ads.
         *
         * Applied from the share alone and never from the query string: these are axes the operator
         * chose when building the link, and there is no client-facing control for them. A filter a
         * reader cannot set is a filter a tampered URL cannot loosen either, which is why they are
         * read here rather than routed through `intersect()`.
         *
         * The ceiling's own campaign list is passed in so ad sets and ads resolve INSIDE it: without
         * it, a share naming an ad set would have its campaign bound replaced by that ad set's
         * campaign — possibly one the ceiling never granted.
         *
         * Every axis is empty on a link built before this existed, and `applyTo()` skips empty axes,
         * so those links behave exactly as they did.
         */
        /*
         * The account axis, read ONCE and handed to everything that needs it.
         *
         * `applyTo()` below binds the engine, so every section built from it — `platforms`, the
         * KPIs, the timeseries — has always been bounded by the accounts the operator granted. The
         * two objective sections are NOT built from that engine: they construct `ObjectivePerformance`
         * directly, and they constructed it WITHOUT this axis, so a link scoped to one ad account
         * drew its platform table for that account and its objective split for every account in the
         * project. The comment beneath promises «the same service the deck calls, on the same bounds»
         * — it was the same service and not the same bounds, because `ReportScope::objectivePerformance()`,
         * which the deck uses, passes `accountIds` and the live path did not.
         *
         * Null rather than an empty array when the link names no account: null is «every account this
         * project has», which is what a share built before the account axis existed means, and an
         * empty list is the fail-closed «none» that the ceiling's other axes use for a deliberate
         * empty choice. Collapsing the two would silently empty every older link in existence.
         */
        /*
         * The campaign ceiling, read with the SAME fail-closed rule the engine uses.
         *
         * `MetricsAggregator::forCampaigns([])` sets an empty list rather than null, and its `where`
         * turns an empty list into the impossible-id sentinel — so a ceiling naming no campaign
         * matches nothing, deliberately. The objective sections wrote `$scope['campaign_ids'] ?: null`,
         * and null in `ObjectivePerformance` means «every campaign»: the one spelling that turns a
         * fail-CLOSED choice into a fail-OPEN one.
         *
         * Measured on the demo world before this: a share naming no campaign rendered `platforms` 0
         * and an objective split of 129,967 — the report body empty and the objective section
         * reporting the whole project, in one document.
         *
         * Passed through as an array, never coalesced: `ObjectivePerformance` carries the same
         * sentinel for an empty list, so the two agree by construction rather than by coincidence.
         */
        $campaignCeiling = $scope['campaign_ids'];

        $accountCeiling = ($share->scope['account_ids'] ?? []) ?: null;

        // A narrowed campaign set is applied on top of the ceiling, never instead of it.
        if ($applied['campaigns'] !== []) {
            $engine = $engine->forCampaigns($applied['campaigns']);
        }

        /*
         * CLIENT-REPORT-ENTITY-BOUNDARY-001 — the coverage verdict travels, its evidence does not.
         *
         * `totals` carries three coverage blocks, and each carries `reasons`: the operator's own
         * account of why a contributor is in the state it is in. On the owner's live client link one
         * of them read «The last sync failed: No connector is registered for provider 'sandbox'.» —
         * an English exception naming an internal artefact, on a report written for a paying client
         * in Arabic. The verdict and the contributor lists stay, because «are these numbers whole»
         * is the client's question and they answer it; why our sync failed is ours.
         */
        $totals = ClientEntityBoundary::coverage($engine->totals($from, $to));
        $previous = ClientEntityBoundary::coverage($this->previousPeriod($engine, $from, $to));

        $payload = [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $from->diffInDays($to) + 1,
            ],
            'currency' => $currency,
            'totals' => $totals,
            /*
             * REPORT-OBJECTIVE-005 — the client's link says what its «conversions» number is.
             *
             * This is the surface where the caveat matters most: an agency's own operator knows a
             * pixel over-reports, and the client reading the link does not. The figure is the sum of
             * each platform's claim, one sale can be claimed by two of them, and no key exists that
             * would prove it. Same method the operator's dashboard calls, so the two cannot end up
             * saying different things about the same number.
             */
            'conversions_basis' => $engine->conversionsBasis($from, $to),
            'deltas' => $this->deltas($totals, $previous),
            'timeseries' => $engine->timeseries($from, $to),
            'platforms' => $this->platformsWithMovement($engine, $from, $to),
            /*
             * CLIENT-REPORT-ENTITY-BOUNDARY-001 — a shared link carries PERFORMANCE, not the campaign
             * plan that produced it.
             *
             * `campaigns` sent every campaign's internal name to a client's browser — «Google Search
             * — Brand», «Meta — White Friday (seasonal)» — and `ad_sets` sent the audience
             * configuration in plain words: «توسيع الجمهور», «إعادة الاستهداف». That is the targeting
             * strategy, which is the agency's work rather than the client's report, and it was in the
             * JSON as well as on the page: a reader with the link could read it out of the response
             * whatever the page chose to draw.
             *
             * The question a campaign table answered for a client — «where did my money go, and what
             * did it do» — is answered by `platforms` and by `objective_performance`, both of which
             * are already here and neither of which names an internal entity. A DETAILED report means
             * more analytical depth, not more of the agency's own vocabulary.
             *
             * The operator's own screens do not come through this service: `MetricsController` still
             * serves the full campaign → ad set → ad hierarchy to anybody who signs in.
             */
            'campaigns' => [],
            /*
             * REPORT-DETAIL-PARITY-001 — the rung between the campaign and the ad.
             *
             * A «detailed report» that stops at the campaign is a summary with a longer label. The
             * ad-set grain is where a media buyer's decisions actually live — an audience, a
             * placement, a budget split — and it has been in `entity_daily_metrics` since
             * ADSET-METRICS-TRUTH-001 asked every provider for it rather than one.
             *
             * Read through the SAME aggregator the operator's drill-down uses, so a client's copy
             * and the agency's screen cannot disagree about one ad set. Empty for a provider that
             * reports no ad-set grain, which the detailed view then says rather than drawing a
             * heading over nothing.
             */
            /*
             * The ad-set rung, withheld from a client link for the same reason and by name: an ad
             * set IS the targeting decision, and «إعادة الاستهداف» tells a merchant how their budget
             * is being segmented rather than what it achieved.
             */
            'ad_sets' => [],
            // The stage list, as every reader of this payload expects. See ReportGenerator for why
            // the aggregator returns the spend alongside it now, and why it is unpacked here.
            'funnel' => ($adFunnel = $engine->funnel($from, $to))['stages'],
            'funnel_spend' => $adFunnel['spend'],
            /*
             * Budget against spend, per PLATFORM — the block the composition calls «budget status».
             *
             * A client link stated what was spent and never what was PLANNED, so the one question a
             * reader can act on before the period ends — «is anything about to run out?» — had no
             * answer on the page. It is the same pacing the operator reads, from the same aggregator,
             * for the same reason the funnel is: a link that paced its own way would be a second
             * answer to a question the client is going to ask somebody.
             *
             * Withheld where the link hides spend. Pacing IS spend divided by a budget the reader can
             * see, so a pacing table beside a hidden spend column hands back the figure the operator
             * chose to withhold.
             */
            'budget' => $share->hide_spend ? [] : $engine->budgetPacingByProvider($from, $to, Carbon::now()),
            /*
             * FUNNEL-001 in a client link — the same section the operator reads, for the same project.
             *
             * Built by the SAME service the analytics tab calls, deliberately: a client link that
             * computed the funnel its own way would be a second answer to «كم طلبًا جاء من الإعلان؟»,
             * and the first time the two disagreed nobody would know which to believe.
             *
             * Null when the project has no store, rather than a funnel of nulls the page would render
             * as a section that failed to load.
             */
            /*
             * REPORT-AD-PREVIEW-001 — the ads, in the client's own link.
             *
             * Built by the SAME service the generated deck calls, on the same objective and with the
             * same ranker, and narrowed to this link's scope. A second implementation here is how
             * «the best ad» comes to mean two different things in two documents about one campaign —
             * and the client is the person holding both.
             *
             * The objective comes from the report the link belongs to, because that is the lens the
             * whole document was written under; a link that ranked ads by a different objective than
             * the deck it accompanies would put a different ad first for no reason a reader could see.
             */
            ...$this->adsFor($share, $applied, $scope, $from, $to),
            /*
             * REPORT-OBJECTIVE-003/004 — the split the client link did not have, and needs most.
             *
             * `totals` above rolls the whole scope together, so its cost per order divides EVERY
             * campaign's spend by the orders the sales campaigns produced. That is the right answer
             * to «what did this programme cost», and the wrong one to «what does an order cost» —
             * and the link is the surface where the second question is asked, by the person paying.
             *
             * The same service the deck calls, on the same bounds: a link that computed its own
             * split would eventually disagree with the document it accompanies about what a sale
             * cost, and the client holds both.
             */
            'objective_performance' => ClientEntityBoundary::objectivePerformance((new ObjectivePerformance(
                projectIds: $scope['project_id'] === '' ? null : [$scope['project_id']],
                campaignIds: $applied['campaigns'] !== [] ? $applied['campaigns'] : $campaignCeiling,
                providers: $applied['providers'] !== [] ? $applied['providers'] : ($scope['providers'] ?: null),
                accountIds: $accountCeiling,
            ))->build($from, $to)),
            /*
             * OBJECTIVE-ANALYTICS-DEPTH-001 — the strongest and weakest campaign INSIDE each path.
             *
             * The link listed campaigns by spend, which answers «where did the money go» and never
             * «which of these worked». A single ranked list across a mixed programme would answer it
             * wrongly: a brand campaign sits at the bottom of a ROAS table for not producing revenue
             * it was never asked to produce. Inside a path, both ends are read on that path's own
             * metric — and where fewer than two campaigns spent there, the reason travels instead.
             */
            'objective_leaders' => (new ObjectivePerformance(
                projectIds: $scope['project_id'] === '' ? null : [$scope['project_id']],
                campaignIds: $applied['campaigns'] !== [] ? $applied['campaigns'] : $campaignCeiling,
                providers: $applied['providers'] !== [] ? $applied['providers'] : ($scope['providers'] ?: null),
                accountIds: $accountCeiling,
            ))->leadersByPath($from, $to, by: 'provider'),
            /*
             * ATTRIB-VIS-001 — the link says which optional sections it is allowed to open.
             *
             * Attribution is a PERMISSION on the share, served by its own endpoint. The live surface
             * could not offer it because it never carried the flags; it does now, and the section
             * itself is still fetched through the gated route rather than inlined here.
             */
            /*
             * The flags the PAGE reads, with attribution closed on a link narrower than its project.
             *
             * `PublicReport` mounts `SharedAttributionSection` on this flag alone, and that component
             * deliberately carries no refusal path — so leaving the flag true while the endpoint
             * refuses would render a section that appears and then fails, the one outcome its own
             * docblock rules out. The predicate lives on the share so the flag and the endpoint cannot
             * drift into disagreeing about which links are too narrow.
             */
            'sections' => $share->visibleSections(),
            // REPORT-DRILLDOWN-001 — which optional breakdowns this link offers; off here means no control is drawn.
            'breakdowns' => ReportBreakdowns::forShare($share),
            'store_funnel' => $this->storeFunnel($share, $scope['project_id'], $from, $to),
            'freshness' => $this->freshness((string) $share->tenant_id, $scope['project_id'], $scope['providers']),
            /*
             * LIVEREP-002 — the metrics the operator chose, in the order they chose to show them.
             *
             * Empty means «all of them», which is what a link built before metric selection existed
             * gets, and what an operator who skipped the step means. The client's page renders THIS
             * list rather than a fixed set, so unticking «Revenue» actually removes the card instead
             * of merely blanking a number — a blank card still tells the reader a figure exists and
             * is being withheld.
             */
            'metrics' => array_values(array_filter(
                (array) ($share->scope['metrics'] ?? []),
                static fn ($m): bool => is_string($m) && $m !== '',
            )),
            'available' => [
                'providers' => $scope['providers'],
                /*
                 * The campaign PICKER is gone from a client link — the owner's own report of it:
                 * «اسم واختيار الحملة احذفه من التقارير… ممكن استبداله باختيار المنصات».
                 *
                 * It listed every campaign by internal name AND by id, so the control that let a
                 * client narrow their report was also the one that published the agency's naming and
                 * its primary keys. The platform picker above answers the same need — «show me
                 * Snapchat only» — in a vocabulary that is the client's as much as ours.
                 *
                 * The key stays, empty, because a link built before this shipped has a page that
                 * reads it: an absent key would be a crash where an empty list is a control that
                 * simply does not appear.
                 */
                'campaigns' => [],
                'earliest' => $scope['earliest'],
                'latest' => $scope['latest'],
            ],
            'applied' => $applied,
        ];

        /*
         * REPORT-ANALYTICAL-DEPTH-001 — the client's link says what it contains, and why anything is
         * missing, from the SAME derivation the generated report uses.
         *
         * It is computed over the assembled payload, after every figure is in place, so the contents
         * cannot promise a section the link does not have. A live link that listed its own sections
         * would be a second answer to «what is in this report», and the first time the two disagreed
         * the client would be holding both.
         */
        /*
         * `composesNarrative: false` — this document composes no written analysis.
         *
         * Findings, recommendations and the executive summary are written when a report is
         * GENERATED. A live link recomputes its figures on every open and never composes them,
         * so the default reasons — «no finding is supported by the figures in this period» —
         * would tell a client their own data had been examined and found wanting when it was
         * never examined at all.
         */
        /*
         * A section the operator switched OFF leaves the payload, it is not hidden on the page.
         *
         * `ShareSections` states the rule for the attribution flag and it holds for these too: «a
         * section removed from the UI while its data still travels in the JSON is not a permission,
         * it is a CSS rule — and the network tab is one keystroke away». The same reasoning makes a
         * display toggle honest rather than decorative: unticking «budget» must mean the figures are
         * not in the document, or the control is a lie the operator cannot see through.
         *
         * BEFORE the outline is composed, so «what is in this report» describes what survived.
         */
        /*
         * REPORT-OBJECTIVE-ANALYTICS-001 — objective-aware KPI blocks, leaders, trend and contribution.
         *
         * The same section the generated snapshot (and so its PDF) carries, built by the same class on
         * the same bounds as the objective split above, and fed the roster this link already read so
         * the content leaders and the ads grid describe the same creatives. Built BEFORE the section
         * flags, so switching «objective breakdown» off removes it like its neighbours.
         */
        $payload[ObjectiveAnalyticsSection::KEY] = (new ObjectiveAnalyticsSection)->build(new ObjectiveAnalyticsInput(
            from: $from,
            to: $to,
            projectIds: $scope['project_id'] === '' ? null : [$scope['project_id']],
            campaignIds: $applied['campaigns'] !== [] ? $applied['campaigns'] : $campaignCeiling,
            providers: $applied['providers'] !== [] ? $applied['providers'] : ($scope['providers'] ?: null),
            accountIds: $accountCeiling,
            content: array_values((array) ($payload['ads_roster'] ?? [])),
        ));

        $payload = $this->applySectionFlags($payload, $share);

        /*
         * REPORT-PRODUCT-MODEL-001 / Owner defect row 96 — the FORM composes the document.
         *
         * A summary is not a detailed report with blocks hidden; it is a shorter document, and what
         * it does not contain must not travel in its payload. `ReportComposition` states which
         * sections belong to which product and why, and it runs AFTER the operator's section flags
         * because the form is the stronger statement: a flag can switch a section off within a
         * product, it cannot switch one on that the product does not have.
         *
         * BEFORE the outline, for the same reason `applySectionFlags` is — «what is in this report»
         * has to describe what survived.
         */
        $payload = ReportComposition::for($this->formFor($share))->apply($payload);

        $payload['outline'] = (new ReportStructure)->sections($payload, composesNarrative: false);

        return $payload;
    }

    /**
     * REPORT-DRILLDOWN-001 — one platform, opened from the comparison.
     *
     * Its objective KPIs, its trend, its share of spend against its share of the outcome, and its
     * strongest and weakest content — everything a reader needs to answer «how did THIS channel do»
     * without the default dashboard having to carry it.
     *
     * ## The client path is platform → content, and nothing in between
     *
     * `campaigns` is dropped from the request before anything reads it. The live link accepts a
     * campaign narrowing for historical reasons, inside its ceiling; a drill-down does not, because a
     * drill-down that answered «this platform, for that campaign» would be a campaign drill-down with
     * the name removed. Nothing here returns a campaign, an ad set, or a count that would let one be
     * reconstructed: the objective blocks carry the path, never its campaigns.
     *
     * ## Null, not an empty drawer
     *
     * A platform outside the link's ceiling, or one with no figures in the window, answers null and the
     * controller answers 404 — the same answer for both, so the endpoint cannot be used to learn which
     * platforms a project buys on beyond the ones the link already shows.
     *
     * Shares are ratios of SUMS over the same scoped engine the comparison table reads, so the drawer
     * and the row it was opened from cannot disagree. A total of zero is a null share, never 0%.
     *
     * @param  array<string, mixed>  $requested
     * @return array<string, mixed>|null
     */
    public function platform(ReportShare $share, string $provider, array $requested): ?array
    {
        unset($requested['campaigns'], $requested['providers']);

        $scope = $this->ceiling($share);
        if ($scope['providers'] !== [] && ! in_array($provider, $scope['providers'], true)) {
            return null;
        }

        $applied = $this->intersect($scope, $requested);
        $applied['providers'] = [$provider];

        $this->tenants->setTenantId((string) $share->tenant_id);
        $this->projects->setProjectId($scope['project_id'] === '' ? ReportScope::IMPOSSIBLE : $scope['project_id']);

        $from = Carbon::parse($applied['from']);
        $to = Carbon::parse($applied['to']);

        $whole = $this->scopedEngine($share, $scope, [])->byProvider($from, $to);
        $row = collect($whole)->firstWhere('provider', $provider);
        if ($row === null) {
            return null;
        }

        $engine = $this->scopedEngine($share, $scope, [$provider]);
        $lens = new ReportObjectiveLens((string) ($share->report->campaign_objective ?? 'custom'));
        $outcome = $this->outcomeMetric($lens, $share);

        $sum = static fn (string $key): float => array_sum(array_map(static fn (array $r): float => (float) ($r[$key] ?? 0), $whole));
        $shareOf = static fn (float $value, float $total): ?float => $total > 0 ? round($value / $total, 4) : null;

        $sections = $share->visibleSections();
        $content = ReportBreakdowns::allows($share, ReportBreakdowns::CONTENT)
            ? $this->adsFor($share, ['campaigns' => [], 'providers' => [$provider]] + $applied, $scope, $from, $to)
            : null;

        $objectives = (new ObjectivePerformance(
            projectIds: $scope['project_id'] === '' ? null : [$scope['project_id']],
            campaignIds: $scope['campaign_ids'],
            providers: [$provider],
            accountIds: ($share->scope['account_ids'] ?? []) ?: null,
        ))->build($from, $to);

        $totals = ClientEntityBoundary::coverage($engine->totals($from, $to));

        $payload = [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $from->diffInDays($to) + 1],
            'provider' => $provider,
            'objective' => ['key' => $lens->value(), 'ranking' => $lens->rankingMetric()['key']],
            'objectives' => ($sections['objective_breakdown'] ?? true) ? $this->objectiveBlocks($objectives, $totals) : [],
            'totals' => $totals,
            'timeseries' => $engine->timeseries($from, $to),
            'shares' => [
                'spend' => $share->hide_spend ? null : [
                    'value' => (float) ($row['spend'] ?? 0),
                    'total' => $sum('spend'),
                    'share' => $shareOf((float) ($row['spend'] ?? 0), $sum('spend')),
                ],
                'outcome' => [
                    'metric' => $outcome,
                    'value' => (float) ($row[$outcome] ?? 0),
                    'total' => $sum($outcome),
                    'share' => $shareOf((float) ($row[$outcome] ?? 0), $sum($outcome)),
                ],
            ],
            'ads' => $content['ads'] ?? [],
            'ads_weakest' => $content['ads_weakest'] ?? [],
            'breakdowns' => ReportBreakdowns::forShare($share),
        ];

        return ReportComposition::for($this->formFor($share))->apply($payload);
    }

    /**
     * The objective blocks of one platform — the paths it spent on, each with its own headline metrics.
     *
     * SEAM — lane `report-objective-analytics` is building the canonical objective → metric mapping
     * around `ObjectivePerformance`. Until it lands, the mapping is `MarketingPath::headlineMetrics()`
     * as `ObjectivePerformance` already derives it; a metric that service does not compute is left
     * out rather than printed as «—», because it is not unavailable — it is not asked here. Swap this
     * method's body for that service and the payload shape stays.
     *
     * Campaign lists are never read: only the path, its labels and its figures leave this method.
     *
     * @param  array<string, mixed>  $objectives
     * @param  array<string, mixed>  $totals
     * @return list<array<string, mixed>>
     */
    private function objectiveBlocks(array $objectives, array $totals): array
    {
        /*
         * MONEY-TRUTH — a path's money is a sum of CONVERTED rows, and `ObjectivePerformance` does not
         * carry which of them were withheld for want of a rate. Where the platform's own totals say
         * some were, every figure built on that money is unavailable here («—»), never the converted
         * subset presented as the whole.
         */
        $unavailable = array_merge(
            (int) ($totals['spend_withheld_rows'] ?? 0) > 0 ? ['spend', 'cpa', 'cpc', 'cpm', 'cost_per_lpv', 'roas'] : [],
            (int) ($totals['revenue_withheld_rows'] ?? 0) > 0 ? ['revenue', 'roas', 'aov'] : [],
        );

        $blocks = [];
        foreach ((array) ($objectives['paths'] ?? []) as $path) {
            if ((float) ($path['spend'] ?? 0) <= 0 && (float) ($path['impressions'] ?? 0) <= 0) {
                continue;
            }
            $metrics = [];
            foreach ((array) ($path['headline_metrics'] ?? []) as $key) {
                if (array_key_exists($key, $path)) {
                    $metrics[$key] = in_array($key, $unavailable, true) ? null : $path[$key];
                }
            }
            $blocks[] = [
                'path' => $path['path'],
                'label_ar' => $path['label_ar'],
                'label_en' => $path['label_en'],
                'metrics' => $metrics,
            ];
        }

        return $blocks;
    }

    /**
     * What «the outcome» is for this report's objective — the figure a platform's share is read on.
     *
     * Revenue for sales, unless the link hides revenue: then results, because a share of hidden
     * revenue discloses its distribution. Reach-type objectives are read on what they buy.
     */
    private function outcomeMetric(ReportObjectiveLens $lens, ReportShare $share): string
    {
        return match ($lens->rankingMetric()['key']) {
            'roas' => $share->hide_revenue ? 'conversions' : 'revenue',
            'cpc' => 'clicks',
            'cpm' => 'impressions',
            default => 'conversions',
        };
    }

    /**
     * The link's engine: its campaign ceiling, its other axes (§14.5), and a platform set.
     *
     * One construction for the whole payload and for the platform drill-down, so the drawer and the
     * comparison row it is opened from are read through the same bounds — see `build()` for why each
     * axis is applied from the share alone and never from the query string.
     *
     * @param  list<string>  $providers
     */
    private function scopedEngine(ReportShare $share, array $scope, array $providers): MetricsAggregator
    {
        return ReportScope::fromArray([
            'campaign_ids' => $scope['campaign_ids'],
            'account_ids' => $share->scope['account_ids'] ?? [],
            'objectives' => $share->scope['objectives'] ?? [],
            'paths' => $share->scope['paths'] ?? [],
            'ad_set_ids' => $share->scope['ad_set_ids'] ?? [],
            'ad_ids' => $share->scope['ad_ids'] ?? [],
        ])->applyTo($this->metrics->forCampaigns($scope['campaign_ids'])->forProviders($providers));
    }

    /**
     * Remove the blocks whose section the link does not publish.
     *
     * One flag can own more than one key — «platform comparison» is the table and the distribution
     * drawn from the same rows, «content» is the ranked ads and the roster beneath them — and the
     * mapping is written out rather than inferred so that adding a payload key is a decision about
     * which section it belongs to instead of a silent escape from all of them.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function applySectionFlags(array $payload, ReportShare $share): array
    {
        $sections = $share->visibleSections();

        /*
         * Each key with the EMPTY VALUE ITS OWN SHAPE TAKES — a list becomes `[]`, a block becomes
         * null — because the page reads these keys and the two are not interchangeable to it.
         *
         * `[]` is truthy in Javascript. Emptying `objective_performance` to a list would leave
         * `payload.objective_performance && …` true, render the block, and then read `.direct` off an
         * array: a crash on a client's report, produced by a switch meant to remove a section. The
         * shapes are written down here rather than inferred from the value, which is how that was
         * nearly shipped.
         */
        $owned = [
            'platform_comparison' => ['platforms' => []],
            'objective_breakdown' => ['objective_performance' => null, 'objective_leaders' => null, 'objective_analytics' => null],
            'creatives' => [
                'ads' => [], 'ads_groups' => [], 'ads_platform_groups' => [], 'ads_roster' => [], 'ads_weakest' => [], 'top_creatives' => [],
                'worst_creatives' => [], 'ads_reading' => null, 'ads_level' => null, 'ads_absent_reason' => null,
            ],
            'budget' => ['budget' => []],
            'funnel_store' => ['funnel' => [], 'store_funnel' => null],
            /*
             * “Compared with the previous period” is the DELTAS, not the figures they sit beside.
             * Dropping the totals here would remove the report; dropping the movement removes the
             * comparison, which is what the switch is called.
             */
            'previous_comparison' => ['deltas' => [], 'previous' => null, 'objective_performance_previous' => null],
        ];

        /*
         * The content leaders name creatives, so they follow the CONTENT switch as well as their own:
         * a link that hides the ads must not name the best and weakest of them one section earlier.
         */
        if (! ($sections['creatives'] ?? true) && is_array($payload['objective_analytics'] ?? null)) {
            foreach ($payload['objective_analytics']['families'] ?? [] as $i => $block) {
                $payload['objective_analytics']['families'][$i]['content_ranking'] = null;
            }
        }

        foreach ($owned as $flag => $keys) {
            if ($sections[$flag] ?? true) {
                continue;
            }

            foreach ($keys as $key => $empty) {
                if (array_key_exists($key, $payload)) {
                    $payload[$key] = $empty;
                }
            }
        }

        return $payload;
    }

    /**
     * The ceiling, normalised, with every value forced into the shape the rest of this class expects.
     *
     * Read defensively: this is JSON written by an earlier version of the app, and a missing key must
     * degrade to «nothing», not to «everything». `campaign_ids => []` means the link shows no campaign
     * data at all, which is a visibly broken link somebody will report — the opposite mistake is a link
     * that silently shows the whole project.
     *
     * @return array{project_id: string, campaign_ids: list<string>, providers: list<string>, earliest: string, latest: string}
     */
    private function ceiling(ReportShare $share): array
    {
        $scope = $share->scope ?? [];

        return [
            'project_id' => (string) ($scope['project_id'] ?? ''),
            'campaign_ids' => array_values(array_filter((array) ($scope['campaign_ids'] ?? []))),
            'providers' => array_values(array_filter((array) ($scope['providers'] ?? []))),
            'earliest' => (string) ($scope['earliest'] ?? Carbon::now()->subDays(30)->toDateString()),
            'latest' => (string) ($scope['latest'] ?? Carbon::now()->toDateString()),
        ];
    }

    /**
     * Intersect what was asked for with what was granted. Never a union, never a replacement.
     *
     * Dates are CLAMPED rather than rejected: a client dragging a date picker past the window should see
     * the window's edge, not an error they cannot act on. Sets are INTERSECTED: asking for a platform
     * outside the ceiling drops that platform from the request rather than failing it, and asking for
     * nothing means the whole ceiling, which is the only case where the result is as wide as the grant.
     *
     * @param  array{project_id: string, campaign_ids: list<string>, providers: list<string>, earliest: string, latest: string}  $scope
     * @param  array<string, mixed>  $requested
     * @return array{from: string, to: string, providers: list<string>, campaigns: list<string>}
     */
    private function intersect(array $scope, array $requested): array
    {
        $requested = array_intersect_key($requested, array_flip(self::NARROWABLE));

        $earliest = Carbon::parse($scope['earliest'])->startOfDay();
        $latest = Carbon::parse($scope['latest'])->startOfDay();

        $from = $this->clamp($requested['from'] ?? null, $earliest, $latest, $earliest);
        $to = $this->clamp($requested['to'] ?? null, $earliest, $latest, $latest);
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'providers' => $this->within($requested['providers'] ?? null, $scope['providers']),
            'campaigns' => $this->within($requested['campaigns'] ?? null, $scope['campaign_ids']),
        ];
    }

    /** A requested date, pinned inside the granted window; unparseable or absent falls back to `$default`. */
    private function clamp(mixed $value, Carbon $min, Carbon $max, Carbon $default): Carbon
    {
        if (! is_string($value) || $value === '') {
            return $default->copy();
        }

        try {
            $date = Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return $default->copy();
        }

        return $date->lessThan($min) ? $min->copy() : ($date->greaterThan($max) ? $max->copy() : $date);
    }

    /**
     * The requested members that the ceiling actually contains. Empty request → empty result, which the
     * caller reads as «the whole ceiling»; a request of only forbidden members also lands here, so the
     * failure mode of a tampered URL is the link's normal view rather than somebody else's data.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function within(mixed $requested, array $allowed): array
    {
        if (! is_array($requested) || $requested === []) {
            return [];
        }

        $asked = array_map(strval(...), $requested);

        return array_values(array_intersect($asked, $allowed));
    }

    /**
     * REPORT-DETAIL-PARITY-001 — each platform's own period-over-period movement.
     *
     * `deltas` compares the TOTALS, so the client's page could say the account grew 26% and had no
     * way to say which platform did the growing — the one question a per-platform summary exists to
     * answer. A month where Meta fell and TikTok doubled reads as a flat account at the top of the
     * page, and «flat» is the least useful true thing a report can say.
     *
     * The movement is computed with the same ratio rule as the totals' — a ratio, not a percentage,
     * and NULL where there was nothing to compare against rather than «+100%», which invites a
     * reader to see a doubling of a real number where a platform simply started running.
     *
     * A platform with no previous row at all gets an empty movement rather than a fabricated one: it
     * did not shrink to nothing, it was not there.
     *
     * @return list<array<string, mixed>>
     */
    private function platformsWithMovement(MetricsAggregator $engine, Carbon $from, Carbon $to): array
    {
        $current = $engine->byProvider($from, $to);
        $days = $from->diffInDays($to) + 1;
        $before = array_column(
            $engine->byProvider($from->copy()->subDays($days), $from->copy()->subDay()),
            null,
            'provider',
        );

        return array_map(function (array $row) use ($before): array {
            $provider = (string) ($row['provider'] ?? '');
            $previous = $before[$provider] ?? null;

            $row['movement'] = $previous === null ? [] : $this->deltas($row, $previous);

            return $row;
        }, $current);
    }

    /** The same window immediately before this one, for period-over-period deltas. */
    private function previousPeriod(MetricsAggregator $engine, Carbon $from, Carbon $to): array
    {
        $days = $from->diffInDays($to) + 1;

        return $engine->totals($from->copy()->subDays($days), $from->copy()->subDay());
    }

    /**
     * Change per metric as a RATIO — 0.26 for +26% — or null where there was nothing to compare against.
     *
     * A ratio, not a percentage, because that is what every other surface in this product emits
     * (`ReportGenerator` line for line) and what the shared `TrendPill` formats. Returning 26.2 here
     * rendered «2620%» on the client's page: a real figure, multiplied by a hundred, sitting next to a
     * correct spend total — the kind of wrong that looks like a spectacular month rather than a bug.
     * Caught by opening the page, not by a test, which is why the sweep at the end of this feature
     * reads the rendered numbers rather than the payload.
     *
     * Null rather than 100%: growth from zero is not a percentage, and rendering one invites a client to
     * read «+100%» as a real doubling of a real number.
     *
     * @return array<string, float|null>
     */
    private function deltas(array $current, array $previous): array
    {
        $out = [];
        foreach ($current as $key => $value) {
            $before = (float) ($previous[$key] ?? 0);
            $out[$key] = $before > 0.0 ? round(((float) $value - $before) / abs($before), 4) : null;
        }

        return $out;
    }

    /**
     * Per-platform data age and connection state — the honest half of the word «live».
     *
     * `data_freshness_at` is when the SOURCE said its figures were current, which is the number a client
     * should judge the report by. The sync run's `finished_at` is when we last asked. Both are reported,
     * because «we asked ten minutes ago and Meta's latest figures are from this morning» is a different
     * situation from «we have not asked since Friday», and only one of them is our problem to fix.
     *
     * @param  list<string>  $providers
     * @return list<array<string, mixed>>
     */
    /**
     * The store funnel for this link's project, or null when there is no store to build one from.
     *
     * A share that hides revenue hides it HERE too. The funnel is a second place a figure could reach
     * a client, and a hide flag that covered the KPI cards and not this would leak the exact number the
     * operator chose to withhold — which is worse than never having offered the flag.
     *
     * @return array<string,mixed>|null
     */
    private function storeFunnel(ReportShare $share, string $projectId, Carbon $from, Carbon $to): ?array
    {
        $funnel = app(StoreFunnelService::class)->build((string) $share->tenant_id, $projectId, $from, $to);

        if (($funnel['coverage']['stores'] ?? 0) === 0) {
            return null;
        }

        if ($share->hide_revenue) {
            $funnel['totals']['revenue'] = null;
            $funnel['totals']['gross_revenue'] = null;
            $funnel['totals']['attributed_revenue'] = null;
            $funnel['derived']['roas'] = null;
            $funnel['derived']['attributed_roas'] = null;
            $funnel['derived']['aov'] = null;
            $funnel['comparisons']['products'] = [];
            $funnel['stages'] = array_map(static function (array $stage): array {
                if ($stage['key'] === 'revenue') {
                    $stage['value'] = null;
                }

                return $stage;
            }, $funnel['stages']);
        }

        if ($share->hide_spend) {
            $funnel['totals']['spend'] = null;
            $funnel['derived']['cpa'] = null;
            $funnel['derived']['cac'] = null;
            // ROAS and attributed ROAS are spend-derived; leaving them would let a reader divide back
            // to the figure the operator hid.
            $funnel['derived']['roas'] = null;
            $funnel['derived']['attributed_roas'] = null;
            $funnel['comparisons']['platforms'] = [];
        }

        return $funnel;
    }

    /**
     * Built by {@see DataFreshnessService} (UNIFIED-001) rather than queried here.
     *
     * The rows this method used to assemble described the ad platforms only, which was defensible while
     * a report held nothing but ad figures. It now carries the store funnel — orders, revenue, AOV,
     * ROAS — so a footer that vouched for the link's freshness while never once looking at the shop was
     * making a promise about numbers it had not checked. Every source behind the link is listed, and the
     * verdict on each is the same verdict the operator sees on their own dashboard.
     *
     * `state` keeps its previous vocabulary for a synced platform (`synced`), so a client page written
     * against the old payload keeps working; the finer verdicts (`stale`, `failed`) arrive as
     * `detailed_state` alongside.
     *
     * @param  list<string>  $providers
     * @return list<array<string, mixed>>
     */
    private function freshness(string $tenantId, string $projectId, array $providers): array
    {
        /*
         * An empty ceiling means NOTHING, never everything — the same rule {@see ceiling()} states.
         *
         * Passing `null` through to the service here would have meant «no provider filter», so a link
         * whose scope named no platform would have listed every platform the tenant runs. That is not
         * a figure, but it is still a disclosure: which platforms an agency buys on is something a
         * client is not automatically entitled to know, and this is the one surface with no session
         * behind it.
         */
        if ($projectId === '' || $providers === []) {
            return [];
        }

        $sources = app(DataFreshnessService::class)->sources($tenantId, [$projectId], $providers);

        /*
         * CLIENT-DIAGNOSTIC-SEPARATION-001 — the payload carries what a CLIENT can act on.
         *
         * This block used to send `data_as_of`, `last_checked_at` and `detailed_state` for every
         * platform, and the page printed them: «ميتا: 18 أغسطس 23:59» beside «بانتظار بيانات
         * الاعتماد». Those are facts about our plumbing. A client cannot act on the timestamp, cannot
         * ask anyone to move it, and «credentials» is a word from our side of the wall.
         *
         * **Removed from the payload, not hidden in the view.** A shared link has no session behind
         * it, and its JSON is one keystroke away in any browser — a diagnostic that is merely
         * unrendered is still disclosed, and the requirement says so in as many words.
         *
         * What SURVIVES is the one fact that is theirs: a platform is not in these figures. A total
         * that silently omits a platform is worse than any diagnostic, so the flag stays and the
         * clock goes. `name` stays too — the page needs something to call the platform other than a
         * database key.
         */
        return array_values(array_map(static fn (array $source): array => [
            'kind' => $source['kind'],
            'provider' => $source['provider'],
            'name' => $source['name'],
            'state' => $source['state'] === 'awaiting_credentials' ? 'awaiting_credentials' : 'synced',
        ], $sources));
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }
}
