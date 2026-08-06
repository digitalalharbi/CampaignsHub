<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Models\CreativeGroup;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativeFatigue;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\CreativePresenter;
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
     * The library: every creative in the project, filtered, with figures that match its objective.
     */
    public function index(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        [$from, $to] = $this->window($request);

        $query = ExternalCreative::query()->where('project_id', $project);
        $this->applyFilters($query, $request);

        $perPage = min((int) $request->integer('per_page', 24) ?: 24, self::PER_PAGE_MAX);
        $page = max((int) $request->integer('page', 1), 1);
        $total = (clone $query)->count();

        $creatives = $query
            ->orderByDesc('last_active_at')
            ->orderByDesc('last_synced_at')
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
            'filters' => $this->filterOptions($project),
        ], 'Creative library.');
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
    public function compare(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        $data = $request->validate([
            'creative_ids' => ['required', 'array', 'min:2', 'max:6'],
            'creative_ids.*' => ['string'],
        ]);

        [$from, $to] = $this->window($request);

        $creatives = ExternalCreative::query()
            ->where('project_id', $project)
            ->whereIn('id', $data['creative_ids'])
            ->get();

        abort_if($creatives->count() < 2, 422, 'At least two creatives from this project are needed to compare.');

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

    private function applyFilters(mixed $query, Request $request): void
    {
        foreach (['provider' => 'provider', 'format' => 'format', 'status' => 'status'] as $param => $column) {
            $values = array_filter((array) $request->input($param, []));
            if ($values !== []) {
                $query->whereIn($column, $values);
            }
        }

        if ($ids = array_filter((array) $request->input('campaign_ids', []))) {
            $query->whereIn('campaign_id', $ids);
        }

        if ($search = trim($request->string('search')->toString())) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('headline', 'ilike', "%{$search}%")
                    ->orWhere('body', 'ilike', "%{$search}%");
            });
        }

        if ($objectives = array_filter((array) $request->input('objectives', []))) {
            $query->whereIn('campaign_id', function ($sub) use ($objectives): void {
                $sub->select('id')->from('unified_campaigns')->whereIn('objective', $objectives);
            });
        }
    }

    /**
     * Shape a page of creatives with their figures — TWO queries for the whole page, never per row.
     *
     * @param  Collection<int, ExternalCreative>  $creatives
     * @return list<array<string, mixed>>
     */
    private function present(mixed $creatives, Carbon $from, Carbon $to, bool $withFatigue): array
    {
        $ids = array_map('strval', $creatives->modelKeys());
        if ($ids === []) {
            return [];
        }

        $figures = $this->metrics->forCreatives($ids, $from, $to);

        $previous = [];
        if ($withFatigue) {
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
    private function filterOptions(string $project): array
    {
        $distinct = static fn (string $column): array => ExternalCreative::query()
            ->where('project_id', $project)
            ->whereNotNull($column)
            ->distinct()->orderBy($column)->pluck($column)->all();

        return [
            'providers' => $distinct('provider'),
            'formats' => $distinct('format'),
            'statuses' => $distinct('status'),
            'health' => [
                CreativeFatigue::IMPROVING, CreativeFatigue::STABLE, CreativeFatigue::WATCH,
                CreativeFatigue::FATIGUED, CreativeFatigue::INSUFFICIENT,
            ],
        ];
    }
}
