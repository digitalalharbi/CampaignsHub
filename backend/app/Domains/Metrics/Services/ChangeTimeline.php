<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use Illuminate\Support\Carbon;

/**
 * ANALYTICS-DIFFERENTIATION-001 — the days worth asking about.
 *
 * ## The question a trend line does not answer
 *
 * A thirty-day spend line shows every day equally. A reader looking for «when did this start» has to
 * eyeball it, and eyeballing is exactly what people are bad at: a 40% jump on a low day looks smaller
 * than a 10% drift on a high one. So this names the days that deviate from what the series itself
 * had been doing, and says by how much — which is a diagnostic statement, not a drawing.
 *
 * ## How a day becomes notable, and why it is a trailing baseline
 *
 * Each day is compared against the MEDIAN and the median absolute deviation of the days BEFORE it,
 * not against the window's own mean. Two reasons, and both are about not inventing findings:
 *
 * - a mean is dragged by the very spike being tested, so a large enough anomaly hides itself;
 * - a trailing window is the only one a reader could have acted on. «This Tuesday was unusual given
 *   the fortnight before it» is a sentence about their campaign; «unusual given the whole month,
 *   including the ten days after» is hindsight dressed as a signal.
 *
 * MAD rather than standard deviation for the same reason — one outlier inflates σ enough to swallow
 * the next one.
 *
 * ## What it refuses
 *
 * A series too short to have a baseline, a series that never varies, and any day whose figure is
 * WITHHELD rather than measured. All three return no findings rather than a weak one: a diagnostic
 * surface that always produces something teaches its reader to ignore it.
 */
final class ChangeTimeline
{
    /** Days of history a point needs before it can be judged against anything. */
    private const BASELINE_MIN = 5;

    /**
     * How far from the trailing median a day must sit, in MADs, to be worth naming.
     *
     * 3.5 is the conventional threshold for the modified z-score. It is deliberately not tuned to
     * produce findings on this product's demo data — a threshold chosen to make a screenshot look
     * busy is how a diagnostic surface becomes decoration.
     */
    private const THRESHOLD = 3.5;

    /** 1/0.6745 — the constant that puts a MAD on the same scale as a standard deviation. */
    private const MAD_TO_SIGMA = 1.4826;

    public function __construct(private readonly MetricsAggregator $metrics) {}

    /**
     * The notable days for a set of metrics, newest first.
     *
     * @param  list<string>  $metrics
     * @return array{
     *     points: list<array{date: string, metric: string, value: float, baseline: float, deviation: float, direction: string}>,
     *     reason: ?string,
     *     days: int
     * }
     */
    public function build(Carbon $from, Carbon $to, array $metrics = ['spend', 'conversions', 'clicks']): array
    {
        $series = $this->metrics->timeseries($from, $to);

        if (count($series) < self::BASELINE_MIN + 1) {
            return ['points' => [], 'reason' => 'window_too_short_to_have_a_baseline', 'days' => count($series)];
        }

        $points = [];

        foreach ($metrics as $metric) {
            foreach ($this->anomaliesIn($series, $metric) as $point) {
                $points[] = $point;
            }
        }

        usort($points, static fn ($a, $b) => [$b['date'], abs($b['deviation'])] <=> [$a['date'], abs($a['deviation'])]);

        return [
            'points' => $points,
            'reason' => $points === [] ? 'no_day_departed_from_its_own_baseline' : null,
            'days' => count($series),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $series
     * @return list<array{date: string, metric: string, value: float, baseline: float, deviation: float, direction: string}>
     */
    private function anomaliesIn(array $series, string $metric): array
    {
        $out = [];

        foreach ($series as $i => $row) {
            if ($i < self::BASELINE_MIN) {
                continue;
            }

            $value = $row[$metric] ?? null;

            // Withheld, not zero — a day the product refused to state is not a day that fell to nothing.
            if (! is_numeric($value)) {
                continue;
            }

            $history = [];
            foreach (array_slice($series, 0, $i) as $prior) {
                if (is_numeric($prior[$metric] ?? null)) {
                    $history[] = (float) $prior[$metric];
                }
            }

            if (count($history) < self::BASELINE_MIN) {
                continue;
            }

            $median = $this->median($history);
            $mad = $this->median(array_map(static fn ($v) => abs($v - $median), $history));

            // A series that never varied has no scale to be unusual against.
            if ($mad <= 0.0) {
                continue;
            }

            $deviation = ((float) $value - $median) / (self::MAD_TO_SIGMA * $mad);

            if (abs($deviation) < self::THRESHOLD) {
                continue;
            }

            $out[] = [
                'date' => (string) $row['date'],
                'metric' => $metric,
                'value' => round((float) $value, 2),
                'baseline' => round($median, 2),
                'deviation' => round($deviation, 2),
                'direction' => (float) $value >= $median ? 'up' : 'down',
            ];
        }

        return $out;
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);

        if ($n === 0) {
            return 0.0;
        }

        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
