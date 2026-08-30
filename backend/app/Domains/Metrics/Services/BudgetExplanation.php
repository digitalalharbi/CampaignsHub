<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use Illuminate\Support\Carbon;

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the funnel's shape, applied to budget.
 *
 * ## The shape, and why it travels
 *
 * Signal → context → explanation → evidence → action. It is the sequence the funnel section uses and
 * the one this product is praised for, and it existed on exactly two surfaces. A table of pacing
 * percentages is not that sequence: it is the SIGNAL with the other four steps left to the reader,
 * who has to know what 1.6 means, what it is measured against, why it happened, which figures say so,
 * and what to do — every time they look.
 *
 * ## The signal is a RANGE this account produced, never a benchmark
 *
 * «The fastest line is spending 1.6× its plan and the slowest 0.4×» is arithmetic over these rows.
 * There is no «healthy pacing» figure here, no threshold that fires an alarm, and no industry
 * number: a reader told that 1.6 is «bad» has been told something nobody here knows. Whether a
 * campaign should be ahead of plan is a decision with a client's money in it, and the product's part
 * is to put both ends in front of the person making it.
 *
 * ## Silence has a reason, and the reason is not «no data»
 *
 * A window where one line has a budget has no range — a range needs two ends. A window where the
 * spend is withheld or in another currency has no pacing AT ALL, and saying «0%» there would be a
 * measurement of something nobody measured. Each of those is a different sentence, and the one that
 * applies travels in the signal's place.
 */
final class BudgetExplanation
{
    /**
     * @param  list<array<string,mixed>>  $pacing  rows from {@see MetricsAggregator::budgetPacing()}
     * @return array<string,mixed>
     */
    public function explain(array $pacing, Carbon $from, Carbon $to): array
    {
        /*
         * Only lines whose pacing was actually COMPUTED. A row whose spend is withheld or whose plan
         * is in another currency has no pace, and including it as a zero would put a campaign that
         * nobody can measure at the slow end of the range — the most misleading place for it.
         */
        $paced = array_values(array_filter(
            $pacing,
            static fn (array $r): bool => ($r['pacing_basis'] ?? null) === 'comparable' && $r['pace'] !== null,
        ));

        $unmeasured = count($pacing) - count($paced);

        if (count($paced) < 2) {
            return [
                'signal' => null,
                'context' => null,
                'explanation' => null,
                'evidence' => [],
                'action' => null,
                /*
                 * «One line cannot be a range» and «nothing here can be paced at all» are different
                 * facts, and an operator acts on them differently: the first waits, the second is a
                 * currency or a withheld figure somebody has to go and look at.
                 */
                'silent_reason' => $paced === []
                    ? ($unmeasured > 0 ? 'no_line_could_be_paced' : 'no_budgets_set')
                    : 'only_one_line_has_a_pace',
                'unmeasured_lines' => $unmeasured,
            ];
        }

        $fastest = $paced[0];
        $slowest = $paced[count($paced) - 1];

        foreach ($paced as $row) {
            if ((float) $row['pace'] > (float) $fastest['pace']) {
                $fastest = $row;
            }
            if ((float) $row['pace'] < (float) $slowest['pace']) {
                $slowest = $row;
            }
        }

        return [
            'signal' => [
                'metric' => 'pace',
                'fastest' => ['campaign' => $fastest['campaign_name'], 'value' => (float) $fastest['pace']],
                'slowest' => ['campaign' => $slowest['campaign_name'], 'value' => (float) $slowest['pace']],
            ],
            'context' => [
                'scope' => 'budget',
                'lines' => count($paced),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'explanation' => [
                'ar' => 'الإيقاع محسوب على ما مضى من الفترة لا على الفترة كاملة، فالفارق بين الطرفين فارق في سرعة الصرف مقابل الخطة، وليس حكمًا على النتيجة.',
                'en' => 'Pace is measured against the part of the window that has elapsed, not the whole of it, so the distance between the two ends is a difference in spending speed against plan — not a verdict on the result.',
            ],
            'evidence' => ['budget', 'spent', 'pace'],
            'action' => [
                'ar' => "قارن «{$fastest['campaign_name']}» بخطته قبل نهاية الفترة، و«{$slowest['campaign_name']}» يترك جزءًا من ميزانيته دون صرف.",
                'en' => "Check «{$fastest['campaign_name']}» against its plan before the window closes; «{$slowest['campaign_name']}» is leaving part of its budget unspent.",
            ],
            'silent_reason' => null,
            /*
             * Said even when there IS a range: a reading over four of nine lines is a reading over
             * four of nine lines, and the count is what tells the operator whether to trust it.
             */
            'unmeasured_lines' => $unmeasured,
        ];
    }
}
