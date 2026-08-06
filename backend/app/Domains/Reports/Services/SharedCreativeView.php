<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativeFatigue;
use App\Domains\Campaigns\Services\CreativeFunnel;
use App\Domains\Campaigns\Services\CreativeInsights;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Campaigns\Services\CreativePulse;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Support\CreativeVisibility;
use App\Domains\Reports\Support\ReportScope;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * §15.12 — the content sections of a client's report, on the library's own selection.
 *
 * ## There is no user on this request
 *
 * Every other creative surface is protected by the signed-in operator's membership. This one is
 * reachable with nothing but a token, so the ceiling comes from the SHARE row — written by an
 * authenticated operator when the link was made — and is entered explicitly: the tenant, the
 * project, the campaigns, the platforms, the named creatives and groups, the exclusions, the
 * objectives, the paths and the window.
 *
 * Everything the reader sends is INTERSECTED with that ceiling by {@see narrow()}. There is no path
 * through this class by which a query string widens anything. That matters more here than anywhere
 * else in the product: a mistake on an operator's page is visible to one company, and a mistake here
 * is visible to whoever was sent the link.
 *
 * ## It is the same selection the operator sees
 *
 * The rows come from {@see CreativeRows}, the rankings from {@see CreativePulse} and the findings
 * from {@see CreativeInsights} — the same three the dashboard uses, over the same figures. §15.12
 * forbids a second aggregation for the report, and the way to obey that is not to be careful; it is
 * to have no query of one's own. What this class adds is a ceiling and a redaction, and nothing that
 * computes a number.
 *
 * ## Redaction happens on the way out, at one place per shape
 *
 * A hidden figure is removed from the PAYLOAD, not hidden by the page. The distinction is the whole
 * feature: a client who opens the network tab must not find the spend their agency chose not to
 * show them, and an export must not carry it either.
 */
final class SharedCreativeView
{
    /** What a reader may narrow by. Anything else in the query string is ignored, not honoured. */
    private const NARROWABLE = ['from', 'to', 'providers', 'campaign_ids', 'objectives', 'paths', 'kinds', 'search', 'sort', 'page', 'per_page'];

    private const PER_PAGE_MAX = 48;

    public function __construct(
        private readonly CreativeRows $rows,
        private readonly CreativePulse $pulse,
        private readonly CreativeInsights $insights,
        private readonly CreativePresenter $presenter,
        private readonly CreativeMetrics $metrics,
        private readonly CreativeFatigue $fatigue,
        private readonly CreativeFunnel $funnel,
        private readonly TenantContext $tenants,
        private readonly ProjectContext $projects,
    ) {}

    /**
     * The creative library, inside the link's ceiling — the DETAILED report's section.
     *
     * @param  array<string, mixed>  $requested
     * @return array<string, mixed>
     */
    public function library(ReportShare $share, array $requested): array
    {
        $visibility = $share->creativeVisibility();
        $applied = $this->enter($share, $requested);

        [$from, $to] = [Carbon::parse($applied['from']), Carbon::parse($applied['to'])];

        $query = $this->bounded($share, $applied);

        $perPage = min(max((int) ($requested['per_page'] ?? 24), 1), self::PER_PAGE_MAX);
        $page = max((int) ($requested['page'] ?? 1), 1);
        $total = (clone $query)->count();

        $creatives = $this->rows->applySort($query, (string) ($applied['sort'] ?? ''), $from, $to)
            ->forPage($page, $perPage)
            ->get();

        $rows = $this->rows->present($creatives, $from, $to, withFatigue: true, withPrevious: true);

        return [
            'creatives' => array_map(fn (array $row): array => $this->redactRow($row, $visibility), $rows),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'period' => ['from' => $applied['from'], 'to' => $applied['to']],
            'applied' => $applied,
            'available' => $this->available($share),
            'permissions' => $visibility->toArray(),
        ];
    }

    /**
     * The rankings, the states and the findings — the EXECUTIVE summary's section.
     *
     * Every creative inside the ceiling is read, not a page of them: a «best video» computed from the
     * first twenty-four rows is a best video of a page, and the reader has no way to know that.
     *
     * @param  array<string, mixed>  $requested
     * @return array<string, mixed>
     */
    public function summary(ReportShare $share, array $requested): array
    {
        $visibility = $share->creativeVisibility();
        $applied = $this->enter($share, $requested);

        [$from, $to] = [Carbon::parse($applied['from']), Carbon::parse($applied['to'])];

        $rows = $this->rows->present(
            $this->bounded($share, $applied)->get(),
            $from,
            $to,
            withFatigue: true,
            withPrevious: true,
        );

        $payload = $this->pulse->build($rows, $from, $to);

        if ($visibility->insights) {
            $payload['insights'] = $this->redactInsights($this->insights->build($rows, $from, $to), $visibility);
        }

        return $this->redactSummary($payload, $visibility) + [
            'applied' => $applied,
            'available' => $this->available($share),
            'permissions' => $visibility->toArray(),
        ];
    }

    /**
     * One creative, in depth — refused for anything the link does not carry.
     *
     * The refusal is the point of the method. A creative excluded from a link must not open by URL,
     * by id, by group id, or by any other address; so the lookup runs through the SAME bounded query
     * the list does rather than fetching by primary key and checking afterwards. A check after the
     * fetch is a check somebody removes.
     *
     * @param  array<string, mixed>  $requested
     * @return array<string, mixed>|null null when this link may not show this creative
     */
    public function detail(ReportShare $share, string $creativeId, array $requested): ?array
    {
        $visibility = $share->creativeVisibility();

        if (! $visibility->creatives) {
            return null;
        }

        $applied = $this->enter($share, $requested);
        [$from, $to] = [Carbon::parse($applied['from']), Carbon::parse($applied['to'])];

        $model = $this->bounded($share, $applied)->whereKey($creativeId)->first();

        if ($model === null) {
            return null;
        }

        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        $id = (string) $model->getKey();
        $current = $this->metrics->forCreatives([$id], $from, $to)[$id] ?? null;
        $previous = $this->metrics->forCreatives([$id], $prevFrom, $prevTo)[$id] ?? null;

        $campaign = $model->campaign_id === null ? null : UnifiedCampaign::query()->find($model->campaign_id);
        $objective = $campaign?->objective;

        $detail = $this->presenter->detail($model, $campaign);
        $detail['objective'] = $objective;
        $detail['path'] = $this->metrics->pathFor($objective)->value;
        $detail['headline_metrics'] = $this->metrics->headline($objective);
        $detail['metrics'] = $current;
        $detail['previous'] = $previous;
        $detail['fatigue'] = $this->fatigue->assess($current ?? ['active_days' => 0], $previous);

        return [
            'creative' => $this->redactRow($detail, $visibility),
            'period' => ['from' => $applied['from'], 'to' => $applied['to'], 'days' => $days],
            'previous_period' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            // The same reshaping of the same figures the operator's page shows — with the per-stage
            // cost removed when the link withholds spend, since a cost per step IS spend divided.
            'funnel' => $this->redactFunnel($this->funnel->build($current), $visibility),
            'trend' => $this->trend($id, $from, $to, $visibility),
            'by_platform' => $this->byPlatform($share, $model, $from, $to, $visibility),
            'by_campaign' => $this->byCampaign($model, $from, $to, $visibility),
            /*
             * REPORT-OBJECTIVE-005 — where every one of these figures came from.
             *
             * A creative's numbers are what the ad platform reported about its own delivery. Only a
             * store-confirmed order can claim otherwise, and none of these are that, so the label is
             * fixed rather than computed — a field that sometimes said «store confirmed» because a
             * join happened to succeed would be worse than no field at all.
             */
            'attribution' => [
                'source' => 'platform_reported',
                'note_ar' => 'الأرقام كما أبلغت عنها المنصة الإعلانية نفسها، ضمن نافذة العزو المعتمدة لديها.',
                'note_en' => 'Figures as the ad platform reported them, inside its own attribution window.',
            ],
            'permissions' => $visibility->toArray(),
        ];
    }

    /**
     * Two or more creatives side by side, and never an overall winner across marketing paths.
     *
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $requested
     * @return array<string, mixed>|null null when comparison is not permitted on this link
     */
    public function compare(ReportShare $share, array $ids, array $requested): ?array
    {
        $visibility = $share->creativeVisibility();

        if (! $visibility->creatives || ! $visibility->comparison) {
            return null;
        }

        $applied = $this->enter($share, $requested);
        [$from, $to] = [Carbon::parse($applied['from']), Carbon::parse($applied['to'])];

        // Bounded first, THEN filtered to what was asked for: an id outside the ceiling drops out
        // here rather than being trusted because the reader named it.
        $creatives = $this->bounded($share, $applied)->whereIn('id', $ids)->get();

        if ($creatives->count() < 2) {
            return null;
        }

        $rows = $this->rows->present($creatives, $from, $to, withFatigue: false, withPrevious: true);

        $objectives = array_values(array_unique(array_map(
            static fn (array $r): ?string => $r['objective'],
            $rows,
        )));

        $verdict = count($objectives) <= 1
            ? ['comparable' => true, 'reason' => null, 'reason_ar' => null]
            : $this->metrics->comparable($objectives[0], $objectives[1]);

        return [
            'creatives' => array_map(fn (array $row): array => $this->redactRow($row, $visibility), $rows),
            'period' => ['from' => $applied['from'], 'to' => $applied['to']],
            'comparable' => $verdict['comparable'],
            'reason' => $verdict['reason'],
            'reason_ar' => $verdict['reason_ar'],
            'permissions' => $visibility->toArray(),
        ];
    }

    // ---- the ceiling --------------------------------------------------------------------------

    /**
     * Enter the share's tenant and project, and work out what this request actually resolves to.
     *
     * The two happen together because neither is safe alone: entering a tenant without narrowing the
     * request would leave the query open inside it, and narrowing without entering would run against
     * whatever context the process happened to be holding.
     *
     * @param  array<string, mixed>  $requested
     * @return array<string, mixed>
     */
    private function enter(ReportShare $share, array $requested): array
    {
        $ceiling = $this->ceiling($share);

        $this->tenants->setTenantId((string) $share->tenant_id);
        $this->projects->setProjectId($ceiling['project_id']);

        $requested = array_intersect_key($requested, array_flip(self::NARROWABLE));

        $earliest = Carbon::parse($ceiling['earliest'])->startOfDay();
        $latest = Carbon::parse($ceiling['latest'])->startOfDay();

        $from = $this->clamp($requested['from'] ?? null, $earliest, $latest, $earliest);
        $to = $this->clamp($requested['to'] ?? null, $earliest, $latest, $latest);
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'providers' => $this->narrow($requested['providers'] ?? null, $ceiling['providers']),
            'campaign_ids' => $this->narrow($requested['campaign_ids'] ?? null, $ceiling['campaign_ids']),
            'objectives' => $this->narrow($requested['objectives'] ?? null, $ceiling['objectives']),
            'paths' => $this->narrow($requested['paths'] ?? null, $ceiling['paths']),
            // Creative TYPE is not a ceiling axis — it is a way of reading the same content, and an
            // operator who shared a video has shared it whether or not the reader ticks «video».
            'kinds' => array_values(array_filter((array) ($requested['kinds'] ?? []))),
            'search' => trim((string) ($requested['search'] ?? '')),
            'sort' => (string) ($requested['sort'] ?? ''),
        ];
    }

    /**
     * The query every read here starts from — the ceiling, then the reader's narrowing.
     *
     * The named creatives, the named groups and the exclusions are applied from the SHARE and are
     * never taken from `$applied`, because there is no client-facing control for them. A bound the
     * reader cannot set is a bound a tampered URL cannot loosen either.
     *
     * @param  array<string, mixed>  $applied
     */
    private function bounded(ReportShare $share, array $applied): Builder
    {
        $ceiling = $this->ceiling($share);

        $query = ExternalCreative::query();

        /*
         * The project bound, applied even though the tenant scope is already on the model.
         *
         * A ceiling with no project is a link to a whole tenant's content, so an empty value here
         * matches NOTHING rather than everything — the same choice `LiveReportService` makes, for
         * the same reason: a visibly empty link gets reported, and a silently wide one does not.
         */
        $query->where('project_id', $ceiling['project_id'] === '' ? ReportScope::IMPOSSIBLE : $ceiling['project_id']);

        $this->rows->applyFilters($query, [
            'providers' => $applied['providers'],
            'campaign_ids' => $applied['campaign_ids'],
            'objectives' => $applied['objectives'],
            'paths' => $applied['paths'],
            'kinds' => $applied['kinds'],
            'search' => $applied['search'],
            'creative_ids' => $ceiling['creative_ids'],
            'creative_group_ids' => $ceiling['creative_group_ids'],
            'excluded_creative_ids' => $ceiling['excluded_creative_ids'],
        ]);

        return $query;
    }

    /**
     * The ceiling, normalised, read defensively.
     *
     * This is JSON written by an earlier version of the app, so a missing key must degrade to
     * «nothing» wherever a value would otherwise widen — and to «no bound» only where an empty axis
     * genuinely means the operator declined to narrow it.
     *
     * @return array<string, mixed>
     */
    private function ceiling(ReportShare $share): array
    {
        $scope = (array) ($share->scope ?? []);
        $list = static fn (string $key): array => array_values(array_filter(array_map(
            static fn ($v): string => is_scalar($v) ? trim((string) $v) : '',
            (array) ($scope[$key] ?? []),
        ), static fn (string $v): bool => $v !== ''));

        return [
            'project_id' => (string) ($scope['project_id'] ?? ''),
            'campaign_ids' => $list('campaign_ids'),
            'providers' => $list('providers'),
            'objectives' => $list('objectives'),
            'paths' => $list('paths'),
            'creative_ids' => $list('creative_ids'),
            'creative_group_ids' => $list('creative_group_ids'),
            'excluded_creative_ids' => $list('excluded_creative_ids'),
            'earliest' => (string) ($scope['earliest'] ?? Carbon::now()->subDays(30)->toDateString()),
            'latest' => (string) ($scope['latest'] ?? Carbon::now()->toDateString()),
        ];
    }

    /**
     * The filter values this link may offer — derived from what is actually inside its ceiling.
     *
     * Not from the ceiling's own lists: a share naming eight campaigns of which two have creatives
     * would offer six controls that return nothing. And not from an enum, which would offer
     * platforms this client has never been on.
     *
     * @return array<string, mixed>
     */
    private function available(ReportShare $share): array
    {
        $ceiling = $this->ceiling($share);

        $base = fn (): Builder => $this->bounded($share, [
            'providers' => [], 'campaign_ids' => [], 'objectives' => [], 'paths' => [],
            'kinds' => [], 'search' => '',
        ]);

        $options = $this->rows->filterOptions($base);

        return [
            'providers' => $options['providers'],
            'campaigns' => $options['campaigns'],
            'objectives' => $options['objectives'],
            'paths' => $options['paths'],
            'kinds' => $options['kinds'],
            'earliest' => $ceiling['earliest'],
            'latest' => $ceiling['latest'],
        ];
    }

    /**
     * The requested members the ceiling actually contains.
     *
     * An empty request means «the whole ceiling»; a request of only forbidden members lands here too,
     * so the failure mode of a tampered URL is the link's normal view rather than somebody else's
     * data.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function narrow(mixed $requested, array $allowed): array
    {
        if (! is_array($requested) || $requested === []) {
            return $allowed;
        }

        $asked = array_values(array_filter(array_map(strval(...), $requested)));

        if ($allowed === []) {
            return $asked;   // no bound on this axis — the reader's own filter stands alone.
        }

        $both = array_values(array_intersect($asked, $allowed));

        // Asked for things, none of them granted → the ceiling, never «no bound».
        return $both === [] ? $allowed : $both;
    }

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

    // ---- redaction ----------------------------------------------------------------------------

    /**
     * One creative row, with everything this link withholds actually removed.
     *
     * Removed rather than blanked where the field would otherwise announce itself: a `headline` key
     * holding an empty string tells the reader a headline exists and is being kept from them, which
     * is a slightly different disclosure and a much more irritating page.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function redactRow(array $row, CreativeVisibility $visibility): array
    {
        foreach ($visibility->hiddenMetrics() as $metric) {
            if (isset($row['metrics']) && is_array($row['metrics'])) {
                unset($row['metrics'][$metric], $row['metrics']['reported'][$metric]);
            }
            if (isset($row['previous']) && is_array($row['previous'])) {
                unset($row['previous'][$metric], $row['previous']['reported'][$metric]);
            }
        }

        /*
         * The headline metric LIST is filtered too, not only the figures.
         *
         * The card renders whatever this list names, so leaving `roas` in it after removing the ROAS
         * figure produces a labelled empty slot — the reader is told exactly which number is being
         * withheld, which is not what the operator asked for when they hid it.
         */
        if (isset($row['headline_metrics']) && is_array($row['headline_metrics'])) {
            $row['headline_metrics'] = array_values(array_filter(
                $row['headline_metrics'],
                static fn ($m): bool => ! $visibility->hides((string) $m),
            ));
        }

        if (isset($row['copy']) && is_array($row['copy'])) {
            if (! $visibility->adCopy) {
                unset($row['copy']['body'], $row['copy']['description']);
            }
            if (! $visibility->headline) {
                unset($row['copy']['headline']);
            }
            if (! $visibility->cta) {
                unset($row['copy']['cta']);
            }
            if ($row['copy'] === []) {
                unset($row['copy']);
            }
        }

        if (! $visibility->destinationUrl) {
            unset($row['destination_url']);
        }

        // Platform record ids are internal plumbing; a client link has no use for them and they are
        // the kind of value that ends up quoted in a support ticket as though it meant something.
        unset($row['external_ids']);

        if (isset($row['preview']) && is_array($row['preview'])) {
            if (! $visibility->video) {
                // The player is fed from this key. Removing it is what makes «no video» true in the
                // payload rather than true only in the component that chose not to render one.
                $row['preview']['video_url'] = null;
            }

            /*
             * `can_zoom` and `can_download` are affordances, and they are described honestly.
             *
             * Turning zoom off does not make the image secret — a reader can save any picture their
             * browser has drawn, here as on every website. What these do is remove the controls and,
             * for download, the URL that fed the download button. The bound that actually withholds a
             * creative is EXCLUDING it from the link, which is a ceiling decision, not a flag.
             */
            $row['preview']['can_zoom'] = $visibility->imageZoom;
            $row['preview']['can_download'] = $visibility->download;

            $row['preview'] = $this->redactCards($row['preview'], $visibility);
        }

        return $row;
    }

    /**
     * A carousel's cards obey the same switches as the creative's own copy (§15.12).
     *
     * Each card carries its own headline, body, call to action and destination — the same four things
     * the link can withhold one level up. A link that hides the ad copy and then ships four cards each
     * holding a headline has not hidden the ad copy; it has moved it. The video switch applies here
     * too, because a video card is a video.
     *
     * Keys are REMOVED, never blanked, for the reason `redactRow` gives above — and the cards
     * themselves are never emptied out, because the pictures are what a carousel IS and hiding those
     * is a decision made by excluding the creative from the link, not by a copy switch.
     *
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function redactCards(array $preview, CreativeVisibility $visibility): array
    {
        if (! is_array($preview['cards'] ?? null)) {
            return $preview;
        }

        $preview['cards'] = array_map(static function (array $card) use ($visibility): array {
            if (! $visibility->adCopy) {
                unset($card['body']);
            }
            if (! $visibility->headline) {
                unset($card['headline']);
            }
            if (! $visibility->cta) {
                unset($card['cta']);
            }
            if (! $visibility->destinationUrl) {
                unset($card['destination_url']);
            }
            if (! $visibility->video) {
                $card['video_url'] = null;
            }

            return $card;
        }, $preview['cards']);

        return $preview;
    }

    /**
     * The summary payload, with hidden figures gone from the rankings as well as from the cards.
     *
     * The winner of a ranking computed on a hidden metric is still named — «your best awareness
     * creative, judged on CPM» is a useful sentence without the CPM — but the VALUE goes, and the
     * card is told the value went, so it renders the ranking rather than a blank number.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactSummary(array $payload, CreativeVisibility $visibility): array
    {
        $winner = function (array $entry) use ($visibility): array {
            if (isset($entry['creative']) && is_array($entry['creative'])) {
                $entry['creative'] = $this->redactRow($entry['creative'], $visibility);
            }
            if (isset($entry['metric']) && $visibility->hides((string) $entry['metric'])) {
                $entry['value'] = null;
                $entry['value_hidden'] = true;
            }
            if (! $visibility->spend) {
                $entry['spend'] = null;
            }

            return $entry;
        };

        foreach (['best_by_objective', 'best_image', 'best_video'] as $section) {
            if (is_array($payload[$section] ?? null)) {
                $payload[$section] = array_map($winner, $payload[$section]);
            }
        }

        foreach (['fastest_growing', 'declining'] as $section) {
            if (is_array($payload[$section]['items'] ?? null)) {
                $payload[$section]['items'] = array_map(function (array $move) use ($visibility): array {
                    $move['creative'] = $this->redactRow($move['creative'], $visibility);
                    if ($visibility->hides((string) ($move['metric'] ?? ''))) {
                        $move['current'] = null;
                        $move['previous'] = null;
                        $move['value_hidden'] = true;
                    }

                    return $move;
                }, $payload[$section]['items']);
            }
        }

        foreach (['fatigued', 'watch', 'insufficient_data'] as $bucket) {
            if (is_array($payload['fatigue'][$bucket]['items'] ?? null)) {
                $payload['fatigue'][$bucket]['items'] = array_map(
                    fn (array $row): array => $this->redactRow($row, $visibility),
                    $payload['fatigue'][$bucket]['items'],
                );
            }
        }

        if (is_array($payload['fatigue']['alerts']['items'] ?? null)) {
            $payload['fatigue']['alerts']['items'] = array_map(function (array $alert) use ($visibility): array {
                $alert['creative'] = $this->redactRow($alert['creative'], $visibility);
                if (! $visibility->spend) {
                    $alert['spend'] = null;
                }

                return $alert;
            }, $payload['fatigue']['alerts']['items']);
        }

        if (! $visibility->spend) {
            $payload['fatigue']['spend_at_risk'] = null;

            // A spend SPLIT is spend. Shares would let a reader reconstruct the proportions of a
            // total they were not shown, which is most of what the total was hiding.
            if (is_array($payload['spend_by_kind'] ?? null)) {
                $payload['spend_by_kind'] = array_map(static function (array $kind): array {
                    $kind['spend'] = null;
                    $kind['share'] = null;

                    return $kind;
                }, $payload['spend_by_kind']);
            }
        }

        if (is_array($payload['image_vs_video'] ?? null)) {
            $payload['image_vs_video'] = array_map(function (array $comparison) use ($visibility): array {
                foreach (['image', 'video'] as $side) {
                    if (is_array($comparison[$side] ?? null)) {
                        foreach ($visibility->hiddenMetrics() as $metric) {
                            unset($comparison[$side][$metric], $comparison[$side]['reported'][$metric]);
                        }
                    }
                }
                $comparison['headline_metrics'] = array_values(array_filter(
                    (array) ($comparison['headline_metrics'] ?? []),
                    static fn ($m): bool => ! $visibility->hides((string) $m),
                ));

                return $comparison;
            }, $payload['image_vs_video']);
        }

        if (is_array($payload['best_platform']['items'] ?? null)) {
            $payload['best_platform']['items'] = array_map(function (array $entry) use ($visibility): array {
                $hidden = $visibility->hides((string) ($entry['metric'] ?? ''));
                $entry['platforms'] = array_map(static function (array $platform) use ($hidden, $visibility): array {
                    if ($hidden) {
                        $platform['value'] = null;
                    }
                    if (! $visibility->spend) {
                        $platform['spend'] = null;
                    }

                    return $platform;
                }, $entry['platforms']);
                $entry['value_hidden'] = $hidden;

                return $entry;
            }, $payload['best_platform']['items']);
        }

        return $payload;
    }

    /**
     * Insights, with the withheld figures gone and — when recommendations are off — the actions too.
     *
     * An insight whose supporting metric is hidden is DROPPED rather than shown without its evidence.
     * «Your cost per order rose» with no figures is an assertion a client cannot check, and a report
     * that makes uncheckable assertions is worse than one that says less.
     *
     * @param  array<string, mixed>  $insights
     * @return array<string, mixed>
     */
    private function redactInsights(array $insights, CreativeVisibility $visibility): array
    {
        $items = [];

        foreach ((array) ($insights['items'] ?? []) as $item) {
            $metric = (string) ($item['movement']['metric'] ?? '');
            $supporting = array_keys((array) ($item['supporting_metrics'] ?? []));

            if ($metric !== '' && $visibility->hides($metric)) {
                continue;
            }
            if (array_filter($supporting, static fn ($m): bool => $visibility->hides((string) $m)) !== []) {
                continue;
            }

            if (! $visibility->recommendations) {
                unset($item['action_ar'], $item['action_en']);
            }
            if (! $visibility->spend) {
                $item['spend'] = null;
            }

            $items[] = $item;
        }

        $insights['items'] = $items;
        $insights['shown'] = count($items);
        // `total` keeps its honest value: the reader is told six of eleven, not six of six.

        return $insights;
    }

    // ---- detail sections ----------------------------------------------------------------------

    /**
     * The funnel, with the per-stage cost removed when the link withholds spend.
     *
     * A cost per checkout IS the spend, divided by a number printed next to it — leaving it in while
     * hiding the spend row would publish the budget to anyone willing to multiply. The stages, their
     * counts and their conversion rates all stay: they are the client's own funnel, and none of them
     * reconstructs a figure the link chose not to show.
     *
     * @param  array<string, mixed>  $funnel
     * @return array<string, mixed>
     */
    private function redactFunnel(array $funnel, CreativeVisibility $visibility): array
    {
        if ($visibility->spend) {
            return $funnel;
        }

        $funnel['stages'] = array_map(static function (array $stage): array {
            $stage['cost_per'] = null;
            // Flagged, not merely nulled: «not shown on this link» and «this platform sent no spend»
            // are different sentences, and the reader is owed the right one.
            $stage['cost_hidden'] = true;

            return $stage;
        }, $funnel['stages']);

        return $funnel;
    }

    /**
     * Daily figures for the trend chart, with hidden columns absent rather than zeroed.
     *
     * @return list<array<string, mixed>>
     */
    private function trend(string $creativeId, Carbon $from, Carbon $to, CreativeVisibility $visibility): array
    {
        return DB::table('creative_daily_metrics')
            ->where('creative_id', $creativeId)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('metric_date')
            ->get(['metric_date', 'spend', 'impressions', 'clicks', 'conversions', 'revenue', 'video_views', 'video_p100', 'frequency'])
            ->map(static function ($r) use ($visibility): array {
                $point = [
                    'date' => (string) $r->metric_date,
                    'impressions' => (float) $r->impressions,
                    'clicks' => (float) $r->clicks,
                    'conversions' => (float) $r->conversions,
                    // Nulls survive: a day the platform reported no video data is not a day of zero views.
                    'video_views' => $r->video_views === null ? null : (float) $r->video_views,
                    'video_p100' => $r->video_p100 === null ? null : (float) $r->video_p100,
                    'frequency' => $r->frequency === null ? null : (float) $r->frequency,
                ];

                if ($visibility->spend) {
                    $point['spend'] = (float) $r->spend;
                }
                if ($visibility->revenue) {
                    $point['revenue'] = (float) $r->revenue;
                }

                return $point;
            })->all();
    }

    /**
     * The same asset's figures per platform — and only across siblings the link also carries.
     *
     * A grouped creative can have a sibling on a platform this share never granted. Showing it here
     * would leak a platform the client was not told about, arriving through a section that looks like
     * it is about the creative they were shown.
     *
     * @return list<array<string, mixed>>
     */
    private function byPlatform(ReportShare $share, ExternalCreative $creative, Carbon $from, Carbon $to, CreativeVisibility $visibility): array
    {
        if ($creative->creative_group_id === null) {
            return [];
        }

        $siblings = $this->bounded($share, [
            'providers' => [], 'campaign_ids' => [], 'objectives' => [], 'paths' => [],
            'kinds' => [], 'search' => '',
        ])->where('creative_group_id', $creative->creative_group_id)->get();

        if ($siblings->count() < 2) {
            return [];
        }

        $figures = $this->metrics->forCreatives(array_map('strval', $siblings->modelKeys()), $from, $to);

        return $siblings->map(function (ExternalCreative $c) use ($figures, $visibility): array {
            $metrics = $figures[(string) $c->getKey()] ?? null;

            if (is_array($metrics)) {
                foreach ($visibility->hiddenMetrics() as $metric) {
                    unset($metrics[$metric], $metrics['reported'][$metric]);
                }
            }

            return [
                'creative_id' => (string) $c->getKey(),
                'provider' => $c->provider,
                'metrics' => $metrics,
                'source' => 'platform_reported',
            ];
        })->values()->all();
    }

    /**
     * How this creative did in each campaign that ran it, within the link's own campaign ceiling.
     *
     * @return list<array<string, mixed>>
     */
    private function byCampaign(ExternalCreative $creative, Carbon $from, Carbon $to, CreativeVisibility $visibility): array
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

        return $rows->map(static function ($r) use ($names, $visibility): array {
            $row = [
                'campaign_id' => $r->campaign_id === null ? null : (string) $r->campaign_id,
                'campaign_name' => $r->campaign_id === null ? null : ($names[$r->campaign_id] ?? null),
                'impressions' => (float) $r->impressions,
                'clicks' => (float) $r->clicks,
                'conversions' => (float) $r->conversions,
            ];

            if ($visibility->spend) {
                $row['spend'] = (float) $r->spend;
            }
            if ($visibility->revenue) {
                $row['revenue'] = (float) $r->revenue;
            }

            return $row;
        })->values()->all();
    }
}
