<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

/**
 * Is this creative wearing out? (§15.9)
 *
 * ## Why this is not a threshold
 *
 * «CTR fell 20% ⇒ fatigued» is the rule everybody writes first, and it is wrong in both directions:
 * it condemns a creative whose CTR moved from 4.1% to 3.2% over a quiet week, and it clears one whose
 * frequency has doubled while its cost per order climbed 60%. Fatigue is a PATTERN — the same
 * audience seeing the same thing until it stops working — and no single number carries it.
 *
 * So this weighs several signals, each of which must have data on BOTH sides of the comparison to
 * count at all, and reports:
 *
 *   - the verdict,
 *   - every signal that fired, with its actual movement,
 *   - and, when it declines to judge, exactly what was missing.
 *
 * ## Insufficient data is a verdict, not a fallback
 *
 * A creative with four active days, or with no previous period to compare against, gets
 * `insufficient_data` — never `stable`. Those two look the same on a dashboard and mean opposite
 * things: one is «we looked and it is fine», the other is «we cannot tell yet». Printing the first
 * when we mean the second is how somebody keeps spending on a creative nobody has actually assessed.
 */
final class CreativeFatigue
{
    public const IMPROVING = 'improving';

    public const STABLE = 'stable';

    public const WATCH = 'watch';

    public const FATIGUED = 'fatigued';

    public const INSUFFICIENT = 'insufficient_data';

    /** Below this many days of delivery, no verdict is offered at all. */
    private const MIN_ACTIVE_DAYS = 7;

    /** Below this many impressions, movement is noise rather than a trend. */
    private const MIN_IMPRESSIONS = 1000.0;

    /** A signal must move at least this much to count — smaller swings are ordinary variance. */
    private const MATERIAL = 0.15;

    /**
     * @param  array<string, mixed>  $current  figures from `CreativeMetrics::forCreatives()`
     * @param  array<string, mixed>|null  $previous  the same window immediately before, or null
     * @return array{
     *     status: string,
     *     score: int,
     *     signals: list<array{key: string, direction: string, change: float, current: float|null, previous: float|null}>,
     *     missing: list<string>,
     *     note_ar: string,
     *     note_en: string
     * }
     */
    public function assess(array $current, ?array $previous): array
    {
        $missing = $this->whatIsMissing($current, $previous);

        if ($missing !== []) {
            return [
                'status' => self::INSUFFICIENT,
                'score' => 0,
                'signals' => [],
                'missing' => $missing,
                'note_ar' => 'لا تكفي البيانات للحكم على هذا المحتوى بعد.',
                'note_en' => 'There is not enough data to judge this creative yet.',
            ];
        }

        /** @var array<string, mixed> $previous */
        $signals = [];
        $score = 0;

        // Each entry: [key, higher is WORSE?, weight]. Weight reflects how directly the signal speaks
        // to «the same people are seeing this too often», not how easy it is to measure.
        $checks = [
            ['frequency', true, 2],
            ['ctr', false, 2],
            ['cpc', true, 1],
            ['cpa', true, 2],
            ['conversion_rate', false, 2],
            ['view_rate', false, 1],
            ['completion_rate', false, 1],
        ];

        foreach ($checks as [$key, $higherIsWorse, $weight]) {
            $now = $current[$key] ?? null;
            $before = $previous[$key] ?? null;

            if (! is_numeric($now) || ! is_numeric($before) || (float) $before == 0.0) {
                continue;
            }

            $change = round(((float) $now - (float) $before) / abs((float) $before), 4);

            if (abs($change) < self::MATERIAL) {
                continue;
            }

            $worse = $higherIsWorse ? $change > 0 : $change < 0;

            $signals[] = [
                'key' => $key,
                'direction' => $worse ? 'worse' : 'better',
                'change' => $change,
                'current' => (float) $now,
                'previous' => (float) $before,
            ];

            $score += $worse ? $weight : -$weight;
        }

        /*
         * Spending more for the same results — the signal that catches what the ratios miss.
         *
         * A creative can hold its CTR and its CPA steady while its spend doubles and its orders do
         * not, which is money going in with nothing coming back. It is checked separately because it
         * is a relationship between two figures rather than a movement in one.
         */
        $spendUp = $this->change($current['spend'] ?? null, $previous['spend'] ?? null);
        $resultsUp = $this->change($current['conversions'] ?? null, $previous['conversions'] ?? null);
        if ($spendUp !== null && $resultsUp !== null && $spendUp > self::MATERIAL && $resultsUp < 0.02) {
            $signals[] = [
                'key' => 'spend_without_results',
                'direction' => 'worse',
                'change' => round($spendUp, 4),
                'current' => (float) $current['spend'],
                'previous' => (float) $previous['spend'],
            ];
            $score += 2;
        }

        return [
            'status' => $this->verdict($score),
            'score' => $score,
            'signals' => $signals,
            'missing' => [],
            'note_ar' => $this->noteAr($this->verdict($score), $signals),
            'note_en' => $this->noteEn($this->verdict($score), $signals),
        ];
    }

    /**
     * What is missing, named — so the UI can say «needs 3 more days» rather than «no data».
     *
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    private function whatIsMissing(array $current, ?array $previous): array
    {
        $missing = [];

        if ($previous === null) {
            $missing[] = 'previous_period';
        }

        if ((int) ($current['active_days'] ?? 0) < self::MIN_ACTIVE_DAYS) {
            $missing[] = 'active_days';
        }

        $impressions = $current['impressions'] ?? null;
        if (! is_numeric($impressions) || (float) $impressions < self::MIN_IMPRESSIONS) {
            $missing[] = 'impressions';
        }

        return $missing;
    }

    private function change(mixed $now, mixed $before): ?float
    {
        if (! is_numeric($now) || ! is_numeric($before) || (float) $before == 0.0) {
            return null;
        }

        return ((float) $now - (float) $before) / abs((float) $before);
    }

    private function verdict(int $score): string
    {
        return match (true) {
            $score >= 5 => self::FATIGUED,
            $score >= 2 => self::WATCH,
            $score <= -2 => self::IMPROVING,
            default => self::STABLE,
        };
    }

    /** @param list<array{key: string, direction: string, change: float}> $signals */
    private function noteEn(string $status, array $signals): string
    {
        $worse = array_values(array_filter($signals, static fn (array $s): bool => $s['direction'] === 'worse'));

        return match ($status) {
            self::FATIGUED => 'Several signals moved the wrong way at once: '.$this->list($worse).'.',
            self::WATCH => 'Worth watching — '.$this->list($worse).'.',
            self::IMPROVING => 'Performing better than the previous period.',
            default => 'Holding steady against the previous period.',
        };
    }

    /** @param list<array{key: string, direction: string, change: float}> $signals */
    private function noteAr(string $status, array $signals): string
    {
        $worse = array_values(array_filter($signals, static fn (array $s): bool => $s['direction'] === 'worse'));

        return match ($status) {
            self::FATIGUED => 'تحرّكت عدة مؤشرات في الاتجاه الخاطئ معًا: '.$this->list($worse).'.',
            self::WATCH => 'يستحق المتابعة — '.$this->list($worse).'.',
            self::IMPROVING => 'أداؤه أفضل من الفترة السابقة.',
            default => 'مستقر مقارنة بالفترة السابقة.',
        };
    }

    /** @param list<array{key: string, change: float}> $signals */
    private function list(array $signals): string
    {
        return implode(', ', array_map(
            static fn (array $s): string => $s['key'].' '.($s['change'] > 0 ? '+' : '').round($s['change'] * 100).'%',
            $signals,
        ));
    }
}
