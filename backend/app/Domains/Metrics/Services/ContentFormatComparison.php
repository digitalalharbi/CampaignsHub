<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Creative\CreativeRanking;
use App\Domains\Campaigns\Creative\RankingDirection;
use App\Domains\Campaigns\Creative\RankingMetric;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\CreativeResultAttribution;
use App\Domains\Campaigns\Support\CreativeKind;
use App\Domains\Reports\Analytics\ObjectiveMetricFamilies;
use App\Domains\Reports\Services\CreativeRankingService;
use App\Domains\Reports\Support\ChosenFigures;
use App\Domains\Reports\Support\HiddenMoney;
use App\Domains\Reports\Support\ReportObjectives;
use Illuminate\Support\Carbon;

/**
 * CONTENT-FORMAT-COMPARISON-001 — «مقارنة أنواع المحتوى»: image, video, carousel, collection … per objective.
 *
 * The Owner, 2026-11-07: «الأكثر ومقارنة للمحتويات صور أو فيديو، والنتائج لكل نوع والمقارنة بينها والتكلفة وتفاصيلها،
 * لمعرفة كل براند وش الأنسب له … بشرط أن تكون البيانات دقيقة 100%». This is the one reading every surface draws —
 * the Analytics content tab, the live link, the saved snapshot and its PDF — so the figures cannot differ between them.
 *
 * ## Where each figure comes from — and nowhere else
 *
 *   FORMAT    the provider's own token in `external_creatives.format`, through {@see CreativeKind::fromToken()}: the
 *             same arms the card and the filter use, asked of the token alone. A token none of the kinds name, or an
 *             empty column, is the explicit `unclassified` slice with its share — never guessed from assets.
 *   FIGURES   {@see CreativeMetrics::forCreatives()} and {@see CreativeMetrics::aggregate()} — the canonical creative
 *             reader. Every ratio is the aggregate's own, pooled from summed numerators and denominators (never an
 *             average of creatives' ratios), and a withheld amount (FX-001) makes every figure derived from it null,
 *             never zero.
 *   RESULT    the objective family's cost per result from {@see RankingMetric::costPerResult()}, and the result is
 *             that ratio's own denominator ({@see CreativeMetrics::ratioDenominator()}). Results are compared only
 *             inside one objective family; a mixed scope gets one comparison per family.
 *   ATTRIBUTION a creative under a campaign whose results never reached creative grain
 *             ({@see CreativeResultAttribution}) contributes no result and no cost per result — its spend still counts
 *             as spend. Nothing is projected down from the campaign.
 *   VERDICT   {@see CreativeRanking::rank()} — the one canonical ranker — over the formats that may be judged: known
 *             kind, not a small sample, results complete, spend not withheld, a positive cost per result; and only
 *             when at least two formats qualify. Never ROAS (see {@see RankingMetric::costPerResult()}).
 *   SAMPLE    «عينة صغيرة» is the repo's two existing rules, read from their own constants: fewer creatives with
 *             figures than {@see ContentIntelligence::MINIMUM_CREATIVES}, or less than
 *             {@see CreativeRankingService::MATERIAL_SHARE} of the objective's spend.
 *
 * ## Reconciliation
 *
 * Every creative in scope lands in exactly one format, so the formats' spend adds to the objective's spend and the
 * objectives' spend adds to the scope's — the same `aggregate()` over the same rows. `reconciles` states it.
 */
final class ContentFormatComparison
{
    public const KEY = 'content_format_comparison';

    /** How many creatives each format lists for its drill-down in a report; the API lists up to the larger figure. */
    public const REPORT_DRILL_ROWS = 50;

    public const API_DRILL_ROWS = 200;

    private const TREND_BUCKETS = 8;

    /** The money a client never reads, and a link that hides spend never carries. */
    private const SPEND_KEYS = ['spend', 'spend_original', 'share_of_spend', 'spend_known_share', 'cost_per_result', 'cpm', 'cpc', 'roas', 'value'];

    public function __construct(
        private readonly CreativeMetrics $metrics,
        private readonly CreativeFormatIntelligence $scope,
        private readonly CreativeResultAttribution $attribution,
        private readonly CreativeRanking $ranking = new CreativeRanking,
    ) {}

    /**
     * @param  array<string, mixed>|null  $ceiling  a link's grant — see {@see CreativeFormatIntelligence::forScope()}
     * @return array<string, mixed>
     */
    public function forScope(
        string $projectId,
        Carbon $from,
        Carbon $to,
        ?string $accountId = null,
        ?string $campaignId = null,
        ?array $ceiling = null,
        int $drillRows = self::API_DRILL_ROWS,
    ): array {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $creatives = $this->scope->scopedCreatives($projectId, $accountId, $campaignId, $ceiling);

        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        $current = $this->reading($creatives, $from, $to, $drillRows, true);
        $previous = $this->reading($creatives, $prevFrom, $prevTo, 0, false);

        foreach ($current['objectives'] as &$objective) {
            $before = collect($previous['objectives'])->firstWhere('family', $objective['family']);
            foreach ($objective['formats'] as &$format) {
                $was = is_array($before) ? collect($before['formats'])->firstWhere('format', $format['format']) : null;
                $format['previous'] = is_array($was)
                    ? array_intersect_key($was, array_flip(['spend', 'results', 'cost_per_result', 'ctr', 'cpm', 'cpc', 'conversion_rate', 'roas', 'impressions', 'clicks']))
                    : null;
            }
            unset($format);
        }
        unset($objective);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'previous_period' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'currency' => $this->scope->projectCurrency($projectId),
            'totals' => $current['totals'],
            'coverage' => $current['coverage'],
            'objectives' => $current['objectives'],
            'sample_rule' => [
                'minimum_creatives' => ContentIntelligence::MINIMUM_CREATIVES,
                'material_share' => CreativeRankingService::MATERIAL_SHARE,
            ],
        ];
    }

    /**
     * What a reader of this audience may see — decided here by an allow-list of money keys, not by the renderer.
     *
     * A client reads results, rates and shares of results: no spend, no cost per result, no CPM/CPC, no ROAS (all of
     * it spend-derived), and the verdict without the figure it was decided on. A link that hides spend gets the same
     * cut; one that hides revenue loses the return. Campaign and account names are never in this block.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public static function forAudience(array $block, bool $clientFacing, bool $hideSpend, bool $hideRevenue): array
    {
        $hideSpend = $hideSpend || $clientFacing;
        if ($hideSpend) {
            $block = self::without($block, self::SPEND_KEYS);
            $block['currency'] = null;
            $block['spend_hidden'] = true;
        }
        if ($hideRevenue) {
            $block = self::without($block, ['roas', 'revenue']);
        }

        return HiddenMoney::redact($block, $hideSpend, $hideRevenue);
    }

    /**
     * REPORT-FORMAT-COMPARE-TABLE-001 — the figures a report's comparison table can show, by the names the report's KPI presets use
     * (`ReportTemplateEngine::METRIC_SETS`). Volume and delivery figures read every creative of the format; a RESULT and everything
     * built on it (orders, revenue, ROAS, cost per order …) reads only the creatives whose results reached creative grain, exactly as
     * `results` does above. A withheld amount makes every figure built from it null (FX-001).
     */
    public const TABLE_KEYS = [
        'spend', 'impressions', 'reach', 'frequency', 'clicks', 'ctr', 'cpc', 'cpm', 'landing_page_views', 'cost_per_lpv',
        'add_to_cart', 'purchases', 'conversions', 'revenue', 'roas', 'cpa', 'conversion_rate',
        'leads', 'cpl', 'installs', 'cpi', 'engagements', 'engagement_rate', 'cpe', 'video_views', 'cost_per_view', 'completion_rate',
    ];

    /** The figures that are a result, or built on one — read from the attributable creatives only. */
    private const RESULT_KEYS = ['add_to_cart', 'purchases', 'conversions', 'revenue', 'roas', 'cpa', 'conversion_rate', 'leads', 'cpl', 'installs', 'cpi'];

    /** Figures built on spend (null when any of it is withheld). */
    private const SPEND_BUILT = ['spend', 'cpc', 'cpm', 'cost_per_lpv', 'roas', 'cpa', 'cpl', 'cpi', 'cpe', 'cost_per_view'];

    /** A report preset's name for a figure → the name this table carries it under. */
    public const TABLE_ALIASES = ['video_completion_rate' => 'completion_rate', 'orders' => 'purchases'];

    /** Which way is better, for the per-row winner. Spend and frequency name no winner: more of either is not better or worse. */
    public const TABLE_BETTER = [
        'impressions' => 'higher', 'reach' => 'higher', 'clicks' => 'higher', 'ctr' => 'higher', 'landing_page_views' => 'higher',
        'add_to_cart' => 'higher', 'purchases' => 'higher', 'conversions' => 'higher', 'revenue' => 'higher', 'roas' => 'higher',
        'conversion_rate' => 'higher', 'leads' => 'higher', 'installs' => 'higher', 'engagements' => 'higher', 'engagement_rate' => 'higher',
        'video_views' => 'higher', 'completion_rate' => 'higher',
        'cpc' => 'lower', 'cpm' => 'lower', 'cost_per_lpv' => 'lower', 'cpa' => 'lower', 'cpl' => 'lower', 'cpi' => 'lower', 'cpe' => 'lower', 'cost_per_view' => 'lower',
    ];

    /** The figure a one-line verdict is stated on, per family, tried in order (the first the data answers wins). */
    public const VERDICT_ORDER = [
        'sales' => ['roas', 'purchases', 'conversions', 'revenue', 'cpa'],
        'traffic' => ['ctr', 'cpc', 'clicks'],
        'awareness' => ['cpm', 'reach', 'impressions'],
        'engagement' => ['engagement_rate', 'cpe', 'engagements'],
        'video' => ['completion_rate', 'cost_per_view', 'video_views'],
        'leads' => ['cpl', 'leads'],
        'app' => ['cpi', 'installs'],
    ];

    /**
     * @param  array<string, mixed>  $all
     * @param  array<string, mixed>  $attr
     * @return array<string, float|null>
     */
    private function tableMetrics(array $all, array $attr, bool $withheld, bool $attrWithheld, bool $revenueWithheld): array
    {
        $out = [];
        foreach (self::TABLE_KEYS as $key) {
            $result = in_array($key, self::RESULT_KEYS, true);
            $source = $result ? $attr : $all;
            $value = $this->number($source[$key] ?? null);
            if (in_array($key, self::SPEND_BUILT, true) && ($result ? $attrWithheld : $withheld)) {
                $value = null;
            }
            if (in_array($key, ['revenue', 'roas'], true) && $revenueWithheld) {
                $value = null;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * REPORT-FORMAT-COMPARE-TABLE-001 (Owner 2026-11-13: «جدول مقارنة احترافي: كل نوع محتوى عمود، وكل صف مؤشر من مؤشرات هدف التقرير»)
     * — the report's ONE comparison table: a column per content type, a row per KPI of the report.
     *
     * Rows are the report's own KPI list (its cards' list — the objective's preset or the operator's choice), in that order; a row
     * every type left empty (no figure, or zero everywhere) is not a row, and a type with no figure in any row is not a column. Built
     * from each family's per-format `metrics` — already cut for this reader (client, hidden spend or revenue) — and, when the report
     * keeps several families, merged by format: counts and money are summed and every ratio is rebuilt from those sums, never averaged;
     * reach and frequency and a completion rate cannot be added across families and are dropped then.
     *
     * Cells are keyed by KPI so that every later redaction pass (`HiddenMoney`, `forAudience`, `withoutMetrics`) that removes a money
     * key by name removes its whole row with it. The verdict is drawn by the renderer from the cells that survived — never written here,
     * where a sentence could outlive the figure it quotes.
     *
     * @param  array<string, mixed>  $block
     * @param  list<string>  $kpis
     * @return array<string, mixed>|null
     */
    public static function table(array $block, array $kpis): ?array
    {
        $objectives = array_values(array_filter((array) ($block['objectives'] ?? []), 'is_array'));
        $rows = [];
        foreach ($kpis as $kpi) {
            $key = self::TABLE_ALIASES[$kpi] ?? $kpi;
            if (in_array($key, self::TABLE_KEYS, true) && ! in_array($key, $rows, true)) {
                $rows[] = $key;
            }
        }
        if ($objectives === [] || $rows === []) {
            return null;
        }

        /*
         * Orders are the provider's PURCHASES; where no type in scope reports a purchase but conversions are reported, the row is
         * CONVERSIONS — named as conversions, never printed under the word purchase. The rule `ObjectiveMetricFamilies::salesKeys()`
         * states for the objective blocks, applied here rather than restated.
         */
        $reported = static function (string $key) use ($objectives): bool {
            foreach ($objectives as $objective) {
                foreach ((array) ($objective['formats'] ?? []) as $f) {
                    if (is_numeric($f['metrics'][$key] ?? null) && (float) $f['metrics'][$key] > 0.0) {
                        return true;
                    }
                }
            }

            return false;
        };
        $purchases = ObjectiveMetricFamilies::salesKeys($reported('purchases'))['volume'];
        $rows = array_values(array_unique(array_map(static fn (string $r): string => $r === 'purchases' ? $purchases : $r, $rows)));

        $merged = [];
        $meta = [];
        foreach ($objectives as $objective) {
            foreach ((array) ($objective['formats'] ?? []) as $f) {
                if (! is_array($f) || ! is_string($f['format'] ?? null)) {
                    continue;
                }
                $kind = $f['format'];
                $merged[$kind][] = (array) ($f['metrics'] ?? []);
                $meta[$kind] = [
                    'format' => $kind,
                    'creatives' => ($meta[$kind]['creatives'] ?? 0) + (int) ($f['creatives'] ?? 0),
                    'spend' => ($meta[$kind]['spend'] ?? 0.0) + (float) ($f['spend'] ?? 0.0),
                    'small_sample' => ($meta[$kind]['small_sample'] ?? true) && (bool) ($f['small_sample'] ?? false),
                ];
            }
        }

        $cells = [];
        foreach ($rows as $row) {
            foreach ($merged as $kind => $sets) {
                $cells[$row][$kind] = self::mergedFigure($row, $sets);
            }
        }

        $present = static fn ($v): bool => is_numeric($v) && (float) $v != 0.0;
        $cells = array_filter($cells, static fn (array $byFormat): bool => array_filter($byFormat, $present) !== []);
        $formats = array_values(array_filter($meta, static function (array $m) use ($cells, $present): bool {
            foreach ($cells as $byFormat) {
                if ($present($byFormat[$m['format']] ?? null)) {
                    return true;
                }
            }

            return false;
        }));
        if ($cells === [] || $formats === []) {
            return null;
        }
        usort($formats, static fn (array $a, array $b): int => [$a['format'] === CreativeKind::UNCLASSIFIED, -$a['spend'], -$a['creatives'], $a['format']]
            <=> [$b['format'] === CreativeKind::UNCLASSIFIED, -$b['spend'], -$b['creatives'], $b['format']]);
        $formats = array_map(static fn (array $m): array => ['format' => $m['format'], 'creatives' => $m['creatives'], 'small_sample' => $m['small_sample']], $formats);

        $first = (string) ($objectives[0]['family'] ?? '');

        return [
            'rows' => array_keys($cells),
            'formats' => $formats,
            'cells' => $cells,
            'better' => array_intersect_key(self::TABLE_BETTER, $cells),
            'labels' => array_map(static fn (string $k): array => ObjectiveMetricFamilies::label($k), array_combine(array_keys($cells), array_keys($cells))),
            'kinds' => array_map(static fn (string $k): string => ObjectiveMetricFamilies::kind($k), array_combine(array_keys($cells), array_keys($cells))),
            'verdict_order' => self::VERDICT_ORDER[$first] ?? [],
        ];
    }

    /**
     * One figure of one format across the families kept: a family's own figure when only one has the format, else rebuilt.
     *
     * @param  list<array<string, mixed>>  $sets
     */
    private static function mergedFigure(string $key, array $sets): ?float
    {
        if (count($sets) === 1) {
            return is_numeric($sets[0][$key] ?? null) ? (float) $sets[0][$key] : null;
        }
        $sum = static function (string $k) use ($sets): ?float {
            $total = null;
            foreach ($sets as $set) {
                if (! array_key_exists($k, $set)) {
                    continue;
                }
                if (! is_numeric($set[$k])) {
                    return null;
                }
                $total = ($total ?? 0.0) + (float) $set[$k];
            }

            return $total;
        };
        $ratio = static function (?float $num, ?float $den, float $scale = 1.0): ?float {
            return $num === null || $den === null || $den <= 0.0 ? null : $num / $den * $scale;
        };

        return match ($key) {
            'reach', 'frequency', 'completion_rate' => null,
            'ctr' => $ratio($sum('clicks'), $sum('impressions')),
            'cpc' => $ratio($sum('spend'), $sum('clicks')),
            'cpm' => $ratio($sum('spend'), $sum('impressions'), 1000.0),
            'cost_per_lpv' => $ratio($sum('spend'), $sum('landing_page_views')),
            'roas' => $ratio($sum('revenue'), $sum('spend')),
            'cpa' => $ratio($sum('spend'), $sum('purchases')),
            'conversion_rate' => $ratio($sum('conversions'), $sum('clicks')),
            'cpl' => $ratio($sum('spend'), $sum('leads')),
            'cpi' => $ratio($sum('spend'), $sum('installs')),
            'engagement_rate' => $ratio($sum('engagements'), $sum('impressions')),
            'cpe' => $ratio($sum('spend'), $sum('engagements')),
            'cost_per_view' => $ratio($sum('spend'), $sum('video_views')),
            default => $sum($key),
        };
    }

    /** REPORT-FORMAT-COMPARE-COMPACT-001 — a report objective (template) → the objective family its results are counted in. */
    public const TEMPLATE_FAMILY = [
        'sales' => 'sales',
        'awareness' => 'awareness',
        'traffic' => 'traffic',
        'engagement' => 'engagement',
        'video' => 'video',
        'leads' => 'leads',
        'app_installs' => 'app',
    ];

    /**
     * REPORT-FORMAT-COMPARE-COMPACT-001 (Owner 2026-11-13: «المقارنة بين أنواع المحتوى كبيرة جدًا ومكررة حسب كل هدف، وتظهر هوامش
     * وأصفار لأن هدف العميل مثلًا مبيعات») — a REPORT's comparison speaks of the report's own objective(s) only.
     *
     * A report measured against sales does not compare formats on awareness or traffic results: those families' cells are the
     * figures of an objective nobody asked this report about (empty, or a zero), and each one was a page of its own. The report's
     * objectives choose which families stay; a report with no objective keeps every family. Nothing about the creatives is hidden
     * by this — the content section lists them all — it only decides which results a format is compared on. A family with no
     * format is dropped; where no family matches the report's objective every family with formats stays; a comparison with no
     * family at all is null: no data, no section.
     *
     * @param  array<string, mixed>  $block
     * @param  list<string>  $templates  {@see ReportObjectives::of()}
     * @return array<string, mixed>|null
     */
    public static function forReportObjectives(array $block, array $templates): ?array
    {
        $families = array_values(array_unique(array_filter(array_map(static fn (string $t): ?string => self::TEMPLATE_FAMILY[$t] ?? null, $templates))));
        $withFormats = array_values(array_filter((array) ($block['objectives'] ?? []), static fn ($o): bool => is_array($o) && (array) ($o['formats'] ?? []) !== []));
        $objectives = array_values(array_filter($withFormats, static fn (array $o): bool => $families === [] || in_array($o['family'] ?? null, $families, true)));
        /*
         * No family of the report's objective in scope: the comparison keeps what the content WAS bought for rather than vanish.
         * A saved report carries its inferred lens in the same column as a chosen one, and an inferred lens that names no family
         * of the creatives in scope must not take a true comparison away (objectives choose KPIs, they never hide content).
         */
        if ($objectives === []) {
            $objectives = $withFormats;
        }
        if ($objectives === []) {
            return null;
        }
        $block['objectives'] = $objectives;

        return $block;
    }

    /**
     * REPORT-KPI-CONTRACT-001 — a report or link that chose its KPIs carries no figure it did not choose, here too.
     *
     * `$cut` is the list of KPI keys left out. Each objective names its own result and cost metric, so «conversions not
     * chosen» takes a sales objective's results (and their share) but leaves a traffic objective's clicks.
     *
     * @param  array<string, mixed>  $block
     * @param  list<string>  $cut
     * @return array<string, mixed>
     */
    public static function withoutMetrics(array $block, array $cut): array
    {
        if ($cut === []) {
            return $block;
        }
        $off = array_fill_keys($cut, true);
        $direct = ['ctr', 'cpm', 'cpc', 'conversion_rate', 'roas', 'revenue', 'impressions', 'clicks'];
        if (isset($off['spend'])) {
            $block['totals']['spend'] = null;
            $block['totals']['spend_original'] = null;
            $block['coverage']['spend_known_share'] = null;
        }
        foreach (['impressions', 'clicks'] as $k) {
            if (isset($off[$k]) && array_key_exists($k, (array) ($block['totals'] ?? []))) {
                $block['totals'][$k] = null;
            }
        }

        // REPORT-FORMAT-COMPARE-TABLE-001 — the table and each type's figures carry no KPI the report left out either. Orders that
        // fell back to conversions (`table()`) stand for the chosen purchases and stay while purchases are chosen.
        $gone = static fn (string $k): bool => isset($off[$k]) && ! ($k === 'conversions' && ! isset($off['purchases']));
        if (is_array($block['table'] ?? null)) {
            $t = $block['table'];
            $t['rows'] = array_values(array_filter((array) ($t['rows'] ?? []), static fn ($r): bool => is_string($r) && ! $gone($r)));
            foreach (['cells', 'better', 'labels', 'kinds'] as $part) {
                $t[$part] = array_filter((array) ($t[$part] ?? []), static fn ($k): bool => ! $gone((string) $k), ARRAY_FILTER_USE_KEY);
            }
            $block['table'] = $t['rows'] === [] ? null : $t;
        }

        $block['objectives'] = array_map(static function ($objective) use ($off, $direct, $gone) {
            if (! is_array($objective)) {
                return $objective;
            }
            foreach ((array) ($objective['formats'] ?? []) as $i => $format) {
                if (is_array($format['metrics'] ?? null)) {
                    foreach (array_keys($format['metrics']) as $k) {
                        if ($gone((string) $k)) {
                            $objective['formats'][$i]['metrics'][$k] = null;
                        }
                    }
                }
            }
            $keys = array_values(array_filter($direct, static fn (string $k): bool => isset($off[$k])));
            if (isset($off['spend'])) {
                $keys = [...$keys, 'spend', 'spend_original', 'share_of_spend', 'spend_known_share'];
            }
            $result = (string) ($objective['result_metric'] ?? '');
            $cost = (string) ($objective['cost_metric'] ?? '');
            // The objective's result is answered by its own KPI («conversions» for sales) or by the generic «results»:
            // it leaves only when neither was chosen. Its cost likewise — and a per-unit cost is a KPI of its own,
            // chosen or not with spend ({@see ChosenFigures}); a link hiding spend is cut by forAudience(), not here.
            if (isset($off['results']) && ($result === '' || isset($off[$result]))) {
                $keys = [...$keys, 'results', 'share_of_results', 'results_known_share'];
            }
            if (isset($off['cost_per_result']) && ($cost === '' || isset($off[$cost]))) {
                $keys = [...$keys, 'cost_per_result', 'value'];
            }

            return $keys === [] ? $objective : self::without($objective, array_values(array_unique($keys)));
        }, (array) ($block['objectives'] ?? []));

        return $block;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function without(array $data, array $keys): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, $keys, true)) {
                $data[$k] = null;
            } elseif (is_array($v)) {
                $data[$k] = self::without($v, $keys);
            }
        }

        return $data;
    }

    /**
     * One window's reading.
     *
     * @param  list<array{id: string, format: ?string, objective: ?string, account_id: ?string, campaign_id: ?string, name: string}>  $creatives
     * @return array{totals: array<string, mixed>, coverage: array<string, mixed>, objectives: list<array<string, mixed>>}
     */
    private function reading(array $creatives, Carbon $from, Carbon $to, int $drillRows, bool $withTrend): array
    {
        $ids = array_map(static fn (array $c): string => $c['id'], $creatives);
        $figures = $ids === [] ? [] : $this->metrics->forCreatives($ids, $from, $to);
        $unattributed = $this->attribution->unattributedCampaigns(
            array_values(array_unique(array_filter(array_column($creatives, 'campaign_id')))),
            $from,
            $to,
        );

        $families = [];
        foreach ($creatives as $creative) {
            $family = $this->metrics->familyFor($creative['objective'] ?? null);
            $families[$family->value][] = $creative + ['kind' => CreativeKind::fromToken($creative['format'] ?? null)];
        }

        $objectives = [];
        foreach ($families as $family => $members) {
            $objectives[] = $this->objective(ObjectiveFamily::from($family), $members, $figures, $unattributed, $from, $to, $drillRows, $withTrend);
        }

        // The objective most of the money was bought for leads; then the one with more creatives; then the name.
        usort($objectives, static fn (array $a, array $b): int => [(float) ($b['totals']['spend'] ?? -1), $b['totals']['creatives'], $a['family']]
            <=> [(float) ($a['totals']['spend'] ?? -1), $a['totals']['creatives'], $b['family']]);

        $all = array_values(array_filter(array_map(static fn (string $id) => $figures[$id] ?? null, $ids), 'is_array'));
        $total = $this->metrics->aggregate($all);
        $known = array_values(array_filter(array_map(
            static fn (array $c) => CreativeKind::fromToken($c['format'] ?? null) !== CreativeKind::UNCLASSIFIED ? ($figures[$c['id']] ?? null) : null,
            $creatives,
        ), 'is_array'));
        $knownTotal = $this->metrics->aggregate($known);

        $spend = $this->money($total);
        $knownSpend = $known === [] ? 0.0 : $this->money($knownTotal);

        return [
            'totals' => [
                'creatives' => count($creatives),
                'creatives_with_metrics' => count($all),
                'spend' => $spend,
                'spend_original' => $spend === null ? ($total['spend_original'] ?? null) : null,
                'original_currency' => $spend === null ? ($total['money_original_currency'] ?? null) : null,
                'impressions' => $total['impressions'] ?? null,
                'clicks' => $total['clicks'] ?? null,
                'reconciles' => $this->reconciles($spend, array_map(static fn (array $o) => $o['totals']['spend'], self::delivered($objectives, static fn (array $o): int => (int) $o['totals']['creatives_with_metrics']))),
            ],
            'coverage' => [
                'creatives_total' => count($creatives),
                'creatives_known' => count(array_filter($creatives, static fn (array $c): bool => CreativeKind::fromToken($c['format'] ?? null) !== CreativeKind::UNCLASSIFIED)),
                // The share of the money whose format the platform named. Null — never 0 — when the money is withheld or nothing was spent.
                'spend_known_share' => $spend !== null && $spend > 0.0 && $knownSpend !== null ? $knownSpend / $spend : null,
            ],
            'objectives' => $objectives,
        ];
    }

    /**
     * One objective family's comparison.
     *
     * @param  list<array<string, mixed>>  $members
     * @param  array<string, array<string, mixed>|null>  $figures
     * @param  array<string, true>  $unattributed
     * @return array<string, mixed>
     */
    private function objective(ObjectiveFamily $family, array $members, array $figures, array $unattributed, Carbon $from, Carbon $to, int $drillRows, bool $withTrend): array
    {
        [$cost, $result] = $this->resultFor($family, $members, $figures, $unattributed);
        $isResultMetric = $result !== null && in_array($result, CreativeResultAttribution::RESULT_METRICS, true);
        $attributable = fn (array $c): bool => ! $isResultMetric || ($c['campaign_id'] ?? null) === null || ! isset($unattributed[$c['campaign_id']]);

        $byKind = [];
        foreach ($members as $member) {
            $byKind[$member['kind']][] = $member;
        }

        $allRows = array_values(array_filter(array_map(static fn (array $c) => $figures[$c['id']] ?? null, $members), 'is_array'));
        $familyTotal = $this->metrics->aggregate($allRows);
        $familySpend = $this->money($familyTotal);
        $attrRows = array_values(array_filter(array_map(static fn (array $c) => $attributable($c) ? ($figures[$c['id']] ?? null) : null, $members), 'is_array'));
        $familyResults = $result === null ? null : $this->number($this->metrics->aggregate($attrRows)[$result] ?? null);

        $formats = [];
        foreach ($byKind as $kind => $set) {
            $formats[] = $this->format((string) $kind, $set, $figures, $attributable, $cost, $result, $familySpend, $familyResults, $drillRows);
        }

        usort($formats, static fn (array $a, array $b): int => [$a['format'] === CreativeKind::UNCLASSIFIED, -1 * (float) ($a['spend'] ?? -1), -1 * $a['creatives'], $a['format']]
            <=> [$b['format'] === CreativeKind::UNCLASSIFIED, -1 * (float) ($b['spend'] ?? -1), -1 * $b['creatives'], $b['format']]);

        [$winner, $refusal] = $this->winner($family, $formats, $cost);

        $knownResults = null;
        if ($result !== null && $familyResults !== null && $familyResults > 0.0) {
            $known = array_sum(array_map(static fn (array $f): float => $f['format'] !== CreativeKind::UNCLASSIFIED ? (float) ($f['results'] ?? 0.0) : 0.0, $formats));
            $knownResults = $known / $familyResults;
        }
        $knownSpend = null;
        if ($familySpend !== null && $familySpend > 0.0) {
            $known = 0.0;
            foreach ($formats as $f) {
                if ($f['format'] !== CreativeKind::UNCLASSIFIED) {
                    $known += (float) ($f['spend'] ?? 0.0);
                }
            }
            $knownSpend = $known / $familySpend;
        }

        return [
            'family' => $family->value,
            'label' => $family->label(),
            'result_metric' => $result,
            'cost_metric' => $cost,
            // Every cost per result is a cost: the smaller is the better, and the registry says so rather than this line.
            'lower_is_better' => $cost === null ? null : RankingMetric::of($cost)->direction === RankingDirection::LowerIsBetter,
            'totals' => [
                'creatives' => count($members),
                'creatives_with_metrics' => count($allRows),
                'spend' => $familySpend,
                'results' => $familyResults,
                'cost_per_result' => $cost === null || $familySpend === null ? null : $this->number($this->metrics->aggregate($attrRows)[$cost] ?? null),
            ],
            'coverage' => ['spend_known_share' => $knownSpend, 'results_known_share' => $knownResults],
            'reconciles' => $this->reconciles($familySpend, array_map(static fn (array $f) => $f['spend'], self::delivered($formats, static fn (array $f): int => (int) $f['creatives_with_metrics']))),
            'formats' => $formats,
            'winner' => $winner,
            'winner_refusal' => $refusal,
            'trend' => $withTrend ? $this->trend($formats, $members, $attributable, $cost, $result, $from, $to) : null,
        ];
    }

    /**
     * The cost this family is judged on and the result it is per: the first candidate whose result ANY attributable
     * creative in the family reported. Leads fall back from a cost per lead to a cost per conversion exactly as the
     * ranking registry does, for platforms that return only the generic figure for a lead form.
     *
     * @param  list<array<string, mixed>>  $members
     * @param  array<string, array<string, mixed>|null>  $figures
     * @param  array<string, true>  $unattributed
     * @return array{0: ?string, 1: ?string}
     */
    private function resultFor(ObjectiveFamily $family, array $members, array $figures, array $unattributed): array
    {
        $candidates = RankingMetric::costPerResult($family);

        foreach ($candidates as $cost) {
            $result = CreativeMetrics::ratioDenominator($cost);
            if ($result === null) {
                continue;
            }
            foreach ($members as $member) {
                if (is_numeric($figures[$member['id']][$result] ?? null)) {
                    return [$cost, $result];
                }
            }
        }

        // Nothing reported any candidate: name the objective's own first pair, so the cells say «not reported».
        $first = $candidates[0] ?? null;

        return [$first, $first === null ? null : CreativeMetrics::ratioDenominator($first)];
    }

    /**
     * @param  list<array<string, mixed>>  $set
     * @param  array<string, array<string, mixed>|null>  $figures
     * @return array<string, mixed>
     */
    private function format(string $kind, array $set, array $figures, callable $attributable, ?string $cost, ?string $result, ?float $familySpend, ?float $familyResults, int $drillRows): array
    {
        $rows = array_values(array_filter(array_map(static fn (array $c) => $figures[$c['id']] ?? null, $set), 'is_array'));
        $attrRows = array_values(array_filter(array_map(static fn (array $c) => $attributable($c) ? ($figures[$c['id']] ?? null) : null, $set), 'is_array'));
        $all = $this->metrics->aggregate($rows) ?? [];
        $attr = $this->metrics->aggregate($attrRows) ?? [];

        $spend = $rows === [] ? null : $this->money($all);
        $withheld = (int) ($all['spend_withheld_rows'] ?? 0) > 0;
        $attrWithheld = (int) ($attr['spend_withheld_rows'] ?? 0) > 0;
        $revenueWithheld = (int) ($attr['revenue_withheld_rows'] ?? 0) > 0;

        $results = $result === null || $attrRows === [] ? null : $this->number($attr[$result] ?? null);
        $resultsPartial = $result !== null && count($attrRows) < count($rows);
        // A cost over spend with a withheld part is a smaller number, not a cost — FX-001.
        $costPerResult = $cost === null || $attrWithheld ? null : $this->number($attr[$cost] ?? null);
        $revenue = $revenueWithheld ? null : $this->number($attr['revenue'] ?? null);

        $smallReason = match (true) {
            count($rows) < ContentIntelligence::MINIMUM_CREATIVES => 'fewer_creatives_than_minimum',
            $spend !== null && $familySpend !== null && $familySpend > 0.0 && $spend < CreativeRankingService::MATERIAL_SHARE * $familySpend => 'below_material_share_of_spend',
            default => null,
        };

        $tokens = [];
        if ($kind === CreativeKind::UNCLASSIFIED) {
            foreach ($set as $c) {
                $token = strtolower(trim((string) ($c['format'] ?? '')));
                $tokens[$token === '' ? '' : $token] = ($tokens[$token === '' ? '' : $token] ?? 0) + 1;
            }
            ksort($tokens);
        }

        $drill = [];
        foreach ($set as $c) {
            $row = $figures[$c['id']] ?? null;
            $ok = $attributable($c);
            $rowWithheld = is_array($row) && (int) ($row['spend_withheld_rows'] ?? 0) > 0;
            $drill[] = [
                'id' => $c['id'],
                'name' => $c['name'] !== '' ? $c['name'] : $c['id'],
                'token' => $c['format'],
                'spend' => is_array($row) && ! $rowWithheld ? $this->number($row['spend'] ?? null) : null,
                'results' => is_array($row) && $ok && $result !== null ? $this->number($row[$result] ?? null) : null,
                'cost_per_result' => is_array($row) && $ok && ! $rowWithheld && $cost !== null ? $this->number($row[$cost] ?? null) : null,
                'ctr' => is_array($row) ? $this->number($row['ctr'] ?? null) : null,
                'impressions' => is_array($row) ? $this->number($row['impressions'] ?? null) : null,
                'clicks' => is_array($row) ? $this->number($row['clicks'] ?? null) : null,
                'results_not_attributable' => ! $ok,
            ];
        }
        usort($drill, static fn (array $a, array $b): int => [$a['spend'] === null, -1 * (float) ($a['spend'] ?? 0), -1 * (float) ($a['results'] ?? 0), $a['name']]
            <=> [$b['spend'] === null, -1 * (float) ($b['spend'] ?? 0), -1 * (float) ($b['results'] ?? 0), $b['name']]);

        return [
            'format' => $kind,
            'tokens' => $kind === CreativeKind::UNCLASSIFIED ? array_map(static fn ($t, $n): array => ['token' => (string) $t, 'creatives' => $n], array_keys($tokens), $tokens) : [],
            'creatives' => count($set),
            'creatives_with_metrics' => count($rows),
            'spend' => $spend,
            'spend_withheld' => $withheld,
            'spend_original' => $withheld ? ($all['spend_original'] ?? null) : null,
            'original_currency' => $withheld ? ($all['money_original_currency'] ?? null) : null,
            'impressions' => $this->number($all['impressions'] ?? null),
            'clicks' => $this->number($all['clicks'] ?? null),
            'results' => $results,
            'results_partial' => $resultsPartial,
            'cost_per_result' => $costPerResult,
            'ctr' => $this->number($all['ctr'] ?? null),
            'cpm' => $withheld ? null : $this->number($all['cpm'] ?? null),
            'cpc' => $withheld ? null : $this->number($all['cpc'] ?? null),
            'conversion_rate' => $this->number($attr['conversion_rate'] ?? null),
            // ROAS only where revenue was reported at this grain, and never the verdict.
            'roas' => $attrWithheld || $revenue === null || $revenue <= 0.0 ? null : $this->number($attr['roas'] ?? null),
            'revenue' => $revenue,
            'share_of_spend' => $spend !== null && $familySpend !== null && $familySpend > 0.0 ? $spend / $familySpend : null,
            'share_of_results' => $results !== null && $familyResults !== null && $familyResults > 0.0 ? $results / $familyResults : null,
            'small_sample' => $smallReason !== null,
            'small_sample_reason' => $smallReason,
            'creative_list' => array_slice($drill, 0, max(0, $drillRows)),
            'creative_list_total' => count($drill),
            // REPORT-FORMAT-COMPARE-TABLE-001 — every figure a report KPI can name, for the report's comparison table (see table()).
            'metrics' => $this->tableMetrics($all, $attr, $withheld, $attrWithheld, $revenueWithheld),
        ];
    }

    /**
     * The format whose result cost least, for this objective, in this window — or why there is none.
     *
     * @param  list<array<string, mixed>>  $formats
     * @return array{0: array{format: string, metric: string, value: float}|null, 1: ?string}
     */
    private function winner(ObjectiveFamily $family, array $formats, ?string $cost): array
    {
        if ($cost === null) {
            return [null, 'no_result_metric_for_objective'];
        }

        $eligible = array_values(array_filter($formats, static fn (array $f): bool => $f['format'] !== CreativeKind::UNCLASSIFIED
            && ! $f['small_sample']
            && ! $f['results_partial']
            && ! $f['spend_withheld']
            && is_numeric($f['results']) && (float) $f['results'] > 0.0
            && is_numeric($f['cost_per_result']) && (float) $f['cost_per_result'] > 0.0));

        if (count($eligible) < 2) {
            return [null, 'fewer_than_two_comparable_formats'];
        }

        $rows = array_map(static fn (array $f): array => ['format' => $f['format'], $cost => (float) $f['cost_per_result']], $eligible);
        $ranked = $this->ranking->rank($rows, $family, $cost)['ranked'];

        if (count($ranked) < 2) {
            return [null, 'fewer_than_two_comparable_formats'];
        }
        if (abs((float) $ranked[0][$cost] - (float) $ranked[1][$cost]) < 1e-9) {
            return [null, 'tie'];
        }

        return [['format' => (string) $ranked[0]['format'], 'metric' => $cost, 'value' => (float) $ranked[0][$cost]], null];
    }

    /**
     * Results and cost per result per format, over at most eight buckets — the same `aggregate()` per bucket, so a
     * point on the line and the figure in the table cannot disagree about what a cost per result is.
     *
     * @param  list<array<string, mixed>>  $formats
     * @param  list<array<string, mixed>>  $members
     * @return array{metric: ?string, result: ?string, points: list<array<string, mixed>>}
     */
    private function trend(array $formats, array $members, callable $attributable, ?string $cost, ?string $result, Carbon $from, Carbon $to): array
    {
        $empty = ['metric' => $cost, 'result' => $result, 'points' => []];
        $days = $from->diffInDays($to) + 1;
        if ($result === null || $days < 2 || $members === []) {
            return $empty;
        }

        $size = (int) max(1, (int) ceil($days / self::TREND_BUCKETS));
        $buckets = (int) ceil($days / $size);
        $ids = array_map(static fn (array $c): string => $c['id'], array_values(array_filter($members, $attributable)));
        if ($ids === []) {
            return $empty;
        }
        $byBucket = $this->metrics->figuresByBlock($ids, $from->copy(), $size, $buckets, $to);

        $points = [];
        $index = 0;
        for ($start = $from->copy(); $start->lessThanOrEqualTo($to); $start->addDays($size)) {
            $end = $start->copy()->addDays($size - 1)->min($to);
            $bucket = $byBucket[$index++] ?? [];
            $point = ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'formats' => []];
            foreach ($formats as $format) {
                $rows = [];
                foreach ($members as $c) {
                    if ($c['kind'] === $format['format'] && $attributable($c) && is_array($bucket[$c['id']] ?? null)) {
                        $rows[] = $bucket[$c['id']];
                    }
                }
                $agg = $rows === [] ? null : $this->metrics->aggregate($rows);
                // Null, never 0 — a bucket the format could not answer is a gap in the line.
                $point['formats'][$format['format']] = [
                    'results' => $agg === null ? null : $this->number($agg[$result] ?? null),
                    'cost_per_result' => $agg === null || $cost === null || (int) ($agg['spend_withheld_rows'] ?? 0) > 0 ? null : $this->number($agg[$cost] ?? null),
                ];
            }
            $points[] = $point;
        }

        return ['metric' => $cost, 'result' => $result, 'points' => $points];
    }

    /** Spend as a figure, or null when any part of it was withheld or nothing reported it. Never 0 for «unknown». */
    private function money(?array $aggregate): ?float
    {
        if ($aggregate === null || (int) ($aggregate['spend_withheld_rows'] ?? 0) > 0) {
            return null;
        }

        return $this->number($aggregate['spend'] ?? null);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * The parts that read any row in this window. A format (or objective) whose creatives delivered nothing has no
     * figure — shown as «no data», never 0 — and is no unknown part of the whole either, so it does not stop the sum.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return list<array<string, mixed>>
     */
    private static function delivered(array $parts, callable $rows): array
    {
        return array_values(array_filter($parts, static fn (array $p): bool => $rows($p) > 0));
    }

    /** @param list<?float> $parts */
    private function reconciles(?float $whole, array $parts): ?bool
    {
        if ($whole === null || in_array(null, $parts, true)) {
            return null;
        }

        return abs($whole - array_sum($parts)) < 0.005;
    }
}
