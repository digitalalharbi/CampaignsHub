<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\CRM\Services\ExecutiveOperations;
use PHPUnit\Framework\TestCase;

/**
 * EXECUTIVE-OPS-DASHBOARD-001 — the join, and the one figure it exists to produce.
 *
 * The spend is on the dashboard, the leads are in the inbox, the follow-up is in the workspace. Each
 * is correct and none of them answers «what did a lead cost us», which is the question a manager
 * opens all three to work out by hand.
 *
 * These cases are about the JOIN rather than about either side: the cost per lead, and what it does
 * when one of the two sides cannot be stated.
 */
final class ExecutiveOperationsTest extends TestCase
{
    /**
     * The join takes two ANSWERS, so the test can hand it two answers.
     *
     * No database and no stubbed service: `ExecutiveOperations` cannot compute a lead figure of its
     * own, which is the property that makes it testable this way and the reason it was written this
     * way rather than taking the query.
     */
    private function build(array $spend, array $work): array
    {
        return (new ExecutiveOperations)->build($work, $spend);
    }

    private function work(array $over = []): array
    {
        return [
            'window' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
            'received' => 50, 'unassigned' => 0, 'contacted' => 40, 'not_contacted' => 5,
            'qualified' => 20, 'appointments' => 8, 'won' => 4, 'lost' => 6, 'invalid' => 5,
            'overdue' => 0, 'overdue_scope' => 'all_open',
            'contact_rate' => 0.888, 'qualification_rate' => 0.5,
            'appointment_rate' => 0.4, 'win_rate' => 0.2,
            'first_response' => ['median_minutes' => 15, 'measured' => 40, 'of' => 50],
            ...$over,
        ];
    }

    /** The figure the join exists for: spend over the leads that were real people. */
    public function test_it_states_what_a_lead_cost(): void
    {
        $out = $this->build(['spend' => 9_000.0, 'currency' => 'SAR'], $this->work());

        // 45 contactable leads (50 received, 5 of them junk), not 50.
        $this->assertSame(200.0, $out['cost_per_lead']['amount']);
        $this->assertSame('SAR', $out['cost_per_lead']['currency']);
        $this->assertSame(450.0, $out['cost_per_qualified_lead']['amount']);
    }

    /**
     * A spend the money contract will not state produces no cost per lead.
     *
     * Not a cost computed from the converted half, and not zero. Somebody divides by this number
     * when deciding next month's budget, and half a spend over all the leads is a figure that looks
     * like a bargain.
     */
    public function test_a_partial_spend_produces_no_cost_at_all(): void
    {
        $out = $this->build(
            ['spend' => 4_000.0, 'spend_withheld_rows' => 3, 'currency' => 'SAR'],
            $this->work(),
        );

        $this->assertNull($out['spend']['amount']);
        $this->assertSame('partial', $out['spend']['reason']);
        $this->assertNull($out['cost_per_lead']['amount']);
        $this->assertSame('partial', $out['cost_per_lead']['reason']);
    }

    /** Two currencies in one window is not a total, and is not a cost either. */
    public function test_a_mixed_currency_window_states_neither(): void
    {
        $out = $this->build(
            ['spend' => 4_000.0, 'money_original_currencies' => 2, 'currency' => 'SAR'],
            $this->work(),
        );

        $this->assertSame('mixed_currency', $out['spend']['reason']);
        $this->assertNull($out['cost_per_lead']['amount']);
    }

    /**
     * Spend with no leads has no cost per lead.
     *
     * «Infinite» is not a number anybody can act on and zero would say the leads were free — the
     * honest output is the refusal and its reason.
     */
    public function test_spend_with_no_leads_says_so(): void
    {
        $out = $this->build(
            ['spend' => 9_000.0, 'currency' => 'SAR'],
            $this->work(['received' => 0, 'invalid' => 0, 'qualified' => 0, 'contacted' => 0, 'not_contacted' => 0]),
        );

        $this->assertNull($out['cost_per_lead']['amount']);
        $this->assertSame('no_leads', $out['cost_per_lead']['reason']);
    }

    /**
     * What needs attention is a list of sentences, not a colour.
     *
     * A card turned amber has said something a reader must decode. These have a subject, a count and
     * a domain, and they are ordered by how fast each decays.
     */
    public function test_it_names_what_needs_attention(): void
    {
        $out = $this->build(
            ['spend' => 9_000.0, 'currency' => 'SAR'],
            $this->work(['unassigned' => 7, 'overdue' => 3]),
        );

        $kinds = array_column($out['attention'], 'kind');

        $this->assertSame(['unassigned_leads', 'overdue_follow_up', 'never_contacted'], $kinds);
        $this->assertSame(7, $out['attention'][0]['count']);
    }

    /**
     * An unstateable spend is itself worth saying on this screen.
     *
     * Every cost figure above it is missing for the same reason, and a manager who does not know
     * that reads the gaps as «the leads cost nothing».
     */
    public function test_an_unstateable_spend_is_raised_as_attention(): void
    {
        $out = $this->build(
            ['spend' => 4_000.0, 'spend_withheld_rows' => 2, 'currency' => 'SAR'],
            $this->work(['unassigned' => 0, 'overdue' => 0, 'not_contacted' => 0]),
        );

        $this->assertSame([['kind' => 'spend_not_comparable', 'domain' => 'money', 'count' => 1]], $out['attention']);
    }

    /** A quiet window raises nothing — an empty list is the correct output, not a reassurance. */
    public function test_a_clean_window_raises_nothing(): void
    {
        $out = $this->build(
            ['spend' => 9_000.0, 'currency' => 'SAR'],
            $this->work(['unassigned' => 0, 'overdue' => 0, 'not_contacted' => 0]),
        );

        $this->assertSame([], $out['attention']);
    }

    /**
     * Zero spend produces no cost per lead.
     *
     * The aggregator returns 0 for a window with no spend rows at all, and «0 per lead» reads as
     * «these leads were free» — a claim about the advertising rather than about the absence of it.
     */
    public function test_a_window_where_nothing_ran_reports_no_cost(): void
    {
        $out = $this->build(['spend' => 0.0, 'currency' => 'SAR'], $this->work());

        $this->assertNull($out['cost_per_lead']['amount']);
        $this->assertSame('no_spend', $out['cost_per_lead']['reason']);
    }
}
