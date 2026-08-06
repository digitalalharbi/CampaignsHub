<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\ObjectivePerformance;
use Illuminate\Support\Carbon;

/**
 * What a report is ABOUT — one object, twelve axes, read by every surface that draws it (§14.5).
 *
 * ## Why this exists at all
 *
 * A report's scope was previously three things kept in three places: the project on the report row,
 * a campaign list on the share, and a date range re-derived from the period columns. Adding
 * platforms, accounts, ad sets, ads, creatives, objectives and marketing paths to that arrangement
 * would have meant seven more fields written in one place and read — or forgotten — in four others.
 * The generator, the live link, the export and the picker now read the SAME object, so a scope that
 * narrows a figure on one surface narrows it on all of them.
 *
 * ## The rule: a scope may only ever narrow
 *
 * `intersect()` is the only way two scopes combine, and it can never widen. An empty axis means «no
 * bound on this axis», which is the one case where the result is as wide as what it was given; a
 * non-empty axis intersected with a non-empty ceiling keeps what BOTH allow, and an intersection
 * that empties out is expressed as `IMPOSSIBLE` — a scope that matches nothing — never as «no bound»,
 * which is how a fail-closed filter turns into an open one.
 *
 * ## Honesty about grain
 *
 * The twelve axes are not all enforceable at the same depth, and pretending otherwise would be the
 * most convincing kind of lie this product could tell:
 *
 *   - **project, platform, account, campaign, objective, path, dates** bound `daily_metrics` directly.
 *     Every figure on the report narrows.
 *   - **creatives** bound `creative_daily_metrics`, which is where creative figures come from. The
 *     creative section narrows; the campaign-level totals do not, because a creative's spend is not
 *     stored in the campaign table.
 *   - **ad sets and ads** have NO metrics of their own in this system — the platforms' insights are
 *     ingested at campaign grain. Selecting them therefore narrows the CAMPAIGN set to the campaigns
 *     they belong to, and `explain()` says so in words. A spend figure presented as «this ad set's»
 *     when the number is the whole campaign's would be wrong by an unknown multiple, and the client
 *     has no way to catch it.
 *
 * `explain()` exists so the report can print that distinction beside the figures rather than leaving
 * the reader to assume the deepest selection was honoured at that depth.
 */
final class ReportScope
{
    /** The axes, in the order the picker shows them. Client and project come first: they are the frame. */
    public const AXES = [
        'client_ids', 'project_ids', 'providers', 'account_ids', 'campaign_ids',
        'ad_set_ids', 'ad_ids', 'creative_ids', 'objectives', 'paths', 'metrics',
    ];

    /** Axes that bound `daily_metrics` directly — every figure on the report narrows with these. */
    public const FIGURE_AXES = ['project_ids', 'providers', 'account_ids', 'campaign_ids', 'objectives', 'paths'];

    /** Axes that resolve UP to campaigns because no metric is stored at their grain. */
    public const RESOLVED_AXES = ['ad_set_ids', 'ad_ids'];

    /**
     * A campaign id that cannot exist, used when an intersection empties out.
     *
     * The alternative — leaving the axis empty — would read as «no bound on campaigns» and hand back
     * the whole project. An impossible id is the fail-closed spelling of «nothing matches», and it
     * keeps the column type valid on Postgres.
     */
    public const IMPOSSIBLE = '00000000-0000-0000-0000-000000000000';

    /**
     * @param  list<string>  $clientIds
     * @param  list<string>  $projectIds
     * @param  list<string>  $providers
     * @param  list<string>  $accountIds
     * @param  list<string>  $campaignIds
     * @param  list<string>  $adSetIds
     * @param  list<string>  $adIds
     * @param  list<string>  $creativeIds
     * @param  list<string>  $objectives
     * @param  list<string>  $paths
     * @param  list<string>  $metrics  the metrics to SHOW, in the order chosen; empty → the layout's own set
     */
    private function __construct(
        public readonly array $clientIds = [],
        public readonly array $projectIds = [],
        public readonly array $providers = [],
        public readonly array $accountIds = [],
        public readonly array $campaignIds = [],
        public readonly array $adSetIds = [],
        public readonly array $adIds = [],
        public readonly array $creativeIds = [],
        public readonly array $objectives = [],
        public readonly array $paths = [],
        public readonly array $metrics = [],
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {}

    /** An unbounded scope — every axis open. Only ever a STARTING point, never the result of narrowing. */
    public static function unbounded(): self
    {
        return new self;
    }

    /**
     * Build from stored or submitted input. Unknown keys are dropped, not honoured.
     *
     * Values are normalised but NOT checked against the database here: whether a campaign id exists,
     * and whether this operator may reach it, is an authorisation question that belongs to the
     * controller with the user in hand. This object's job is the shape and the narrowing.
     *
     * @param  array<string, mixed>|null  $input
     */
    public static function fromArray(?array $input): self
    {
        $input ??= [];
        $list = static fn (string $key): array => array_values(array_unique(array_filter(
            array_map(
                static fn ($v): string => is_scalar($v) ? trim((string) $v) : '',
                (array) ($input[$key] ?? []),
            ),
            static fn (string $v): bool => $v !== '',
        )));

        $date = static function (string $key) use ($input): ?string {
            $raw = $input[$key] ?? null;

            if (! is_string($raw) || trim($raw) === '') {
                return null;
            }

            try {
                return Carbon::parse($raw)->toDateString();
            } catch (\Throwable) {
                // An unparseable date is dropped rather than defaulted: a scope that silently invents
                // a window is a scope nobody can review.
                return null;
            }
        };

        return new self(
            clientIds: $list('client_ids'),
            projectIds: $list('project_ids'),
            providers: array_map('strtolower', $list('providers')),
            accountIds: $list('account_ids'),
            campaignIds: $list('campaign_ids'),
            adSetIds: $list('ad_set_ids'),
            adIds: $list('ad_ids'),
            creativeIds: $list('creative_ids'),
            objectives: array_values(array_intersect($list('objectives'), CampaignObjective::values())),
            paths: array_values(array_intersect($list('paths'), MarketingPath::values())),
            metrics: array_values(array_intersect($list('metrics'), MetricsAggregator::readKeys() + self::derivedMetricKeys())),
            from: $date('from'),
            to: $date('to'),
        );
    }

    /** Derived KPIs a reader may choose to show, which are computed rather than stored. */
    private static function derivedMetricKeys(): array
    {
        return ['ctr', 'cpc', 'cpm', 'cpa', 'roas', 'aov', 'conversion_rate', 'frequency', 'reach'];
    }

    /** @return array<string, mixed> the stored shape — empty axes omitted, so a stored scope reads as what it bounds */
    public function toArray(): array
    {
        $out = [];
        foreach (self::AXES as $axis) {
            $value = $this->{self::property($axis)};
            if ($value !== []) {
                $out[$axis] = $value;
            }
        }

        if ($this->from !== null) {
            $out['from'] = $this->from;
        }
        if ($this->to !== null) {
            $out['to'] = $this->to;
        }

        return $out;
    }

    public function isUnbounded(): bool
    {
        return $this->toArray() === [];
    }

    /** @return list<string> the axes this scope actually bounds, for the report's own «what this covers» line */
    public function boundAxes(): array
    {
        return array_values(array_filter(
            self::AXES,
            fn (string $axis): bool => $this->{self::property($axis)} !== [],
        ));
    }

    /**
     * Narrow this scope by another. Never a union, never a replacement — see the class note.
     *
     * Dates CLAMP rather than empty out, which is the same choice `LiveReportService` already makes:
     * a reader dragging a picker past the window should meet the window's edge, not a report that
     * matches nothing. Sets INTERSECT, and an intersection that empties becomes IMPOSSIBLE.
     */
    public function intersect(self $ceiling): self
    {
        $narrow = static function (array $mine, array $theirs): array {
            if ($theirs === []) {
                return $mine;      // no ceiling on this axis
            }
            if ($mine === []) {
                return $theirs;    // no request on this axis → the ceiling itself
            }

            $both = array_values(array_intersect($mine, $theirs));

            // Asked for things, none of them granted → nothing, expressed so it cannot be read as «all».
            return $both === [] ? [self::IMPOSSIBLE] : $both;
        };

        $from = self::laterOf($this->from, $ceiling->from);
        $to = self::earlierOf($this->to, $ceiling->to);

        // A window narrowed past itself is empty, not inverted. Collapsing it to a single day would
        // invent a day nobody asked for; keeping `from > to` would let a downstream `whereBetween`
        // silently return everything on some drivers. Both bounds move to the ceiling's edge.
        if ($from !== null && $to !== null && $from > $to) {
            $from = $to;
        }

        return new self(
            clientIds: $narrow($this->clientIds, $ceiling->clientIds),
            projectIds: $narrow($this->projectIds, $ceiling->projectIds),
            providers: $narrow($this->providers, $ceiling->providers),
            accountIds: $narrow($this->accountIds, $ceiling->accountIds),
            campaignIds: $narrow($this->campaignIds, $ceiling->campaignIds),
            adSetIds: $narrow($this->adSetIds, $ceiling->adSetIds),
            adIds: $narrow($this->adIds, $ceiling->adIds),
            creativeIds: $narrow($this->creativeIds, $ceiling->creativeIds),
            objectives: $narrow($this->objectives, $ceiling->objectives),
            paths: $narrow($this->paths, $ceiling->paths),
            // Metrics are a DISPLAY choice, and the ceiling is the last word: a link told to hide
            // revenue must not show it because the reader asked nicely.
            metrics: $narrow($this->metrics, $ceiling->metrics),
            from: $from,
            to: $to,
        );
    }

    /**
     * Apply to the metrics engine, at the grain each axis can honestly reach.
     *
     * Ad sets and ads are resolved to their campaigns FIRST and intersected with any campaign
     * selection, so choosing an ad set narrows the figures to that ad set's campaign — which is what
     * the data supports — rather than being quietly ignored.
     */
    public function applyTo(MetricsAggregator $engine): MetricsAggregator
    {
        $campaigns = $this->resolvedCampaignIds();

        if ($this->projectIds !== []) {
            $engine = $engine->forProjects($this->projectIds);
        }
        if ($campaigns !== null) {
            $engine = $engine->forCampaigns($campaigns);
        }
        if ($this->providers !== []) {
            $engine = $engine->forProviders($this->providers);
        }
        if ($this->accountIds !== []) {
            $engine = $engine->forAccounts($this->accountIds);
        }
        if ($this->objectives !== [] || $this->paths !== []) {
            $engine = $engine->forObjectives($this->objectivesIncludingPaths());
        }

        return $engine;
    }

    /**
     * The objective-split service, bounded by the same scope as everything else on the report.
     *
     * Built here rather than by each caller because the split slide is the section a reader consults
     * when the headline number looks wrong: a report scoped to one platform whose Direct/Blended
     * block still counted the others would contradict its own KPI cards, and the reader would have no
     * way to tell which of the two was lying.
     *
     * The objective axis is deliberately NOT passed down. This service's whole job is to separate the
     * paths from one another and name what it excluded; pre-filtering the campaigns by objective
     * would leave it reporting «no awareness spend» for a scope that merely declined to ask about it.
     */
    public function objectivePerformance(): ObjectivePerformance
    {
        return new ObjectivePerformance(
            projectIds: $this->projectIds === [] ? null : $this->projectIds,
            campaignIds: $this->resolvedCampaignIds(),
            providers: $this->providers === [] ? null : $this->providers,
            accountIds: $this->accountIds === [] ? null : $this->accountIds,
        );
    }

    /**
     * Every objective this scope admits, with marketing paths expanded into the objectives on them.
     *
     * The two axes are deliberately combined rather than applied one after the other: a scope naming
     * the conversion PATH and the `leads` OBJECTIVE means «leads» (both admit it), and applying them
     * as two successive filters would produce the same answer only by accident of ordering.
     *
     * @return list<string>
     */
    public function objectivesIncludingPaths(): array
    {
        if ($this->paths === []) {
            return $this->objectives;
        }

        $onPaths = array_values(array_map(
            static fn (CampaignObjective $o): string => $o->value,
            array_filter(
                CampaignObjective::cases(),
                fn (CampaignObjective $o): bool => in_array($o->path()->value, $this->paths, true),
            ),
        ));

        if ($this->objectives === []) {
            return $onPaths;
        }

        $both = array_values(array_intersect($this->objectives, $onPaths));

        // Both axes named, nothing satisfies both → an objective that cannot exist, so the engine
        // matches nothing rather than falling back to every objective.
        return $both === [] ? ['__none__'] : $both;
    }

    /**
     * The campaign set the figures are bounded to, or null for «no campaign bound».
     *
     * @return list<string>|null
     */
    public function resolvedCampaignIds(): ?array
    {
        $fromStructure = $this->campaignIdsBehindAdSetsAndAds();

        if ($this->campaignIds === [] && $fromStructure === null) {
            return null;
        }
        if ($fromStructure === null) {
            return $this->campaignIds;
        }
        if ($this->campaignIds === []) {
            return $fromStructure;
        }

        $both = array_values(array_intersect($this->campaignIds, $fromStructure));

        return $both === [] ? [self::IMPOSSIBLE] : $both;
    }

    /**
     * The campaigns that the selected ad sets and ads belong to, or null when neither axis is used.
     *
     * A selection whose rows have all been deleted resolves to IMPOSSIBLE rather than to «no bound»:
     * a stale saved template must not quietly widen to the whole project the day its ad set is gone.
     *
     * @return list<string>|null
     */
    private function campaignIdsBehindAdSetsAndAds(): ?array
    {
        if ($this->adSetIds === [] && $this->adIds === []) {
            return null;
        }

        $ids = [];

        if ($this->adSetIds !== []) {
            $ids = array_merge($ids, ExternalAdSet::query()
                ->whereIn('id', $this->adSetIds)
                ->pluck('unified_campaign_id')->all());
        }

        if ($this->adIds !== []) {
            $ids = array_merge($ids, ExternalAd::query()
                ->whereIn('id', $this->adIds)
                ->pluck('unified_campaign_id')->all());
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => (string) $id,
            $ids,
        ))));

        return $ids === [] ? [self::IMPOSSIBLE] : $ids;
    }

    /**
     * What each bound axis actually does to the figures — printed beside them, not left to inference.
     *
     * The `grain` field is the point: `figures` means every number on the report narrowed, `creatives`
     * means only the creative section did, and `campaign` means the selection was resolved up to the
     * campaigns behind it because nothing finer is stored. A reader who selects three ads and sees a
     * spend figure is entitled to know it is the campaigns' spend.
     *
     * @return list<array{axis: string, count: int, grain: string, note_ar: string, note_en: string}>
     */
    public function explain(): array
    {
        $out = [];

        foreach ($this->boundAxes() as $axis) {
            if ($axis === 'metrics') {
                continue; // a display choice, not a bound on the data
            }

            $count = count($this->{self::property($axis)});

            [$grain, $ar, $en] = match (true) {
                in_array($axis, self::RESOLVED_AXES, true) => [
                    'campaign',
                    'لا تُخزَّن مؤشرات على هذا المستوى — النطاق مطبَّق على الحملات التابعة له.',
                    'No metrics are stored at this level — the bound is applied to the campaigns behind it.',
                ],
                $axis === 'creative_ids' => [
                    'creatives',
                    'يضيّق قسم المحتويات وحده؛ إجماليات الحملات تبقى على مستوى الحملة.',
                    'Narrows the creative section only; campaign totals stay at campaign grain.',
                ],
                $axis === 'client_ids' => [
                    'projects',
                    'يضيّق عبر مشاريع العميل.',
                    'Narrows through the client’s projects.',
                ],
                default => [
                    'figures',
                    'يضيّق كل رقم في التقرير.',
                    'Narrows every figure in the report.',
                ],
            };

            $out[] = ['axis' => $axis, 'count' => $count, 'grain' => $grain, 'note_ar' => $ar, 'note_en' => $en];
        }

        return $out;
    }

    /** `campaign_ids` → `campaignIds`. */
    private static function property(string $axis): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $axis))));
    }

    private static function laterOf(?string $a, ?string $b): ?string
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => max($a, $b),
        };
    }

    private static function earlierOf(?string $a, ?string $b): ?string
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => min($a, $b),
        };
    }
}
