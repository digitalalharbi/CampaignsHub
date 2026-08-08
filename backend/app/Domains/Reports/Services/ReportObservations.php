<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

/**
 * What actually happened in this report's own numbers — §14.7.
 *
 * ## Every note is derived, or it is not written
 *
 * The requirement's own line is «كل ملاحظة مبنية على بيانات التقرير ونطاقه — لا نصوص عامة ثابتة»,
 * and it is the whole design. Each detector below either finds its condition in this report's data
 * and states the figures that made it true, or it produces nothing. There is no fallback copy, no
 * «راجع الأداء بشكل دوري» filler, and no sentence that would be equally true of any report.
 *
 * That is also why every threshold is a named constant with a reason. A note that fires on a 2%
 * movement is noise, and noise in an alerts column trains a reader to skip the column — which costs
 * more than the note was ever worth.
 *
 * ## Nothing is asserted on thin data
 *
 * A detector that needs a denominator checks for one. A comparison needs a previous period with
 * something in it. A «best vs worst» needs at least two comparable rows — with one platform the
 * best and the worst are the same row, and saying so is a sentence with no content. Where the data
 * cannot support a judgement the honest output is silence, not a hedged claim.
 *
 * ## Objective-aware throughout
 *
 * The cost that matters, the rate that matters and the direction that is «good» all come from
 * {@see ReportObjectiveLens}. A rising CPA on a brand report is not a finding; a rising CPM is.
 */
final class ReportObservations
{
    /** Below this a movement is noise — period-over-period metrics wobble on volume alone. */
    private const MATERIAL_CHANGE = 0.15;

    /** A cost rising by more than this is worth a reader's attention even in a good month. */
    private const COST_ALERT = 0.20;

    /**
     * Average impressions per person before repetition starts working against the campaign.
     *
     * Not a universal truth and not treated as one: the note says what the figure IS and suggests
     * a review, rather than asserting the audience is exhausted.
     */
    private const FREQUENCY_WATCH = 3.5;

    /** Spending this much faster than plan will exhaust the budget before the period ends. */
    private const PACE_FAST = 1.25;

    /** …and this much slower leaves budget unspent, which is its own kind of failure. */
    private const PACE_SLOW = 0.7;

    /** Two platforms whose ranking metric differs by less than this are not worth moving money over. */
    private const REALLOCATION_GAP = 0.4;

    /**
     * @param  array<string,mixed>  $data  the snapshot as assembled so far
     * @return list<array<string,mixed>>
     */
    public function build(ReportObjectiveLens $lens, array $data): array
    {
        $out = [];
        $currency = (string) ($data['currency'] ?? 'SAR');

        foreach ([
            $this->headlineMovement($lens, $data, $currency),
            $this->risingCost($lens, $data, $currency),
            $this->fallingEngagement($lens, $data),
            $this->frequencySaturation($data),
            $this->budgetPace($data, $currency),
            $this->reallocation($lens, $data, $currency),
            $this->missingMetrics($data),
            $this->staleData($data),
        ] as $group) {
            foreach ($group as $note) {
                $out[] = $note;
            }
        }

        // Most serious first — a reader who stops after three has read the three that mattered.
        $rank = ['critical' => 0, 'warning' => 1, 'positive' => 2, 'info' => 3];
        usort($out, fn ($a, $b) => ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9));

        return $out;
    }

    /** Did the one figure this report is judged on move, and which way is that? */
    private function headlineMovement(ReportObjectiveLens $lens, array $data, string $currency): array
    {
        $metric = $lens->rankingMetric();
        $change = $data['delta'][$metric['key']] ?? null;
        $value = $data['kpis'][$metric['key']] ?? null;

        if ($change === null || $value === null || abs((float) $change) < self::MATERIAL_CHANGE) {
            return [];
        }

        $rose = (float) $change > 0;
        $good = $metric['lower_is_better'] ? ! $rose : $rose;

        return [[
            'id' => 'headline:'.$metric['key'],
            'kind' => 'period_comparison',
            'severity' => $good ? 'positive' : 'warning',
            'scope' => ['type' => 'period', 'name' => null],
            'metric' => $metric['key'],
            'value' => $lens->formatRanking((float) $value, $currency),
            'change' => round((float) $change, 4),
            'title' => sprintf('%s %s مقارنة بالفترة السابقة', $metric['label_ar'], $rose ? 'ارتفع' : 'تراجع'),
            'detail' => sprintf(
                '%s الآن %s، بفارق %s%% عن الفترة السابقة.',
                $metric['label_ar'],
                $lens->formatRanking((float) $value, $currency),
                number_format(abs((float) $change) * 100, 1),
            ),
        ]];
    }

    /** The cost of the thing this campaign buys, going up. */
    private function risingCost(ReportObjectiveLens $lens, array $data, string $currency): array
    {
        $metric = $lens->rankingMetric();
        // Already covered by the headline note when the ranking metric IS the cost.
        if ($metric['lower_is_better']) {
            return [];
        }

        $out = [];
        foreach (['cpa' => 'تكلفة النتيجة', 'cpc' => 'تكلفة النقرة', 'cpm' => 'تكلفة الألف ظهور'] as $key => $label) {
            $change = $data['delta'][$key] ?? null;
            $value = $data['kpis'][$key] ?? null;
            if ($change === null || $value === null || (float) $change <= self::COST_ALERT) {
                continue;
            }
            // A cost per RESULT is only a finding where results were the point.
            if ($key === 'cpa' && ! $lens->judgesOnCostPerResult()) {
                continue;
            }

            $out[] = [
                'id' => 'cost:'.$key,
                'kind' => 'rising_cost',
                'severity' => 'warning',
                'scope' => ['type' => 'period', 'name' => null],
                'metric' => $key,
                'value' => number_format((float) $value, 2).' '.$currency,
                'change' => round((float) $change, 4),
                'title' => sprintf('ارتفاع %s', $label),
                'detail' => sprintf(
                    '%s ارتفعت %s%% لتصل إلى %s %s، وقد يشير ذلك إلى منافسة أعلى أو ضعف في المحتوى.',
                    $label,
                    number_format((float) $change * 100, 1),
                    number_format((float) $value, 2),
                    $currency,
                ),
            ];
        }

        return $out;
    }

    /** CTR and conversion rate falling — the two rates that say the message stopped working. */
    private function fallingEngagement(ReportObjectiveLens $lens, array $data): array
    {
        $out = [];
        $rates = ['ctr' => 'معدل النقر'];
        if ($lens->judgesOnCostPerResult()) {
            $rates['conversion_rate'] = 'معدل التحويل';
        }

        foreach ($rates as $key => $label) {
            $change = $data['delta'][$key] ?? null;
            $value = $data['kpis'][$key] ?? null;
            if ($change === null || $value === null || (float) $change > -self::MATERIAL_CHANGE) {
                continue;
            }

            $out[] = [
                'id' => 'rate:'.$key,
                'kind' => 'falling_rate',
                'severity' => 'warning',
                'scope' => ['type' => 'period', 'name' => null],
                'metric' => $key,
                'value' => number_format((float) $value * 100, 2).'%',
                'change' => round((float) $change, 4),
                'title' => sprintf('تراجع %s', $label),
                'detail' => sprintf(
                    '%s تراجع %s%% ليصل إلى %s%%، وقد يدل ذلك على بداية ضعف في أداء المحتوى.',
                    $label,
                    number_format(abs((float) $change) * 100, 1),
                    number_format((float) $value * 100, 2),
                ),
            ];
        }

        return $out;
    }

    /**
     * The same people seeing the same ad, over and over.
     *
     * Requires reach to have been REPORTED: frequency is impressions ÷ reach, and on a platform that
     * publishes no reach the ratio is not small, it is absent.
     */
    private function frequencySaturation(array $data): array
    {
        if (($data['reported']['reach'] ?? false) !== true) {
            return [];
        }

        $frequency = $data['kpis']['frequency'] ?? null;
        if ($frequency === null || (float) $frequency < self::FREQUENCY_WATCH) {
            return [];
        }

        return [[
            'id' => 'frequency',
            'kind' => 'frequency_saturation',
            'severity' => 'warning',
            'scope' => ['type' => 'period', 'name' => null],
            'metric' => 'frequency',
            'value' => number_format((float) $frequency, 2),
            'change' => isset($data['delta']['frequency']) ? round((float) $data['delta']['frequency'], 4) : null,
            'title' => 'ارتفاع معدل التكرار',
            'detail' => sprintf(
                'يشاهد الشخص الواحد الإعلان %s مرة في المتوسط، ويُنصح بتوسيع الجمهور أو تحديث المحتوى.',
                number_format((float) $frequency, 1),
            ),
        ]];
    }

    /** Money going out faster or slower than the plan it was given. */
    private function budgetPace(array $data, string $currency): array
    {
        $out = [];
        foreach ($data['budget'] ?? [] as $row) {
            $pace = $row['pace'] ?? null;
            // A campaign with no budget set has no plan to deviate from — silence, not a warning.
            if ($pace === null || (float) ($row['budget'] ?? 0) <= 0) {
                continue;
            }

            $fast = (float) $pace >= self::PACE_FAST;
            $slow = (float) $pace <= self::PACE_SLOW;
            if (! $fast && ! $slow) {
                continue;
            }

            $out[] = [
                'id' => 'budget:'.$row['campaign_id'],
                'kind' => 'budget_pace',
                'severity' => $fast ? 'critical' : 'info',
                'scope' => ['type' => 'campaign', 'name' => $row['campaign_name']],
                'metric' => 'pace',
                'value' => number_format((float) $pace, 2).'×',
                'change' => null,
                'title' => sprintf('حملة «%s» تستهلك الميزانية %s الخطة', $row['campaign_name'], $fast ? 'أسرع من' : 'أبطأ من'),
                'detail' => sprintf(
                    'صُرف %s %s من أصل %s %s، بما يعادل %s%% من المتوقع حتى الآن.',
                    number_format((float) $row['spent'], 2),
                    $currency,
                    number_format((float) $row['budget'], 2),
                    $currency,
                    number_format((float) $pace * 100, 0),
                ),
            ];
        }

        return array_slice($out, 0, 3);
    }

    /**
     * Money that would do more somewhere else.
     *
     * Needs two platforms with real spend and a real gap between them. With one platform there is
     * nowhere to move money TO, and «your only platform is your best platform» is not advice.
     */
    private function reallocation(ReportObjectiveLens $lens, array $data, string $currency): array
    {
        $metric = $lens->rankingMetric();
        $rated = array_values(array_filter(
            $data['platforms'] ?? [],
            fn ($p) => ($p[$metric['key']] ?? null) !== null && (float) ($p['spend'] ?? 0) > 0,
        ));

        if (count($rated) < 2) {
            return [];
        }

        usort($rated, fn ($a, $b) => $metric['lower_is_better']
            ? $a[$metric['key']] <=> $b[$metric['key']]
            : $b[$metric['key']] <=> $a[$metric['key']]);

        $best = $rated[0];
        $worst = $rated[count($rated) - 1];
        $bv = (float) $best[$metric['key']];
        $wv = (float) $worst[$metric['key']];
        if ($bv <= 0.0 || $wv <= 0.0) {
            return [];
        }

        $gap = $metric['lower_is_better'] ? ($wv - $bv) / $wv : ($bv - $wv) / $bv;
        if ($gap < self::REALLOCATION_GAP) {
            return [];
        }

        return [[
            'id' => 'reallocation',
            'kind' => 'reallocation',
            'severity' => 'info',
            'scope' => ['type' => 'platform', 'name' => $best['provider']],
            'metric' => $metric['key'],
            'value' => $lens->formatRanking($bv, $currency),
            'change' => round($gap, 4),
            'title' => sprintf('فرصة لإعادة توزيع الميزانية نحو %s', $best['provider']),
            'detail' => sprintf(
                '%s على %s %s، مقابل %s على %s — بفارق %s%% لصالح الأولى.',
                $metric['label_ar'],
                $best['provider'],
                $lens->formatRanking($bv, $currency),
                $lens->formatRanking($wv, $currency),
                $worst['provider'],
                number_format($gap * 100, 0),
            ),
        ]];
    }

    /**
     * Metrics the layout asked for that no connected platform sends.
     *
     * This is a data-quality note, not a performance one: it explains why a card on the first page
     * says «لم ترسله المنصة» instead of leaving the reader to assume a zero.
     */
    private function missingMetrics(array $data): array
    {
        $labels = [
            'reach' => 'الوصول', 'frequency' => 'التكرار', 'video_views' => 'مشاهدات الفيديو',
            'landing_page_views' => 'زيارات صفحة الهبوط', 'engagements' => 'التفاعلات',
            'purchases' => 'المشتريات', 'revenue' => 'الإيرادات',
        ];

        $missing = [];
        foreach ($data['metric_set'] ?? [] as $key) {
            if (isset($labels[$key]) && ($data['reported'][$key] ?? true) === false) {
                $missing[] = $labels[$key];
            }
        }

        if ($missing === []) {
            return [];
        }

        return [[
            'id' => 'data:missing',
            'kind' => 'data_gap',
            'severity' => 'info',
            'scope' => ['type' => 'data', 'name' => null],
            'metric' => null,
            'value' => (string) count($missing),
            'change' => null,
            'title' => 'مؤشرات لا ترسلها المنصات المرتبطة',
            'detail' => sprintf(
                'لا تتوفر بيانات %s من المنصات المرتبطة خلال هذه الفترة، ولذلك تظهر بلا قيمة بدلًا من صفر.',
                $this->arabicList($missing),
            ),
        ]];
    }

    /** Figures quoted from a source that stopped sending. */
    private function staleData(array $data): array
    {
        $freshness = $data['freshness'] ?? null;
        if (! is_array($freshness)) {
            return [];
        }

        $state = (string) ($freshness['state'] ?? 'unknown');
        if (! in_array($state, ['stale', 'failed'], true)) {
            return [];
        }

        $failing = array_map(static fn ($f) => (string) ($f['name'] ?? $f['provider'] ?? ''), $freshness['failing'] ?? []);

        return [[
            'id' => 'data:freshness',
            'kind' => 'stale_data',
            'severity' => $state === 'failed' ? 'critical' : 'warning',
            'scope' => ['type' => 'data', 'name' => null],
            'metric' => null,
            'value' => $freshness['last_sync_at'] ?? null,
            'change' => null,
            'title' => $state === 'failed' ? 'تعذّرت آخر مزامنة للبيانات' : 'بيانات لم تتحدث مؤخرًا',
            'detail' => $failing === []
                ? 'لم تتحدث بيانات هذه الفترة مؤخرًا، لذلك قد تكون بعض المؤشرات غير مكتملة.'
                : sprintf('لم تتحدث بيانات %s مؤخرًا، لذلك قد تكون بعض المؤشرات غير مكتملة.', $this->arabicList($failing)),
        ]];
    }

    /**
     * «الوصول ومشاهدات الفيديو», not «الوصول، مشاهدات الفيديو».
     *
     * A comma-separated list reads as a machine listing its fields. The conjunction before the last
     * item is what makes the sentence sound like it was written by somebody, which is the whole
     * point of the copy in this file.
     *
     * @param  list<string>  $items
     */
    private function arabicList(array $items): string
    {
        if (count($items) < 2) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode('، ', $items).' و'.$last;
    }
}
