<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * §15.17, made structural — the ONE way a creative is selected and shaped, for every surface.
 *
 * ## Why this is a service and not four copies of a controller method
 *
 * The library, the dashboard's creative section, the client's executive summary and the client's
 * detailed report all answer questions about the same creatives. Each of them could have built its
 * own query — and each would then have had its own idea of what «this project's creatives in this
 * window» means. The first divergence would not announce itself: an operator would read one spend on
 * the dashboard, a client would read another in the report, and both pages would look correct.
 *
 * So the filtering, the ordering, the two-query metric fetch and the presentation live here, and the
 * controllers decide only WHICH ceiling to put in front of them:
 *
 *   - the operator's surfaces pass the signed-in user, and {@see applyReach()} applies the client
 *     ceiling their membership grants;
 *   - the client's report passes no user at all and applies the SHARE's ceiling instead, which is
 *     narrower by construction and cannot be widened by editing a URL.
 *
 * Nothing here consults `auth()` or the request. A service that reached for the current user would be
 * unusable on the one surface that has none, which is exactly the surface where a mistake is public.
 *
 * ## Two queries per page, whatever the page size
 *
 * {@see present()} fetches every figure for the whole page in one grouped query and the campaigns in
 * another. A thousand creatives cost the same as ten (§15.14). The previous window, when asked for,
 * is a third — not one per row.
 */
final class CreativeRows
{
    public function __construct(
        private readonly CreativeMetrics $metrics,
        private readonly CreativeFatigue $fatigue,
        private readonly CreativePresenter $presenter,
    ) {}

    /**
     * The window a creative surface reads, defaulting to the last 30 days and never inverted.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function window(?string $from, ?string $to): array
    {
        $end = ($to !== null && trim($to) !== '') ? Carbon::parse($to) : Carbon::today();
        $start = ($from !== null && trim($from) !== '')
            ? Carbon::parse($from)
            : $end->copy()->subDays(29);

        return $start->greaterThan($end) ? [$end, $start] : [$start, $end];
    }

    /**
     * The ceiling an OPERATOR's read sits under, before a single filter is considered.
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
    public function applyReach(mixed $query, ?User $user): void
    {
        $reach = app(ClientScopeResolver::class)->reachableClientIds($user);

        if ($reach === null) {
            return; // an explicit `clients.view_all` holder — never inferred from an empty scope.
        }

        $query->whereIn('project_id', function ($sub) use ($reach): void {
            $sub->select('id')->from('projects')->whereIn('client_workspace_id', $reach);
        });
    }

    /**
     * The filters, from a plain array rather than a request.
     *
     * An array because the client's report supplies filters that have already been intersected with
     * the share's ceiling — they arrive as data, not as query-string input, and a method that read
     * the request directly would have quietly re-admitted the untrusted original.
     *
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(mixed $query, array $filters): void
    {
        $list = static function (string $key) use ($filters): array {
            return array_values(array_filter((array) ($filters[$key] ?? []), static fn ($v): bool => $v !== null && $v !== ''));
        };

        // Singular and plural both accepted: the older callers send `provider[]`, the library sends
        // `providers[]`. One name would have been better; breaking the older one to get it would
        // have been worse.
        $columns = [
            'provider' => 'provider', 'providers' => 'provider',
            'format' => 'format', 'formats' => 'format',
            'status' => 'status', 'statuses' => 'status',
        ];

        foreach ($columns as $param => $column) {
            if (($values = $list($param)) !== []) {
                $query->whereIn($column, $values);
            }
        }

        foreach ([
            'campaign_ids' => 'campaign_id',
            'ad_set_ids' => 'external_ad_set_id',
            'project_ids' => 'project_id',
        ] as $param => $column) {
            if (($ids = $list($param)) !== []) {
                $query->whereIn($column, $ids);
            }
        }

        /*
         * CREATIVE-AD-RELATION-001 — filtered THROUGH the ads, not through a column that names one.
         *
         * This read `whereIn('external_ad_id', $ids)`, and that column holds whichever ad was
         * imported last. So filtering by any of the other ads carrying a creative returned nothing,
         * and filtering by the last one returned it — with no way to tell those two apart from the
         * result. `ads()` is the real relation, so every ad that carries the creative matches.
         */
        if (($adIds = $list('ad_ids')) !== []) {
            $query->whereHas('ads', fn ($q) => $q->whereIn('external_ads.external_id', $adIds));
        }

        /*
         * Named creatives and named groups are a UNION, and every other axis is an intersection.
         *
         * An operator building a client link picks «these four creatives, plus whatever is in this
         * group». Applied as two `whereIn`s that would mean «creatives that are in the list AND in
         * the group», which is almost always nothing — a link that silently shows an empty section
         * because two controls that read as additive were implemented as restrictive. Kept in one
         * nested clause so that the union cannot leak past the other filters.
         */
        $namedCreatives = $list('creative_ids');
        $namedGroups = $list('creative_group_ids');

        if ($namedCreatives !== [] || $namedGroups !== []) {
            $query->where(function ($q) use ($namedCreatives, $namedGroups): void {
                if ($namedCreatives !== []) {
                    $q->whereIn('id', $namedCreatives);
                }
                if ($namedGroups !== []) {
                    $namedCreatives === []
                        ? $q->whereIn('creative_group_id', $namedGroups)
                        : $q->orWhereIn('creative_group_id', $namedGroups);
                }
            });
        }

        /*
         * Exclusions, which are the one axis that is a UNION rather than an intersection.
         *
         * Everything above narrows by naming what is allowed; this narrows by naming what is not.
         * They compose in the same direction — an excluded id stays excluded however the other axes
         * move — which is why it is applied last and never merged into the allow-lists above.
         */
        if (($excluded = $list('excluded_creative_ids')) !== []) {
            $query->whereNotIn('id', $excluded);
        }

        // A client is reached through its projects; `external_creatives` has no client column, and
        // adding one would be a second place for the same fact to be wrong.
        if (($clients = $list('client_ids')) !== []) {
            $query->whereIn('project_id', function ($sub) use ($clients): void {
                $sub->select('id')->from('projects')->whereIn('client_workspace_id', $clients);
            });
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
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
        if (($kinds = $list('kinds')) !== []) {
            $query->where(function ($q) use ($kinds): void {
                foreach ($kinds as $kind) {
                    $q->orWhere('format', 'ilike', '%'.$kind.'%');
                }
            });
        }

        $objectives = $list('objectives');
        $paths = $list('paths');

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
    public function applySort(mixed $query, ?string $sort, Carbon $from, Carbon $to): mixed
    {
        $sort = (string) $sort;
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
            /*
             * SNAP-CREATIVE-METRICS-LIVE-001 — NULLS LAST, and it is not a refinement.
             *
             * PostgreSQL sorts NULLs FIRST under `DESC`. `last_active_at` is null for every creative
             * that has never delivered, so the moment `UpsertCreativeDailyMetrics` began writing the
             * column, the default order would have opened the library on the creatives with no
             * delivery and pushed the ones that ran below them — the same empty first page, arrived
             * at by the opposite route.
             */
            default => $query
                ->orderByRaw('external_creatives.last_active_at DESC NULLS LAST')
                ->orderByRaw('external_creatives.last_synced_at DESC NULLS LAST')
                // `id` last, always: the first two tie freely across a batch synced in one run, and
                // an ordering with ties repeats and skips rows across pages.
                ->orderBy('external_creatives.id'),
        };
    }

    /**
     * Shape a page of creatives with their figures — TWO queries for the whole page, never per row.
     *
     * @return list<array<string, mixed>>
     */
    public function present(mixed $creatives, Carbon $from, Carbon $to, bool $withFatigue, bool $withPrevious = false): array
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

        /*
         * CREATIVE-PRESENTER-ADS-BACKEND-001 — one query for every creative's ads, not one each.
         *
         * `card()` now reads `$creative->ads`, and this loop calls it once per row. Left to lazy
         * loading that is a query per creative: `CreativePulseApiTest` caught it immediately —
         * «two hundred creatives cost the same queries as two» is exactly the guard for this, and
         * two hundred creatives had become two hundred extra round trips.
         *
         * `loadMissing` rather than `load`: the caller may already have eager-loaded the relation,
         * and re-loading it would throw the saving away for a second identical query.
         */
        $creatives->loadMissing('ads');

        /*
         * CONTENT-AD-DELIVERED-001 — did this creative's AD run, even though the creative has no
         * figures of its own?
         *
         * Production has 35 creatives in exactly that state: the platform reports the ad and never
         * names the creative. Without this the card falls to «لم يعمل خلال هذه الفترة», which is a
         * false statement about a creative that was live — and false in the expensive direction,
         * because an operator reads it and leaves a running creative alone.
         *
         * One query for the page, not one per row. It answers a question about the AD and is
         * reported as such: no ad figure is copied onto the creative, and nothing here reaches the
         * KPI grid. It only decides WHICH SENTENCE an empty card gets.
         */
        $adDelivered = array_fill_keys(
            DB::table('external_ads')
                ->whereIn('creative_id', $ids)
                ->whereIn('id', function ($sub) use ($from, $to): void {
                    $sub->select('entity_id')
                        ->from('entity_daily_metrics')
                        ->where('entity_type', 'ad')
                        ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()]);
                })
                ->distinct()
                ->pluck('creative_id')
                ->map(static fn (mixed $v): string => (string) $v)
                ->all(),
            true,
        );

        $out = [];
        foreach ($creatives as $creative) {
            $id = (string) $creative->getKey();
            $campaign = $creative->campaign_id === null ? null : $campaigns->get($creative->campaign_id);
            $objective = $campaign?->objective;

            $row = $this->presenter->card($creative, $campaign);
            $row['objective'] = $objective;
            $row['path'] = $this->metrics->pathFor($objective)->value;
            /*
             * CONTENT-KPI-AVAILABILITY-001 — the row's OWN figures decide which of the family's
             * metrics the card promises. A sales creative whose platform reports no revenue at
             * creative grain is not helped by a cell reserved for revenue.
             */
            $row['headline_metrics'] = $this->metrics->headline($objective, $figures[$id] ?? null);
            $row['metrics'] = $figures[$id] ?? null;
            // A fact about the AD, named as one — see CONTENT-AD-DELIVERED-001.
            $row['ad_delivered'] = isset($adDelivered[$id]);

            if ($withFatigue) {
                $row['fatigue'] = $this->fatigue->assess($figures[$id] ?? ['active_days' => 0], $previous[$id] ?? null);
            }

            /*
             * The previous period's figures, on the row, for the dashboard and the report only.
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

    /**
     * The external ids of every ad reachable from these creatives — CREATIVE-AD-RELATION-001.
     *
     * Read from `external_ads` through the real relation rather than from
     * `external_creatives.external_ad_id`, which holds one ad per creative and so offered a list
     * that was really a count of creatives: 1,451 values on the live Snapchat account where 5,706
     * ads exist, with every ad but the last of each creative unselectable.
     *
     * @param  Closure(): Builder  $base  a fresh bounded query, same contract as `filterOptions`
     * @return list<array{value:string,label:string}> the ad ids to filter by, each with the name a person reads
     */
    private function adExternalIds(Closure $base): array
    {
        $creativeIds = $base()->distinct()->pluck('id')->all();

        if ($creativeIds === []) {
            return [];
        }

        /*
         * CREATIVE-FRONTEND-ADS-001 — an ad filter that offers ids is not a control.
         *
         * This returned bare `external_id` strings and the select rendered them as their own
         * labels, so narrowing the library by ad meant choosing between a column of provider ids
         * that say nothing about which ad they are. The NAME is what somebody recognises; the id is
         * what the filter must send, and both fit in one option.
         *
         * The id remains the value, deliberately: names are not unique and a name is not an
         * address. An ad the platform left unnamed falls back to its id rather than to an empty row.
         */
        return ExternalAd::query()
            ->whereIn('creative_id', $creativeIds)
            ->orderBy('external_id')
            ->get(['external_id', 'name'])
            ->unique('external_id')
            ->map(static fn (ExternalAd $ad): array => [
                'value' => (string) $ad->external_id,
                'label' => $ad->name !== null && $ad->name !== ''
                    ? (string) $ad->name
                    : (string) $ad->external_id,
            ])
            ->values()
            ->all();
    }

    /**
     * What the filter controls may offer — derived from the rows in reach, never from a fixed list.
     *
     * A select populated from an enum offers platforms this project has never run and campaigns it
     * does not have: every one of those is a control that returns an empty list, which reads as «no
     * data» rather than «nothing was ever going to match». The two exceptions are deliberate —
     * marketing paths and fatigue states are a closed vocabulary this system defines, not a
     * reflection of what happens to be synced, and an operator needs to see «Fatigued» in the list to
     * know the concept exists even in a week when nothing is.
     *
     * @param  Closure(): Builder  $base  a FRESH bounded query each call — the options are read with
     *                                    several independent aggregates, and reusing one builder
     *                                    would accumulate their clauses onto each other.
     * @return array<string, mixed>
     */
    public function filterOptions(Closure $base): array
    {
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
            /*
             * CREATIVE-AD-RELATION-001 — the ads themselves, not `distinct('external_ad_id')`.
             *
             * That column carries one ad per creative, so the option list was really a count of
             * CREATIVES wearing an ad's name: on the live Snapchat account it offered 1,451 values
             * where 5,706 ads exist, and every ad but the last of each creative was unselectable.
             */
            'ads' => $this->adExternalIds($base),
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

    /** A bare creative query — the model's own tenant scope and nothing else yet. */
    public function query(): Builder
    {
        return ExternalCreative::query();
    }
}
