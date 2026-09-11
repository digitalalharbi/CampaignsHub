<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Services\CampaignRelevance;
use PHPUnit\Framework\TestCase;

/**
 * CAMPAIGNS-LEDGER-001 — the same relevance rule the browser has, so a page can be cut from it.
 *
 * The campaigns workspace opens on what is running. Paginating while that ordering lived only in the
 * browser would have meant «the most relevant of the twenty-five newest», with everything behind the
 * boundary invisible — which is why this had to move before the page could be bounded.
 *
 * These are the cases `campaignRelevance.ts` is held to, asserted here so the two cannot disagree
 * about which campaign leads.
 */
final class CampaignRelevanceTest extends TestCase
{
    private CampaignRelevance $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new CampaignRelevance;
    }

    public function test_a_finished_campaign_is_stopped_however_much_it_spent(): void
    {
        foreach (['paused', 'completed', 'archived'] as $status) {
            $this->assertSame('stopped', $this->rule->of(
                ['status' => $status, 'last_active_on' => '2026-08-30'],
                '2026-08-30',
            ));
        }
    }

    /** A platform that said nothing about the state has not said the campaign ended. */
    public function test_an_unknown_state_is_judged_by_activity_not_read_as_stopped(): void
    {
        $this->assertSame('serving', $this->rule->of(['status' => null, 'last_active_on' => '2026-08-29'], '2026-08-30'));
        $this->assertSame('idle', $this->rule->of(['status' => 'unknown', 'last_active_on' => null], '2026-08-30'));
    }

    public function test_activity_within_three_days_is_serving_and_older_is_idle(): void
    {
        $this->assertSame('serving', $this->rule->of(['status' => 'active', 'last_active_on' => '2026-08-27'], '2026-08-30'));
        $this->assertSame('idle', $this->rule->of(['status' => 'active', 'last_active_on' => '2026-08-26'], '2026-08-30'));
    }

    /**
     * Serving first, then dark, then stopped — and a stopped campaign does not lead on spend.
     *
     * This is the ordering defect stated as a rule: a finished campaign that outspent every running
     * one used to head the operational list, so the first thing an operator saw was a campaign they
     * could do nothing about.
     */
    public function test_a_big_spending_stopped_campaign_does_not_outrank_a_small_serving_one(): void
    {
        $order = $this->rule->order([
            ['campaign_id' => 'stopped-big', 'status' => 'completed', 'last_active_on' => '2026-08-30', 'spend' => 900_000],
            ['campaign_id' => 'serving-small', 'status' => 'active', 'last_active_on' => '2026-08-30', 'spend' => 10],
            ['campaign_id' => 'dark-mid', 'status' => 'active', 'last_active_on' => '2026-07-01', 'spend' => 5_000],
        ], '2026-08-30');

        $this->assertSame(['serving-small', 'dark-mid', 'stopped-big'], $order);
    }

    /** Equal campaigns keep a stable order, so a list does not reshuffle between pages. */
    public function test_equal_campaigns_are_ordered_by_a_key_that_cannot_move(): void
    {
        $rows = [
            ['campaign_id' => 'b', 'status' => 'active', 'last_active_on' => '2026-08-30', 'spend' => 100],
            ['campaign_id' => 'a', 'status' => 'active', 'last_active_on' => '2026-08-30', 'spend' => 100],
        ];

        $this->assertSame(['a', 'b'], $this->rule->order($rows, '2026-08-30'));
        $this->assertSame(['a', 'b'], $this->rule->order(array_reverse($rows), '2026-08-30'));
    }
}
