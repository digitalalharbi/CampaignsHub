<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Reports\Services\AdsExplanation;

/**
 * ANALYTICS-DIFFERENTIATION-001 — content intelligence, which is not the ranked list.
 *
 * ## What the tab was
 *
 * A table of ads ordered by a figure. That answers «which ad did best», and a reader who wants to
 * spend differently tomorrow cannot act on it: the winner is one asset, it is already made, and
 * being told its name does not say what to commission next. The ranking is a leaderboard, and a
 * leaderboard is a Dashboard question — what is happening now — printed on the Analytics surface.
 *
 * ## What content intelligence asks instead
 *
 * Not «which asset won» but «WHAT KIND of asset earns its money here» — the one content question
 * whose answer transfers to the next brief. Formats are the axis the provider actually reports and
 * an agency actually decides on: whether to cut another video, shoot more stills, or build a
 * carousel is a real allocation, and the spend already sitting behind the weaker answer is the size
 * of the mistake being repeated.
 *
 * ## Both ends of a comparison are read on ONE metric, chosen by the objective
 *
 * The same rule {@see AdsExplanation} holds for two ads: naming video
 * the winner on return and image the loser on cost is a comparison with two units. The metric is
 * taken from the objective's own headline set, and only a metric EVERY compared format could answer
 * is eligible — a format whose figure is missing is not a format that scored zero.
 *
 * `spend` and `impressions` are never the verdict. They are how much was bought, not how it did, and
 * a comparison won by whichever format was funded most is a tautology.
 */
final class ContentIntelligence
{
    /** Metrics where the SMALLER figure is the better one. */
    private const LOWER_IS_BETTER = [
        'cpm', 'cpc', 'cpa', 'cost_per_view', 'cost_per_lpv', 'cpe',
    ];

    /**
     * The ONLY metrics a format comparison may be decided on — every one of them normalised.
     *
     * ## Why an allow-list, and why volumes are not on it
     *
     * Formats are groups of unequal size. On the account this was first read against, video carried
     * thirty creatives and carousel fifteen, and the first version of this class ranked them on
     * `clicks` — so video «won» by 1,050 to 390, which is very largely the arithmetic of having
     * twice as many ads running. That is the same tautology as ranking by spend, one step removed,
     * and it is worse for being harder to spot: a reader has no reason to suspect a click count of
     * being a headcount.
     *
     * A comparison between groups of different sizes can only be settled on a figure that is
     * already per-something — a rate, a cost per outcome, or a per-order average. Those are exactly
     * the DERIVED metrics, and the two derived figures that are NOT normalised are deliberately
     * absent: `orders` is a count wearing a derived name, and `frequency` describes the audience's
     * exposure rather than judging the creative.
     *
     * A denied list would have to be right about every metric the product will ever add. This has to
     * be right only about the ones it will ever rank on, and a new metric is excluded until somebody
     * decides it is comparable — which is the safe direction for it to fail in.
     */
    private const MAY_DECIDE = [
        'ctr', 'cpc', 'cpm', 'cpa', 'roas', 'conversion_rate', 'aov', 'cost_per_view',
        'view_rate', 'completion_rate', 'hook_rate', 'cost_per_lpv', 'engagement_rate', 'cpe',
    ];

    /** Below this a «format» is one or two assets, and its aggregate is an anecdote. */
    private const MINIMUM_CREATIVES = 2;

    public function __construct(private readonly CreativeMetrics $metrics) {}

    /**
     * @param  list<array{id: string, format: ?string}>  $creatives
     * @param  array<string, array<string, mixed>|null>  $figures  keyed by creative id, from CreativeMetrics::forCreatives()
     * @return array<string, mixed>
     */
    public function byFormat(array $creatives, array $figures, ?string $objective): array
    {
        /*
         * Grouped before anything is judged, so «this format reported nothing» is distinguishable
         * from «this format was never run» — the first is a gap in the data and the second is a gap
         * in the media plan, and they lead to opposite decisions.
         */
        $sets = [];
        foreach ($creatives as $creative) {
            $format = $this->normalise($creative['format'] ?? null);
            $row = $figures[$creative['id']] ?? null;

            if (! is_array($row)) {
                continue;
            }

            $sets[$format][] = $row;
        }

        if ($sets === []) {
            return $this->refuse('no_creative_reported_in_this_period');
        }

        /*
         * A format carried by a single asset is that asset, wearing the name of a category. Held out
         * rather than dropped: «one video ran, which is not enough to speak for video» is a true
         * statement about the account and the reader deserves to see it.
         */
        $tooFew = [];
        foreach ($sets as $format => $rows) {
            if (count($rows) < self::MINIMUM_CREATIVES) {
                $tooFew[] = ['format' => $format, 'creatives' => count($rows)];
                unset($sets[$format]);
            }
        }

        if (count($sets) < 2) {
            return $this->refuse('only_one_format_ran_enough_to_compare', $tooFew);
        }

        $totals = [];
        foreach ($sets as $format => $rows) {
            $aggregate = $this->metrics->aggregate($rows);

            if ($aggregate === null) {
                continue;
            }

            $totals[$format] = $aggregate;
        }

        if (count($totals) < 2) {
            return $this->refuse('only_one_format_ran_enough_to_compare', $tooFew);
        }

        $metric = $this->comparableMetric($objective, $totals);

        if ($metric === null) {
            return $this->refuse('no_metric_every_format_could_answer', $tooFew);
        }

        $lowerIsBetter = in_array($metric, self::LOWER_IS_BETTER, true);

        $rows = [];
        foreach ($totals as $format => $aggregate) {
            $rows[] = [
                'format' => $format,
                'value' => (float) $aggregate[$metric],
                'spend' => is_numeric($aggregate['spend'] ?? null) ? (float) $aggregate['spend'] : null,
                'creatives' => (int) ($aggregate['creatives'] ?? 0),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $lowerIsBetter
            ? $a['value'] <=> $b['value']
            : $b['value'] <=> $a['value']);

        $best = $rows[0];
        $worst = $rows[count($rows) - 1];

        /*
         * The share of spend standing behind everything that is NOT the leading format.
         *
         * This is the figure that makes the reading an allocation question rather than a fact: video
         * winning on cost per result matters differently when 8% of the budget is on video than when
         * 80% is. Null — not zero — where any format withheld its spend, because a share computed
         * over an incomplete denominator overstates itself and looks like an answer.
         */
        $spends = array_column($rows, 'spend');
        $someWithheld = in_array(null, $spends, true);
        $totalSpend = $someWithheld ? null : array_sum($spends);
        $behindTheRest = $totalSpend !== null && $totalSpend > 0.0
            ? ($totalSpend - (float) $best['spend']) / $totalSpend
            : null;

        /*
         * WHY the share is absent, which is not one reason but three.
         *
         * «A format withheld its spend» was printed for all of them, including the common case where
         * NO format reported any spend at all — an account whose provider does not break spend down
         * to the creative grain. Telling that reader one of their formats is withholding a figure
         * sends them looking for a fault that is not there.
         */
        $spendSilence = match (true) {
            ! $someWithheld && $totalSpend !== null && $totalSpend > 0.0 => null,
            count(array_filter($spends, static fn ($s): bool => $s !== null)) === 0 => 'no_spend_was_reported_at_this_grain',
            $someWithheld => 'a_format_withheld_its_spend',
            default => 'nothing_was_spent_in_this_period',
        };

        return [
            'metric' => $metric,
            'lower_is_better' => $lowerIsBetter,
            'objective' => $objective,
            'formats' => $rows,
            'best' => $best['format'],
            'worst' => $worst['format'],
            'share_of_spend_not_on_the_leading_format' => $behindTheRest,
            'why_no_spend_share' => $spendSilence,
            'too_few_to_speak_for_their_format' => $tooFew,
            'refusal' => null,
        ];
    }

    /**
     * The first of the objective's own headline metrics that EVERY compared format reported.
     *
     * Order is the family's, not this class's: the objective's first headline metric is the one the
     * campaign was bought to move, and falling back to a later one only because the first is missing
     * keeps the verdict as close to the buy as the data allows.
     *
     * @param  array<string, array<string, mixed>>  $totals
     */
    private function comparableMetric(?string $objective, array $totals): ?string
    {
        foreach ($this->metrics->headline($objective) as $metric) {
            if (! in_array($metric, self::MAY_DECIDE, true)) {
                continue;
            }

            $answeredByAll = true;
            foreach ($totals as $aggregate) {
                if (! is_numeric($aggregate[$metric] ?? null)) {
                    $answeredByAll = false;
                    break;
                }
            }

            if ($answeredByAll) {
                return $metric;
            }
        }

        return null;
    }

    /** A creative with no format recorded is «unlabelled», never quietly filed under image. */
    private function normalise(?string $format): string
    {
        $format = trim((string) $format);

        return $format === '' ? 'unlabelled' : strtolower($format);
    }

    /** @param list<array{format: string, creatives: int}> $tooFew */
    private function refuse(string $reason, array $tooFew = []): array
    {
        return [
            'metric' => null,
            'lower_is_better' => false,
            'objective' => null,
            'formats' => [],
            'best' => null,
            'worst' => null,
            'share_of_spend_not_on_the_leading_format' => null,
            'why_no_spend_share' => null,
            'too_few_to_speak_for_their_format' => $tooFew,
            'refusal' => $reason,
        ];
    }
}
