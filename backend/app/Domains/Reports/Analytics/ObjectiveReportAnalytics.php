<?php

declare(strict_types=1);

namespace App\Domains\Reports\Analytics;

use App\Domains\Campaigns\Creative\CreativeRanking;
use App\Domains\Campaigns\Creative\RankingMetric;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;

/**
 * REPORT-OBJECTIVE-ANALYTICS-001 — objective-aware KPI blocks, leaders, trends and contribution.
 *
 * Pure: it reads sums somebody else queried and returns the section. That is what lets the live link,
 * the shared snapshot and the PDF carry the same block — they differ in when the sums were read, never
 * in what is done with them.
 *
 * ## One block per family, and never a figure across families
 *
 * A mixed programme produces one block per family it ran. There is no «overall CPA» here, no ranking
 * of an awareness platform against a sales platform, and no contribution share of one family's
 * outcome counted against another's. The payload states it (`cross_family_blend: false`) so a surface
 * that wanted to draw one would have to contradict the data it was given.
 *
 * ## Three states, kept apart
 *
 *   reported      a provider sent the figure; `value` may be 0 and that 0 is measured.
 *   unavailable   the family asks for it and nobody sent it, or its money was not converted, or its
 *                 denominator is zero. `value` is null and `reason` says which.
 *   inapplicable  the key is not in the family at all — it is simply not in the block.
 *
 * ## A ranking must be earned
 *
 * Best and weakest exist only where at least two rows clear the metric's minimum volume
 * (`ObjectiveMetricFamilies::MINIMUM_VOLUME`) and are ordered by the canonical ranker. Otherwise both
 * are null and the reason travels — no guess, no superlative over a single row.
 */
final class ObjectiveReportAnalytics
{
    public const VERSION = 1;

    public function __construct(private readonly CreativeRanking $ranking = new CreativeRanking) {}

    /**
     * @param  list<array<string,mixed>>  $byObjectiveProvider  one row per (objective, provider): base sums, `<k>_rows`, `<k>_withheld`
     * @param  list<array<string,mixed>>  $byObjectiveDay  one row per (objective, date) with the same columns
     * @param  list<array<string,mixed>>  $content  roster rows: name, provider, format, objective, metrics
     * @return array<string,mixed>
     */
    public function build(array $byObjectiveProvider, array $byObjectiveDay, array $content, Carbon $from, Carbon $to): array
    {
        /** @var array<string, array{total: array<string,float>, platforms: array<string, array<string,float>>}> $families */
        $families = [];
        $unclassified = false;

        foreach ($byObjectiveProvider as $row) {
            $family = $this->familyOfObjective($row['objective'] ?? null);

            if ($family === null) {
                $unclassified = $unclassified || $this->hasAnyRows($row);

                continue;
            }

            $families[$family] ??= ['total' => [], 'platforms' => []];
            $families[$family]['total'] = $this->add($families[$family]['total'], $row);
            $provider = (string) ($row['provider'] ?? '');
            $families[$family]['platforms'][$provider] = $this->add($families[$family]['platforms'][$provider] ?? [], $row);
        }

        /** @var array<string, array<string, array<string,float>>> $days */
        $days = [];
        foreach ($byObjectiveDay as $row) {
            $family = $this->familyOfObjective($row['objective'] ?? null);
            if ($family === null) {
                continue;
            }
            $date = substr((string) ($row['date'] ?? ''), 0, 10);
            $days[$family][$date] = $this->add($days[$family][$date] ?? [], $row);
        }

        /** @var array<string, list<array<string,mixed>>> $contentByFamily */
        $contentByFamily = [];
        foreach ($content as $item) {
            $family = $this->familyOfObjective($item['objective'] ?? null);
            if ($family !== null && is_array($item['metrics'] ?? null)) {
                $contentByFamily[$family][] = $item;
            }
        }

        $blocks = [];

        foreach (ObjectiveMetricFamilies::families() as $family) {
            if (! isset($families[$family]) || ! $this->hasAnyRows($families[$family]['total'])) {
                continue;
            }

            $blocks[] = $this->block(
                $family,
                $families[$family]['total'],
                $families[$family]['platforms'],
                $days[$family] ?? [],
                $contentByFamily[$family] ?? [],
                $from,
                $to,
            );
        }

        return [
            'version' => self::VERSION,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'families' => $blocks,
            'mixed' => count($blocks) > 1,
            'cross_family_blend' => false,
            // Spend on campaigns whose objective nobody classified has no family to be judged under.
            'unclassified_present' => $unclassified,
            'minimum_volume' => array_map(
                static fn (array $rule): array => ['volume' => $rule[0], 'minimum' => $rule[1]],
                ObjectiveMetricFamilies::MINIMUM_VOLUME,
            ),
        ];
    }

    /**
     * A section with the figures a link that hides money must not publish removed.
     *
     * Removed, not nulled to «—»: a KPI tile reading «—» under «CPA» tells the reader a figure exists
     * and is being withheld, which is the operator's decision leaking as a gap. A ranking that rests on
     * a hidden figure goes with it, because its order would reveal the figure.
     *
     * @param  array<string,mixed>|null  $section
     * @param  list<string>  $hidden  metric keys the link hides (`CreativeVisibility` lists)
     * @return array<string,mixed>|null
     */
    public static function redact(?array $section, array $hidden): ?array
    {
        if ($section === null || $hidden === []) {
            return $section;
        }

        // A figure built from hidden money is hidden money: every cost-per this section can produce,
        // including ones a shared list has never heard of (cost per conversion), goes with its base.
        foreach ([...ObjectiveMetricFamilies::BASE, ...array_keys(ObjectiveMetricFamilies::DERIVED)] as $key) {
            if (array_intersect(ObjectiveMetricFamilies::moneyParts($key), $hidden) !== []) {
                $hidden[] = $key;
            }
        }

        $keep = static fn (array $kpis): array => array_values(array_filter(
            $kpis,
            static fn ($k): bool => is_array($k) && ! in_array($k['key'] ?? null, $hidden, true),
        ));

        foreach ($section['families'] ?? [] as $i => $block) {
            $block['kpis'] = $keep($block['kpis'] ?? []);

            foreach ($block['platforms'] ?? [] as $p => $platform) {
                $block['platforms'][$p]['kpis'] = $keep($platform['kpis'] ?? []);
                if (in_array('spend', $hidden, true)) {
                    $block['platforms'][$p]['spend_share'] = null;
                }
            }

            foreach (['platform_ranking', 'content_ranking'] as $ranking) {
                if (in_array($block[$ranking]['metric'] ?? null, $hidden, true)) {
                    $block[$ranking] = ['metric' => null, 'best' => null, 'weakest' => null, 'reason' => 'hidden_by_link', 'eligible' => 0, 'candidates' => 0];
                }
            }

            if (in_array($block['contribution']['outcome'] ?? null, $hidden, true)) {
                $block['contribution'] = null;
            }

            if (is_array($block['trend'] ?? null)) {
                if (in_array($block['trend']['metric'] ?? null, $hidden, true)) {
                    $block['trend']['metric'] = null;
                }
                foreach ($block['trend']['points'] ?? [] as $d => $point) {
                    if (in_array('spend', $hidden, true)) {
                        $point['spend'] = null;
                    }
                    if ($block['trend']['metric'] === null) {
                        $point['value'] = null;
                    }
                    if (in_array($block['trend']['outcome'] ?? null, $hidden, true)) {
                        $point['outcome'] = null;
                    }
                    $block['trend']['points'][$d] = $point;
                }
            }

            $section['families'][$i] = $block;
        }

        return $section;
    }

    /**
     * @param  array<string,float>  $total
     * @param  array<string, array<string,float>>  $platforms
     * @param  array<string, array<string,float>>  $days
     * @param  list<array<string,mixed>>  $content
     * @return array<string,mixed>
     */
    private function block(string $family, array $total, array $platforms, array $days, array $content, Carbon $from, Carbon $to): array
    {
        $purchases = (float) ($total['purchases_rows'] ?? 0) > 0;
        $sales = ObjectiveMetricFamilies::salesKeys($purchases);
        $keys = ['spend', ...($family === 'sales' ? $sales['kpis'] : ObjectiveMetricFamilies::kpis($family))];
        $outcomes = $family === 'sales' ? $sales['outcomes'] : ObjectiveMetricFamilies::outcomes($family);
        $label = ObjectiveFamily::from($family)->label();

        $platformRows = [];
        foreach ($platforms as $provider => $bag) {
            $platformRows[] = [
                'provider' => (string) $provider,
                'kpis' => array_map(fn (string $k): array => $this->kpi($bag, $k), $keys),
                '_bag' => $bag,
            ];
        }
        $platformRows = AdPlatforms::sortRows($platformRows, 'provider');

        $spendTotal = $this->figure($total, 'spend');
        foreach ($platformRows as $i => $row) {
            $spend = $this->figure($row['_bag'], 'spend');
            $platformRows[$i]['spend_share'] = $spendTotal['state'] === 'reported' && $spend['state'] === 'reported' && $spendTotal['value'] > 0
                ? round($spend['value'] / $spendTotal['value'], 4)
                : null;
        }

        $contribution = $this->contribution($outcomes, $total, $platformRows);
        $platformRanking = $this->rank($family, array_map(fn (array $r): array => [
            'provider' => $r['provider'],
            ...$this->flat($r['_bag']),
        ], $platformRows), $purchases);

        $contentRanking = $this->rank($family, array_map(fn (array $item): array => [
            'name' => (string) ($item['name'] ?? ''),
            'provider' => (string) ($item['provider'] ?? ''),
            'format' => $item['format'] ?? null,
            ...$this->contentFlat((array) $item['metrics']),
        ], $content), $purchases);

        foreach ($platformRows as $i => $row) {
            unset($platformRows[$i]['_bag']);
        }

        return [
            'family' => $family,
            'label_ar' => $label['ar'],
            'label_en' => $label['en'],
            'kpis' => array_map(fn (string $k): array => $this->familyKpi($total, $platforms, $k), $keys),
            'platforms' => $platformRows,
            'contribution' => $contribution,
            'platform_ranking' => $platformRanking,
            'content_ranking' => $contentRanking,
            'trend' => $this->trend($keys, $total, $days, $contribution['outcome'] ?? null, $from, $to),
        ];
    }

    /**
     * A family-level figure, only over the platforms that report it.
     *
     * A base figure is the sum of what was sent, and names the platforms that sent nothing. A ratio is
     * rebuilt from the platforms that reported BOTH of its parts: Meta's revenue over Meta's and
     * TikTok's spend is not a ROAS, it is Meta's return diluted by money whose return nobody measured.
     * The platforms left out are named, so a reader can see the figure's basis.
     *
     * @param  array<string,float>  $total
     * @param  array<string, array<string,float>>  $platforms
     * @return array<string,mixed>
     */
    private function familyKpi(array $total, array $platforms, string $key): array
    {
        $missing = [];

        if (! isset(ObjectiveMetricFamilies::DERIVED[$key])) {
            foreach ($platforms as $provider => $bag) {
                if ($this->figure($bag, $key)['state'] !== 'reported') {
                    $missing[] = (string) $provider;
                }
            }

            return $this->kpi($total, $key) + ['not_reported_by' => $missing];
        }

        [$num, $den] = ObjectiveMetricFamilies::DERIVED[$key];
        $basis = [];

        foreach ($platforms as $provider => $bag) {
            if ($this->figure($bag, $num)['state'] === 'reported' && $this->figure($bag, $den)['state'] === 'reported') {
                $basis = $this->add($basis, $bag);
            } else {
                $missing[] = (string) $provider;
            }
        }

        // Nobody reported both parts: the family total says why (not reported, not converted).
        $kpi = $basis === [] ? $this->kpi($total, $key) : $this->kpi($basis, $key);

        return $kpi + ['not_reported_by' => $basis === [] ? array_map('strval', array_keys($platforms)) : $missing];
    }

    /** @return array<string,mixed> */
    private function kpi(array $bag, string $key): array
    {
        $figure = $this->figure($bag, $key);
        $label = ObjectiveMetricFamilies::label($key);

        return [
            'key' => $key,
            'label_ar' => $label['ar'],
            'label_en' => $label['en'],
            'kind' => ObjectiveMetricFamilies::kind($key),
            'value' => $figure['value'],
            'state' => $figure['state'],
            'reason' => $figure['reason'],
        ];
    }

    /**
     * One figure from a bag of sums.
     *
     * @param  array<string,float>  $bag
     * @return array{value: ?float, state: string, reason: ?string}
     */
    public function figure(array $bag, string $key): array
    {
        if (isset(ObjectiveMetricFamilies::DERIVED[$key])) {
            [$num, $den, $scale] = ObjectiveMetricFamilies::DERIVED[$key];
            $n = $this->figure($bag, $num);
            $d = $this->figure($bag, $den);

            foreach ([$n, $d] as $part) {
                if ($part['state'] !== 'reported') {
                    return ['value' => null, 'state' => 'unavailable', 'reason' => $part['reason']];
                }
            }

            if ((float) $d['value'] <= 0) {
                // Nothing to divide by. Not zero — a zero CPA reads as «free».
                return ['value' => null, 'state' => 'unavailable', 'reason' => 'zero_denominator'];
            }

            $precision = in_array(ObjectiveMetricFamilies::kind($key), ['rate'], true) ? 4 : 2;

            return ['value' => round((float) $n['value'] / (float) $d['value'] * $scale, $precision), 'state' => 'reported', 'reason' => null];
        }

        if ((float) ($bag["{$key}_rows"] ?? 0) <= 0) {
            return ['value' => null, 'state' => 'unavailable', 'reason' => 'not_reported'];
        }

        /*
         * Reach is deduplicated by the PROVIDER, for the grain it was asked about — and what is stored is
         * one figure per campaign, per platform, per day. Adding two of those counts a person who came
         * back on Tuesday twice, so a sum across days, campaigns or platforms is not reach and is not
         * printed as reach. It is reported only where the whole bag is ONE provider grain carrying ONE
         * reach row; anything else is «not reported», which is the truth about a deduplicated period
         * reach. Frequency divides by this figure and inherits the rule.
         */
        if ($key === 'reach' && ((float) ($bag['grains'] ?? 0) !== 1.0 || (float) $bag['reach_rows'] !== 1.0)) {
            return ['value' => null, 'state' => 'unavailable', 'reason' => 'not_reported'];
        }

        if (in_array($key, ObjectiveMetricFamilies::MONEY_BASE, true) && (float) ($bag["{$key}_withheld"] ?? 0) > 0) {
            // FX-001 withheld some of it: a partial sum in the reporting currency is not the figure.
            return ['value' => null, 'state' => 'unavailable', 'reason' => 'money_not_converted'];
        }

        return ['value' => round((float) ($bag[$key] ?? 0), 2), 'state' => 'reported', 'reason' => null];
    }

    /**
     * Each platform's share of the family's OUTCOME — the first outcome anybody reported.
     *
     * @param  list<string>  $outcomes
     * @param  array<string,float>  $total
     * @param  list<array<string,mixed>>  $platformRows
     * @return array<string,mixed>|null
     */
    private function contribution(array $outcomes, array $total, array $platformRows): ?array
    {
        /*
         * An outcome EVERY platform reported is preferred over one only some did: «Meta 100% of
         * revenue» beside a Snapchat that sent no revenue is a share of what was measured, not of what
         * happened. Only when no outcome is universal does the first reported one stand, with the
         * silent platforms shown as «—».
         */
        $universal = array_values(array_filter($outcomes, function (string $outcome) use ($platformRows): bool {
            foreach ($platformRows as $row) {
                if ($this->figure($row['_bag'], $outcome)['state'] !== 'reported') {
                    return false;
                }
            }

            return true;
        }));

        foreach (array_values(array_unique([...$universal, ...$outcomes])) as $outcome) {
            $figure = $this->figure($total, $outcome);

            if ($figure['state'] !== 'reported' || (float) $figure['value'] <= 0) {
                continue;
            }

            $label = ObjectiveMetricFamilies::label($outcome);
            $rows = [];

            foreach ($platformRows as $row) {
                $own = $this->figure($row['_bag'], $outcome);
                $rows[] = [
                    'provider' => $row['provider'],
                    'value' => $own['value'],
                    'share' => $own['state'] === 'reported' ? round((float) $own['value'] / (float) $figure['value'], 4) : null,
                ];
            }

            usort($rows, static fn (array $a, array $b): int => ($b['share'] ?? -1) <=> ($a['share'] ?? -1));

            return [
                'outcome' => $outcome,
                'label_ar' => $label['ar'],
                'label_en' => $label['en'],
                'total' => $figure['value'],
                'rows' => $rows,
            ];
        }

        return null;
    }

    /**
     * Best and weakest, on a defensible metric, above the minimum volume — or neither.
     *
     * @param  list<array<string,mixed>>  $rows  flat rows (metric keys at the top level)
     * @return array<string,mixed>
     */
    private function rank(string $family, array $rows, bool $purchases = true): array
    {
        $none = static fn (?string $metric, string $reason, int $eligible = 0): array => [
            'metric' => $metric, 'best' => null, 'weakest' => null,
            'reason' => $reason, 'eligible' => $eligible, 'candidates' => count($rows),
        ];

        $spending = array_values(array_filter($rows, static fn (array $r): bool => $r['_spent'] ?? false));

        if ($spending === []) {
            return $none(null, 'nothing_spent');
        }

        $familyEnum = ObjectiveFamily::from($family);
        $resolved = $this->ranking->resolveMetric($spending, $familyEnum);

        /*
         * The canonical metric first, then the family's other DEFENSIBLE metrics in the canonical
         * layout's own order — the first one at least two rows can be compared on wins.
         *
         * The canonical resolver takes the primary whenever ANY row reports it. That is right for a
         * list, and wrong for a comparison: one platform reporting revenue makes ROAS the metric, and
         * a two-platform sales scope then has nothing to compare even though both report what a
         * purchase cost. Falling back along the SAME layout keeps «what better means» the ranker's,
         * and a volume metric is still never reached.
         *
         * A scope with no purchases reads the layout's CPA as cost per CONVERSION, under its own name.
         */
        $layout = RankingMetric::forObjective($familyEnum);
        $named = static fn (?string $m): ?string => ! $purchases && $m === 'cpa' ? 'cost_per_conversion' : $m;
        $candidates = array_values(array_unique(array_filter(
            array_map($named, [$resolved, $layout['primary'], ...$layout['secondary']]),
            // Defensible, and reported by at least one row — an unreported metric compares nothing.
            static fn (?string $m): bool => $m !== null
                && isset(ObjectiveMetricFamilies::MINIMUM_VOLUME[$m])
                && array_any($spending, static fn (array $r): bool => is_numeric($r[$m] ?? null)),
        )));

        if ($candidates === []) {
            return $none(null, $resolved === null ? 'no_metric_reported' : 'no_defensible_metric');
        }

        $metric = $candidates[0];
        $volumeKey = $this->volumeFor($metric, $purchases);
        $ranked = [];

        foreach ($candidates as $candidate) {
            $candidateVolume = $this->volumeFor($candidate, $purchases);
            $minimum = ObjectiveMetricFamilies::MINIMUM_VOLUME[$candidate][1];

            $eligible = array_values(array_filter(
                $spending,
                static fn (array $r): bool => is_numeric($r[$candidate] ?? null) && (float) ($r[$candidateVolume] ?? 0) >= $minimum,
            ));

            /*
             * Cost per conversion is ordered by the ranker's CPA rule — both are «lower cost per
             * result is better» — so its direction is the canonical one, not a second opinion.
             */
            $rankedBy = $candidate === 'cost_per_conversion' ? 'cpa' : $candidate;
            $input = $candidate === 'cost_per_conversion'
                ? array_map(static fn (array $r): array => ['cpa' => $r['cost_per_conversion']] + $r, $eligible)
                : $eligible;

            $attempt = $this->ranking->rank($input, $familyEnum, $rankedBy)['ranked'];

            if (count($attempt) >= 2) {
                [$metric, $volumeKey, $ranked] = [$candidate, $candidateVolume, $attempt];

                break;
            }
        }

        if (count($ranked) < 2) {
            return $none($metric, count($spending) < 2 ? 'only_one_candidate' : 'too_few_above_minimum_volume');
        }

        /*
         * Two ends a hair apart are not a strongest and a weakest. 49.99 against 50.00 printed under
         * «▲ الأقوى» and «▼ الأضعف» is a verdict made of rounding; below a 1% relative gap the two
         * are reported as indistinguishable instead.
         */
        $top = (float) $ranked[0][$metric];
        $bottom = (float) $ranked[count($ranked) - 1][$metric];
        $scale = max(abs($top), abs($bottom));

        if ($scale <= 0 || abs($top - $bottom) / $scale < 0.01) {
            return $none($metric, 'indistinguishable', count($ranked));
        }

        $shape = static function (array $r) use ($metric, $volumeKey): array {
            $out = ['provider' => $r['provider'] ?? null, 'value' => (float) $r[$metric], 'volume' => ['key' => $volumeKey, 'value' => (float) ($r[$volumeKey] ?? 0)]];
            if (array_key_exists('name', $r)) {
                $out = ['name' => $r['name'], 'format' => $r['format'] ?? null, ...$out];
            }

            return $out;
        };

        return [
            'metric' => $metric,
            'best' => $shape($ranked[0]),
            'weakest' => $shape($ranked[count($ranked) - 1]),
            'reason' => null,
            'eligible' => count($ranked),
            'candidates' => count($rows),
        ];
    }

    /** ROAS rests on purchases, or on conversions where the scope has no purchases. */
    private function volumeFor(string $metric, bool $purchases): string
    {
        $key = ObjectiveMetricFamilies::MINIMUM_VOLUME[$metric][0];

        return $key === 'purchases' && ! $purchases ? 'conversions' : $key;
    }

    /**
     * The family's trend: spend, outcome and its lead efficiency figure, per day, from that day's sums.
     *
     * @param  array<string,float>  $total
     * @param  array<string, array<string,float>>  $days
     * @return array<string,mixed>|null
     */
    private function trend(array $keys, array $total, array $days, ?string $outcome, Carbon $from, Carbon $to): ?array
    {
        if ($days === []) {
            return null;
        }

        $metric = null;
        foreach ($keys as $key) {
            if (isset(ObjectiveMetricFamilies::DERIVED[$key]) && $key !== 'frequency' && $this->figure($total, $key)['state'] === 'reported') {
                $metric = $key;
                break;
            }
        }

        $points = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $bag = $days[$day->toDateString()] ?? null;
            $points[] = [
                'date' => $day->toDateString(),
                'reported' => $bag !== null,
                'spend' => $bag === null ? null : $this->figure($bag, 'spend')['value'],
                'outcome' => $bag === null || $outcome === null ? null : $this->figure($bag, $outcome)['value'],
                'value' => $bag === null || $metric === null ? null : $this->figure($bag, $metric)['value'],
            ];
        }

        return ['metric' => $metric, 'outcome' => $outcome, 'points' => $points];
    }

    /** @return array<string,mixed> the bag as flat figures a ranker reads — null where not reported */
    private function flat(array $bag): array
    {
        $out = [];

        foreach ([...ObjectiveMetricFamilies::BASE, ...array_keys(ObjectiveMetricFamilies::DERIVED)] as $key) {
            $out[$key] = $this->figure($bag, $key)['value'];
        }

        $spend = $this->figure($bag, 'spend');
        $out['_spent'] = $spend['state'] === 'reported' ? $spend['value'] > 0 : (float) ($bag['spend_withheld'] ?? 0) > 0;
        $out['money_comparable'] = $spend['state'] === 'reported';

        return $out;
    }

    /**
     * A roster row's figures in the same flat shape, re-derived from its own sums.
     *
     * @param  array<string,mixed>  $m
     * @return array<string,mixed>
     */
    private function contentFlat(array $m): array
    {
        $bag = [];

        foreach (ObjectiveMetricFamilies::BASE as $key) {
            $value = $m[$key] ?? null;
            $withheld = in_array($key, ObjectiveMetricFamilies::MONEY_BASE, true) ? (float) ($m["{$key}_withheld_rows"] ?? 0) : 0.0;

            $bag[$key] = is_numeric($value) ? (float) $value : 0.0;
            $bag["{$key}_rows"] = is_numeric($value) || $withheld > 0 ? 1.0 : 0.0;
            $bag["{$key}_withheld"] = $withheld;
        }

        return $this->flat($bag);
    }

    /** @param array<string,mixed> $row */
    private function hasAnyRows(array $row): bool
    {
        foreach (ObjectiveMetricFamilies::BASE as $key) {
            if ((float) ($row["{$key}_rows"] ?? 0) > 0 || (float) ($row["{$key}_withheld"] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,float>  $bag
     * @param  array<string,mixed>  $row
     * @return array<string,float>
     */
    private function add(array $bag, array $row): array
    {
        foreach (ObjectiveMetricFamilies::BASE as $key) {
            foreach ([$key, "{$key}_rows", "{$key}_withheld"] as $column) {
                $bag[$column] = ($bag[$column] ?? 0.0) + (float) ($row[$column] ?? 0);
            }
        }

        // Distinct (campaign, platform, day) grains — summable, because no grain spans two rows here.
        $bag['grains'] = ($bag['grains'] ?? 0.0) + (float) ($row['grains'] ?? 0);

        return $bag;
    }

    private function familyOfObjective(mixed $objective): ?string
    {
        $case = CampaignObjective::tryFrom((string) $objective) ?? CampaignObjective::Other;

        return ObjectiveMetricFamilies::familyOf($case->family());
    }
}
