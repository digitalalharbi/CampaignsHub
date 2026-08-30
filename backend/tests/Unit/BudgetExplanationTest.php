<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Metrics\Services\BudgetExplanation;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the pacing table, read back in the funnel's own shape.
 *
 * The table gives the SIGNAL and stops: a column of percentages whose meaning the reader has to
 * reconstruct every time. This adds the four steps that make it a reading — what it is measured
 * against, why the distance exists, which figures say so, and what to do — and it must add them
 * without inventing a verdict.
 *
 * The two rules worth more than the arithmetic: a line whose pacing could NOT be computed is not a
 * slow line, and a range needs two ends.
 */
final class BudgetExplanationTest extends TestCase
{
    private function row(string $name, ?float $pace, string $basis = 'comparable'): array
    {
        return [
            'campaign_name' => $name,
            'budget' => 1000.0,
            'spent' => $pace === null ? null : 1000.0 * $pace,
            'pace' => $pace,
            'pacing_basis' => $basis,
        ];
    }

    private function explain(array $rows): array
    {
        return (new BudgetExplanation)->explain(
            $rows,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-30'),
        );
    }

    public function test_the_signal_is_the_range_this_account_produced(): void
    {
        $out = $this->explain([
            $this->row('Fast', 1.6),
            $this->row('Middle', 1.0),
            $this->row('Slow', 0.4),
        ]);

        $this->assertSame('pace', $out['signal']['metric']);
        $this->assertSame('Fast', $out['signal']['fastest']['campaign']);
        $this->assertSame('Slow', $out['signal']['slowest']['campaign']);
        $this->assertSame(3, $out['context']['lines']);
        $this->assertSame(['budget', 'spent', 'pace'], $out['evidence']);
        $this->assertNull($out['silent_reason']);
    }

    /**
     * Nothing here is a benchmark.
     *
     * A reader told that 1.6 is «bad» has been told something nobody in this system knows: whether a
     * campaign should be ahead of its plan is a decision with a client's money in it. The action
     * names both ends and stops.
     */
    public function test_it_states_no_verdict_and_no_threshold(): void
    {
        $out = $this->explain([$this->row('Fast', 1.6), $this->row('Slow', 0.4)]);

        $this->assertStringNotContainsStringIgnoringCase('bad', $out['action']['en']);
        $this->assertStringNotContainsStringIgnoringCase('healthy', $out['action']['en']);
        $this->assertStringContainsString('Fast', $out['action']['en']);
        $this->assertStringContainsString('Slow', $out['action']['en']);
    }

    /**
     * A line whose pacing could not be computed is NOT a slow line.
     *
     * Its spend is withheld, or its plan is in another currency. Counting it as zero would put the
     * one campaign nobody can measure at the slow end of the range — the most misleading place in
     * the reading for it to sit.
     */
    public function test_an_unmeasurable_line_is_not_the_slowest_line(): void
    {
        $out = $this->explain([
            $this->row('Fast', 1.6),
            $this->row('Slow', 0.4),
            $this->row('Withheld', null, 'complete_withheld'),
        ]);

        $this->assertSame('Slow', $out['signal']['slowest']['campaign']);
        $this->assertSame(2, $out['context']['lines']);
        // And the count is stated, because a reading over two of three lines is exactly that.
        $this->assertSame(1, $out['unmeasured_lines']);
    }

    /** A range needs two ends. One paced line is not a range, and it says which case it is. */
    public function test_one_line_is_not_a_range(): void
    {
        $out = $this->explain([$this->row('Only', 1.2)]);

        $this->assertNull($out['signal']);
        $this->assertNull($out['action']);
        $this->assertSame('only_one_line_has_a_pace', $out['silent_reason']);
    }

    /** «Nobody set a budget» and «nothing could be paced» are different sentences. */
    public function test_it_separates_no_budgets_from_nothing_measurable(): void
    {
        $this->assertSame('no_budgets_set', $this->explain([])['silent_reason']);

        $this->assertSame(
            'no_line_could_be_paced',
            $this->explain([$this->row('A', null, 'currency_mismatch'), $this->row('B', null, 'no_budget')])['silent_reason'],
        );
    }
}
