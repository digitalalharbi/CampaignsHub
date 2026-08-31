<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the funnel's shape, applied to the ads.
 *
 * ## What the section was
 *
 * A ranked grid: the strongest ads, sometimes the weakest beside them, each with its figures. The
 * ranking is the SIGNAL and nothing else, and the reader is left to answer the rest — what the range
 * between the two ends is measured on, why one is ahead, which figures say so, and what to do about
 * it — from a row of numbers, every time they look.
 *
 * ## The range needs the same metric at both ends
 *
 * The ranker chooses ONE metric per objective and both ends are read on it: naming a best by return
 * and a worst by cost per result would be a range with two different units, which reads as a
 * comparison and is not one.
 *
 * ## Nothing here is a benchmark, and nothing is a promise
 *
 * The action names the two ads and stops. «Pause the weakest» is a decision with a client's money in
 * it and a creative the client may have paid to make; the product's part is to put the two in front
 * of the person who decides, with the figure that separates them.
 */
final class AdsExplanation
{
    /**
     * @param  list<array<string,mixed>>  $best  from {@see CreativeRankingService::rank()}
     * @param  list<array<string,mixed>>  $worst  from {@see CreativeRankingService::worst()}
     * @return array<string,mixed>
     */
    public function explain(array $best, array $worst, string $objective): array
    {
        $strongest = $best[0] ?? null;
        $weakest = $worst[0] ?? null;

        /*
         * A range needs two ENDS, and they must be different ads.
         *
         * With two or three ads on an objective the ranker's best is arithmetically also its worst,
         * and printing one ad as both ends of a range is a comparison with itself.
         */
        $sameAd = $strongest !== null && $weakest !== null
            && (string) ($strongest['id'] ?? '') === (string) ($weakest['id'] ?? '');

        if ($strongest === null || $weakest === null || $sameAd) {
            return [
                'signal' => null,
                'context' => null,
                'explanation' => null,
                'evidence' => [],
                'action' => null,
                'silent_reason' => match (true) {
                    $best === [] => 'no_ad_could_be_ranked',
                    $sameAd, $worst === [] => 'only_one_ad_is_comparable',
                    default => 'no_ad_could_be_ranked',
                },
            ];
        }

        /*
         * The metric BOTH ends are read on. The ranker states it per row; where the two disagree —
         * which can happen when one row carries a metric the other does not — there is no single
         * range to state, and saying so is the honest answer.
         */
        $metric = (string) ($strongest['metric'] ?? '');
        if ($metric === '' || $metric !== (string) ($weakest['metric'] ?? '')) {
            return [
                'signal' => null,
                'context' => null,
                'explanation' => null,
                'evidence' => [],
                'action' => null,
                'silent_reason' => 'the_two_ends_were_measured_on_different_metrics',
            ];
        }

        return [
            'signal' => [
                'metric' => $metric,
                'best' => ['ad' => $strongest['name'] ?? null, 'value' => $strongest['value'] ?? null],
                'worst' => ['ad' => $weakest['name'] ?? null, 'value' => $weakest['value'] ?? null],
            ],
            'context' => ['objective' => $objective, 'ads_ranked' => count($best)],
            'explanation' => [
                'ar' => 'الإعلانان اشتُريا لنفس الهدف وقيسا على المقياس نفسه، فالفارق بينهما فارق في المادة لا في ما طُلب منها.',
                'en' => 'Both ads were bought for the same objective and read on the same metric, so the distance between them is a difference in the creative rather than in what it was asked to do.',
            ],
            'evidence' => ['spend', $metric],
            'action' => [
                'ar' => "قارن «{$weakest['name']}» بـ«{$strongest['name']}» قبل أن يأخذ الأضعف مزيدًا من ميزانية هذا الهدف.",
                'en' => "Compare «{$weakest['name']}» against «{$strongest['name']}» before the weaker one takes more of this objective's budget.",
            ],
            'silent_reason' => null,
        ];
    }
}
