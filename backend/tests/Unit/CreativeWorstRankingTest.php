<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\CreativeRankingService;
use Tests\TestCase;

/**
 * REPORT-WORST-CREATIVES-001 — a report that lists only winners never says what to stop.
 */
final class CreativeWorstRankingTest extends TestCase
{
    private CreativeRankingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CreativeRankingService;
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        return [
            ['campaign_name' => 'Strong', 'spend' => 1000, 'roas' => 6.0, 'cpa' => 20.0, 'ctr' => 0.04, 'cpm' => 12.0],
            ['campaign_name' => 'Middling', 'spend' => 1000, 'roas' => 3.0, 'cpa' => 40.0, 'ctr' => 0.02, 'cpm' => 20.0],
            ['campaign_name' => 'Weak', 'spend' => 1000, 'roas' => 0.4, 'cpa' => 90.0, 'ctr' => 0.005, 'cpm' => 60.0],
        ];
    }

    public function test_the_worst_is_the_far_end_of_the_same_ranking(): void
    {
        $best = $this->service->rank('sales', $this->items());
        $worst = $this->service->worst('sales', $this->items());

        $this->assertSame('Strong', $best[0]['campaign_name']);
        $this->assertSame('Weak', $worst[0]['campaign_name']);
    }

    /** An awareness creative is judged on CPM, so its «worst» is the most expensive reach. */
    public function test_worst_follows_the_objective_rather_than_a_fixed_metric(): void
    {
        $worst = $this->service->worst('awareness', $this->items());

        $this->assertSame('Weak', $worst[0]['campaign_name']);
        /*
         * The Arabic metric name, not the Latin acronym. The sentence is built from `RankingMetric`
         * now — the same source the leaders' reason already used — so both ends of the list read the
         * same way and a metric added to a layout is explained without editing the reason builder.
         * The acronym went with the hardcoded phrasing whose `default` arm named ROAS for every
         * objective it had not been taught.
         */
        $this->assertStringContainsString('تكلفة الألف ظهور', $worst[0]['reason']);
    }

    /**
     * The rule this exists to protect. A creative the platform did not measure sorts last in
     * `rank()` so an absence cannot win — and by the same reasoning it must not lose. Naming it the
     * worst performer in a client's report would be a claim nobody made.
     */
    public function test_a_creative_with_no_figure_is_excluded_rather_than_called_the_worst(): void
    {
        $items = $this->items();
        $items[] = ['campaign_name' => 'Unmeasured', 'spend' => 5000, 'roas' => null, 'cpa' => null, 'ctr' => null, 'cpm' => null];

        $worst = $this->service->worst('sales', $items);
        $names = array_column($worst, 'campaign_name');

        $this->assertNotContains('Unmeasured', $names, 'An unmeasured creative was reported as the worst performer.');
        $this->assertSame('Weak', $worst[0]['campaign_name']);
    }

    /** Nothing that did not spend is judged: a creative with no spend has bought nothing to judge. */
    public function test_creatives_with_no_spend_are_not_ranked(): void
    {
        $items = $this->items();
        $items[] = ['campaign_name' => 'Never ran', 'spend' => 0, 'roas' => 0.01, 'cpa' => 900.0, 'ctr' => 0.0, 'cpm' => 900.0];

        $names = array_column($this->service->worst('sales', $items), 'campaign_name');

        $this->assertNotContains('Never ran', $names);
    }

    public function test_it_returns_nothing_when_no_creative_was_measured(): void
    {
        $unmeasured = [['campaign_name' => 'A', 'spend' => 100, 'roas' => null, 'cpa' => null, 'ctr' => null, 'cpm' => null]];

        $this->assertSame([], $this->service->worst('sales', $unmeasured));
    }

    public function test_it_states_the_figure_that_put_a_creative_on_the_list(): void
    {
        $worst = $this->service->worst('sales', $this->items());

        $this->assertStringContainsString('العائد على الإنفاق', $worst[0]['reason']);
        $this->assertStringContainsString('0.40', $worst[0]['reason']);
    }
}
