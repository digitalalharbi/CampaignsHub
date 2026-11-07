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
use App\Domains\Reports\Services\CreativeRankingService;
use App\Domains\Reports\Support\HiddenMoney;
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
                'reconciles' => $this->reconciles($spend, array_map(static fn (array $o) => $o['totals']['spend'], $objectives)),
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
                'spend' => $familySpend,
                'results' => $familyResults,
                'cost_per_result' => $cost === null || $familySpend === null ? null : $this->number($this->metrics->aggregate($attrRows)[$cost] ?? null),
            ],
            'coverage' => ['spend_known_share' => $knownSpend, 'results_known_share' => $knownResults],
            'reconciles' => $this->reconciles($familySpend, array_map(static fn (array $f) => $f['spend'], $formats)),
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

    /** @param list<?float> $parts */
    private function reconciles(?float $whole, array $parts): ?bool
    {
        if ($whole === null || in_array(null, $parts, true)) {
            return null;
        }

        return abs($whole - array_sum($parts)) < 0.005;
    }
}
