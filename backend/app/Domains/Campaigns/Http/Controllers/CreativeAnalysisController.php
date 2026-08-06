<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Campaigns\Models\CreativeGroup;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativeFatigue;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Campaigns\Services\CreativePulse;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
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

    public function __construct(
        private readonly CreativeMetrics $metrics,
        private readonly CreativeFatigue $fatigue,
        private readonly CreativePresenter $presenter,
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
    public function pulse(Request $request, CreativePulse $pulse, ?string $project = null): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        [$from, $to] = $this->window($request);

        $query = ExternalCreative::query();

        if ($project !== null) {
            $query->where('project_id', $project);
        }

        $this->applyReach($query, $request);
        $this->applyFilters($query, $request);

        $rows = $this->present($query->get(), $from, $to, withFatigue: true, withPrevious: true);

        return ApiResponse::success(
            $pulse->build($rows, $from, $to) + [
                /*
                 * The same options the library's filter bar is built from.
                 *
                 * The section carries its own controls on a dashboard that has none of its own — and
                 * a control populated from an enum would offer platforms this account has never run,
                 * which is a filter that returns nothing and reads as «no data». Derived from the
                 * rows in reach, exactly as the library's are.
                 */
                'filters' => $this->filterOptions($request, $project),
            ],
            'Creative pulse.',
        );
    }

    /**
     * One creative, in depth: its asset, its figures, its trend, and how it did per platform and
     * per campaign (§15.6).
     */
    public function show(Request $request, string $project, string $creative): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        $model = ExternalCreative::query()->where('project_id', $project)->findOrFail($creative);
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
        $peers = $this->peerAverages($project, $objective, $from, $to, exclude: $id);

        return ApiResponse::success([
            'creative' => $this->presenter->detail($model, $campaign),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'metrics' => $current,
            'previous' => $previous,
            'headline_metrics' => $this->metrics->headline($objective),
            'path' => $this->metrics->pathFor($objective)->value,
            'fatigue' => $this->fatigue->assess($current ?? ['active_days' => 0], $previous),
            'trend' => $this->trend($id, $from, $to),
            'by_platform' => $this->byPlatform($model, $from, $to),
            'by_campaign' => $this->byCampaign($model, $from, $to),
            'peers' => $peers,
            'group' => $this->groupShape($model),
        ], 'Creative analysis.');
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
        /*
         * `campaigns.link` — the permission that already means «say two platform records are one
         * thing». Grouping creatives across platforms is the same judgement one level down, and
         * inventing a `campaigns.manage` key nobody grants would have made this endpoint unreachable
         * for every existing role.
         */
        abort_unless($request->user()?->hasPermission('campaigns.link'), 403);

        $data = $request->validate([
            'creative_ids' => ['required', 'array', 'min:2'],
            'creative_ids.*' => ['string'],
            'name' => ['nullable', 'string', 'max:200'],
        ]);

        $creatives = ExternalCreative::query()
            ->where('project_id', $project)
            ->whereIn('id', $data['creative_ids'])
            ->get();

        abort_if($creatives->count() < 2, 422, 'At least two creatives from this project are needed to group.');

        $group = CreativeGroup::create([
            'project_id' => $project,
            'name' => $data['name'] ?? $creatives->first()->name,
            'method' => 'manual',
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => Carbon::now(),
        ]);

        ExternalCreative::query()->whereIn('id', $creatives->modelKeys())
            ->update(['creative_group_id' => $group->getKey()]);

        $audit->log(
            action: 'creative.group.created',
            entityType: CreativeGroup::class,
            entityId: (string) $group->getKey(),
            after: ['creatives' => $creatives->modelKeys(), 'method' => 'manual'],
        );

        return ApiResponse::success([
            'id' => (string) $group->getKey(),
            'name' => $group->name,
            'method' => $group->method,
            'creative_ids' => array_map('strval', $creatives->modelKeys()),
        ], 'Creatives grouped.', status: 201);
    }

    /** Split a creative out of its group — the undo §15.8 requires for a wrong automatic match. */
    public function ungroup(Request $request, AuditLogger $audit, string $project, string $creative): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.link'), 403);

        $model = ExternalCreative::query()->where('project_id', $project)->findOrFail($creative);
        $groupId = $model->creative_group_id;

        abort_if($groupId === null, 422, 'This creative is not grouped.');

        $model->forceFill(['creative_group_id' => null])->save();

        // A group of one is not a group. Removing it keeps «grouped» meaning «shared with another
        // platform», so a lone survivor is not left wearing a badge that promises company.
        $remaining = ExternalCreative::query()->where('creative_group_id', $groupId)->count();
        if ($remaining < 2) {
            ExternalCreative::query()->where('creative_group_id', $groupId)->update(['creative_group_id' => null]);
            CreativeGroup::query()->whereKey($groupId)->delete();
        }

        $audit->log(
            action: 'creative.group.split',
            entityType: CreativeGroup::class,
            entityId: (string) $groupId,
            after: ['creative_id' => (string) $model->getKey(), 'group_dissolved' => $remaining < 2],
        );

        return ApiResponse::success(['creative_id' => (string) $model->getKey()], 'Creative split from its group.');
    }

    // ---- internals ----------------------------------------------------------------------------

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : Carbon::today();
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())
            : $to->copy()->subDays(29);

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    /**
     * The ceiling every library read sits under, before a single filter is considered.
     *
     * The tenant scope is on the model; this adds the CLIENT ceiling, which the model cannot know
     * about. A manager confined to two clients must not reach a third client's creatives by asking
     * for them, and `reachableClientIds()` returns `[]` — not null — for anyone without an explicit
     * grant, so the fail-closed direction is the default rather than a special case.
     *
     * Applied to the query, never to the response: filtering rows out after fetching them is the
     * shape of leak that shows up in a total, a chart axis or a page count even when the list looks
     * right.
     */
    private function applyReach(mixed $query, Request $request): void
    {
        $reach = app(ClientScopeResolver::class)->reachableClientIds($request->user());

        if ($reach === null) {
            return; // an explicit `clients.view_all` holder — never inferred from an empty scope.
        }

        $query->whereIn('project_id', function ($sub) use ($reach): void {
            $sub->select('id')->from('projects')->whereIn('client_workspace_id', $reach);
        });
    }

    private function applyFilters(mixed $query, Request $request): void
    {
        // Singular and plural both accepted: the older callers send `provider[]`, the library sends
        // `providers[]`. One name would have been better; breaking the older one to get it would
        // have been worse.
        $columns = [
            'provider' => 'provider', 'providers' => 'provider',
            'format' => 'format', 'formats' => 'format',
            'status' => 'status', 'statuses' => 'status',
        ];

        foreach ($columns as $param => $column) {
            $values = array_filter((array) $request->input($param, []));
            if ($values !== []) {
                $query->whereIn($column, $values);
            }
        }

        foreach ([
            'campaign_ids' => 'campaign_id',
            'ad_set_ids' => 'external_ad_set_id',
            'ad_ids' => 'external_ad_id',
            'project_ids' => 'project_id',
        ] as $param => $column) {
            if ($ids = array_filter((array) $request->input($param, []))) {
                $query->whereIn($column, $ids);
            }
        }

        // A client is reached through its projects; `external_creatives` has no client column, and
        // adding one would be a second place for the same fact to be wrong.
        if ($clients = array_filter((array) $request->input('client_ids', []))) {
            $query->whereIn('project_id', function ($sub) use ($clients): void {
                $sub->select('id')->from('projects')->whereIn('client_workspace_id', $clients);
            });
        }

        if ($search = trim($request->string('search')->toString())) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('headline', 'ilike', "%{$search}%")
                    ->orWhere('body', 'ilike', "%{$search}%");
            });
        }

        /*
         * Creative TYPE, not the provider's format string.
         *
         * «image», «video» and «carousel» are what an operator filters by; the column holds whatever
         * each platform calls it (`VIDEO`, `single_video`, `carousel_ad`, …). Matched with the same
         * `str_contains` rule `CreativePresenter::kind()` uses, so the filter and the badge on the
         * card can never disagree about what a creative is.
         */
        if ($kinds = array_filter((array) $request->input('kinds', []))) {
            $query->where(function ($q) use ($kinds): void {
                foreach ($kinds as $kind) {
                    $q->orWhere('format', 'ilike', '%'.$kind.'%');
                }
            });
        }

        $objectives = array_filter((array) $request->input('objectives', []));
        $paths = array_filter((array) $request->input('paths', []));

        if ($paths !== []) {
            // A path is a set of objectives, so it narrows to the objectives it contains — and when
            // an objective filter is also present, to the INTERSECTION of the two. Applying them as
            // separate `whereIn`s would have widened the result the moment both were used.
            $fromPaths = [];
            foreach (CampaignObjective::cases() as $case) {
                if (in_array($this->metrics->pathFor($case->value)->value, $paths, true)) {
                    $fromPaths[] = $case->value;
                }
            }

            $objectives = $objectives === [] ? $fromPaths : array_values(array_intersect($objectives, $fromPaths));

            // Both named and nothing satisfies both: match nothing, rather than falling through to
            // «no objective filter» and answering with everything.
            if ($objectives === []) {
                $objectives = ['__none__'];
            }
        }

        if ($objectives !== []) {
            $query->whereIn('campaign_id', function ($sub) use ($objectives): void {
                $sub->select('id')->from('unified_campaigns')->whereIn('objective', $objectives);
            });
        }
    }

    /**
     * Ordering, including by a figure the row does not carry.
     *
     * Sorting by spend cannot be done on `external_creatives` — the figures live in
     * `creative_daily_metrics` and belong to the requested window. Done in PHP it would sort only the
     * page, which looks identical on page one and is wrong everywhere after it. A joined aggregate
     * over the SAME window keeps the sort and the pagination talking about the same numbers.
     */
    private function applySort(mixed $query, Request $request, Carbon $from, Carbon $to): mixed
    {
        $sort = $request->string('sort')->toString();
        $metric = ['spend', 'impressions', 'clicks', 'conversions', 'revenue'];

        if (in_array($sort, $metric, true)) {
            $totals = DB::table('creative_daily_metrics')
                ->select('creative_id')
                ->selectRaw('SUM('.$sort.') AS sort_total')
                ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
                ->groupBy('creative_id');

            return $query
                ->leftJoinSub($totals, 'sorted', 'sorted.creative_id', '=', 'external_creatives.id')
                ->select('external_creatives.*')
                // NULLS LAST: a creative the platform reported nothing for has not «earned last
                // place» on spend — it has no figure at all, and floating it to the top of an
                // ascending sort would read as the cheapest creative in the project.
                ->orderByRaw('sorted.sort_total DESC NULLS LAST')
                ->orderBy('external_creatives.id');
        }

        return match ($sort) {
            'name' => $query->orderBy('name')->orderBy('id'),
            'oldest' => $query->orderBy('first_seen_at')->orderBy('id'),
            default => $query
                ->orderByDesc('last_active_at')
                ->orderByDesc('last_synced_at')
                // `id` last, always: the first two tie freely across a batch synced in one run, and
                // an ordering with ties repeats and skips rows across pages.
                ->orderBy('id'),
        };
    }

    /**
     * Shape a page of creatives with their figures — TWO queries for the whole page, never per row.
     *
     * @param  Collection<int, ExternalCreative>  $creatives
     * @return list<array<string, mixed>>
     */
    private function present(mixed $creatives, Carbon $from, Carbon $to, bool $withFatigue, bool $withPrevious = false): array
    {
        $ids = array_map('strval', $creatives->modelKeys());
        if ($ids === []) {
            return [];
        }

        $figures = $this->metrics->forCreatives($ids, $from, $to);

        $previous = [];
        if ($withFatigue || $withPrevious) {
            $days = $from->diffInDays($to) + 1;
            $prevTo = $from->copy()->subDay();
            $previous = $this->metrics->forCreatives($ids, $prevTo->copy()->subDays($days - 1), $prevTo);
        }

        $campaigns = UnifiedCampaign::query()
            ->whereIn('id', $creatives->pluck('campaign_id')->filter()->unique()->values()->all())
            ->get(['id', 'name', 'objective'])
            ->keyBy('id');

        $out = [];
        foreach ($creatives as $creative) {
            $id = (string) $creative->getKey();
            $campaign = $creative->campaign_id === null ? null : $campaigns->get($creative->campaign_id);
            $objective = $campaign?->objective;

            $row = $this->presenter->card($creative, $campaign);
            $row['objective'] = $objective;
            $row['path'] = $this->metrics->pathFor($objective)->value;
            $row['headline_metrics'] = $this->metrics->headline($objective);
            $row['metrics'] = $figures[$id] ?? null;

            if ($withFatigue) {
                $row['fatigue'] = $this->fatigue->assess($figures[$id] ?? ['active_days' => 0], $previous[$id] ?? null);
            }

            /*
             * The previous period's figures, on the row, for the dashboard only.
             *
             * «Fastest growing» is a comparison, and the comparison has to be made where the two
             * periods are both in hand. The library does not carry this — a page of 24 cards would
             * double its payload for a figure no card shows.
             */
            if ($withPrevious) {
                $row['previous'] = $previous[$id] ?? null;
            }

            $out[] = $row;
        }

        return $out;
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
     * The average of creatives doing the SAME job, so «below average» means something.
     *
     * @return array<string, float|null>|null
     */
    private function peerAverages(string $project, ?string $objective, Carbon $from, Carbon $to, string $exclude): ?array
    {
        $path = $this->metrics->pathFor($objective);

        $peerIds = ExternalCreative::query()
            ->where('project_id', $project)
            ->whereKeyNot($exclude)
            ->whereIn('campaign_id', function ($sub): void {
                $sub->select('id')->from('unified_campaigns');
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

    /** The values the filters can actually take for THIS project. */
    /**
     * What the filter controls may offer — derived from the rows in reach, never from a fixed list.
     *
     * A select populated from an enum offers platforms this project has never run and campaigns it
     * does not have: every one of those is a control that returns an empty list, which reads as «no
     * data» rather than «nothing was ever going to match». The two exceptions are deliberate —
     * marketing paths and fatigue states are a closed vocabulary this system defines, not a
     * reflection of what happens to be synced, and an operator needs to see «Fatigued» in the list to
     * know the concept exists even in a week when nothing is.
     */
    private function filterOptions(Request $request, ?string $project): array
    {
        $base = function () use ($request, $project) {
            $q = ExternalCreative::query();
            if ($project !== null) {
                $q->where('project_id', $project);
            }
            $this->applyReach($q, $request);

            return $q;
        };

        $distinct = static fn (string $column): array => $base()
            ->whereNotNull($column)
            ->distinct()->orderBy($column)->pluck($column)->map(static fn ($v): string => (string) $v)->all();

        $campaignIds = $base()->whereNotNull('campaign_id')->distinct()->pluck('campaign_id')->all();

        $campaigns = $campaignIds === [] ? collect() : UnifiedCampaign::query()
            ->whereIn('id', $campaignIds)->orderBy('name')->get(['id', 'name', 'objective']);

        $projectIds = $base()->distinct()->pluck('project_id')->all();

        $projects = $projectIds === [] ? collect() : DB::table('projects')
            ->whereIn('id', $projectIds)->orderBy('name')
            ->get(['id', 'name', 'client_workspace_id']);

        $clients = $projects->pluck('client_workspace_id')->filter()->unique()->values()->all();

        return [
            'providers' => $distinct('provider'),
            'formats' => $distinct('format'),
            'statuses' => $distinct('status'),
            // The three the UI groups by, in the same vocabulary `CreativePresenter::kind()` uses.
            'kinds' => ['image', 'video', 'carousel'],
            'campaigns' => $campaigns->map(static fn ($c): array => [
                'id' => (string) $c->id, 'name' => $c->name, 'objective' => $c->objective,
            ])->all(),
            'ad_sets' => $distinct('external_ad_set_id'),
            'ads' => $distinct('external_ad_id'),
            'objectives' => $campaigns->pluck('objective')->filter()->unique()->sort()->values()->all(),
            'paths' => array_map(static fn (MarketingPath $p): string => $p->value, MarketingPath::cases()),
            'projects' => $projects->map(static fn ($p): array => [
                'id' => (string) $p->id, 'name' => $p->name,
                'client_id' => $p->client_workspace_id === null ? null : (string) $p->client_workspace_id,
            ])->values()->all(),
            'clients' => $clients === [] ? [] : DB::table('client_workspaces')
                ->whereIn('id', $clients)->orderBy('name')->get(['id', 'name'])
                ->map(static fn ($c): array => ['id' => (string) $c->id, 'name' => $c->name])->all(),
            'health' => [
                CreativeFatigue::IMPROVING, CreativeFatigue::STABLE, CreativeFatigue::WATCH,
                CreativeFatigue::FATIGUED, CreativeFatigue::INSUFFICIENT,
            ],
        ];
    }
}
