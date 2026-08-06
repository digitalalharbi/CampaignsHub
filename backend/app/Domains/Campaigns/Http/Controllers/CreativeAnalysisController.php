<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Models\CreativeGroup;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativeFatigue;
use App\Domains\Campaigns\Services\CreativeInsights;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Campaigns\Services\CreativePulse;
use App\Domains\Campaigns\Services\CreativeRows;
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
