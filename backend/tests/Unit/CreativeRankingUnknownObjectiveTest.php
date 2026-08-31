<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\CreativeRankingService;
use Tests\TestCase;

/**
 * ANALYTICS-OBJECTIVE-SYSTEM-001 — «`All` must never rank unlike objectives by one universal metric
 * such as ROAS».
 *
 * `CreativeRankingService::strategy()` broke that rule twice, and both were silent.
 *
 *   1. An objective string the map did not recognise fell to `default => Sales`, whose primary is
 *      ROAS. An awareness report whose objective this map had not learned was ordered by return on
 *      ad spend — and read as though somebody had chosen that.
 *   2. When nothing in the objective's layout was reported by anyone, the key fell through to the
 *      literal `'roas'`. That contradicted the contract it was calling: `resolveMetric` returns null
 *      ON PURPOSE, and its own docblock says «this scope cannot be ranked» is an answer, and a
 *      better one than an arbitrary order.
 *
 * The harm is specific to where this output goes. A report is the copy a client keeps, and an order
 * under the heading «الأفضل أداءً» reads as a judgement. Ordering creatives by a metric nobody
 * measured is not a weaker judgement; it is a fabricated one.
 */
final class CreativeRankingUnknownObjectiveTest extends TestCase
{
    private CreativeRankingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CreativeRankingService;
    }

    /**
     * Rows carrying revenue, under an objective this service has never heard of.
     *
     * The ROAS is deliberately decisive — if the service falls back to it, the order is obvious and
     * the assertion below fails loudly rather than by a hair.
     */
    private function withRevenue(): array
    {
        return [
            ['campaign_name' => 'Rich', 'spend' => 1000, 'roas' => 9.0],
            ['campaign_name' => 'Poor', 'spend' => 1000, 'roas' => 0.1],
        ];
    }

    public function test_an_unrecognised_objective_is_not_ranked_by_roas(): void
    {
        $ranked = $this->service->rank('some_objective_nobody_mapped', [
            ['campaign_name' => 'Rich', 'roas' => 9.0],
            ['campaign_name' => 'Poor', 'roas' => 0.1],
        ]);

        /*
         * No spend, no impressions, no clicks — nothing `Unknown`'s layout covers. Revenue is present
         * and must not be reached for: it belongs to Sales, and this objective is not Sales.
         */
        $this->assertSame([], $ranked, 'an unknown objective was ranked by revenue');
    }

    /**
     * The other end of the same list, and a third instance of the same defect.
     *
     * These rows DO report spend, which is in `Unknown`'s layout — so there is an order to give, and
     * it is by spend. What the sentence beside it used to say is the problem: `weakness()` was a
     * `match` on the objective whose `default` arm named ROAS, so a scope ranked on spend came back
     * reading «أقل عائد على الإنفاق (ROAS —)». An explanation naming a different number from the one
     * that produced the order leaves a reader unable to tell which of the two is wrong.
     */
    public function test_the_worst_is_explained_by_the_metric_that_ordered_it(): void
    {
        $worst = $this->service->worst('some_objective_nobody_mapped', [
            ['campaign_name' => 'Cheap', 'spend' => 10],
            ['campaign_name' => 'Expensive', 'spend' => 5000],
        ]);

        $this->assertNotSame([], $worst);
        $this->assertStringNotContainsString('ROAS', $worst[0]['reason'], 'explained by a metric that did not order it');
        $this->assertStringContainsString('الإنفاق', $worst[0]['reason']);
    }

    /** With nothing in the layout reported at all, there is no worst to name. */
    public function test_nothing_reported_names_no_worst_performer(): void
    {
        $this->assertSame([], $this->service->worst('awareness', $this->withRevenue()));
    }

    /**
     * A known objective whose layout is entirely unreported ranks nothing.
     *
     * Awareness is judged on CPM with reach, impressions and frequency behind it. Rows carrying only
     * revenue report none of those — so there is no order to give, and the previous fallback gave
     * one anyway.
     */
    public function test_a_known_objective_with_nothing_reported_ranks_nothing(): void
    {
        $this->assertSame([], $this->service->rank('awareness', $this->withRevenue()));
    }

    /**
     * The fix must not cost the ordinary case.
     *
     * A sales report still ranks by ROAS, an awareness report still ranks by CPM, and an objective
     * the map translates rather than recognises — `app_installs` — still reaches its own family.
     */
    public function test_a_recognised_objective_still_ranks_on_its_own_metric(): void
    {
        $sales = $this->service->rank('sales', $this->withRevenue());
        $this->assertSame('Rich', $sales[0]['campaign_name']);

        $awareness = $this->service->rank('awareness', [
            ['campaign_name' => 'Cheap', 'spend' => 1000, 'cpm' => 8.0],
            ['campaign_name' => 'Dear', 'spend' => 1000, 'cpm' => 40.0],
        ]);
        $this->assertSame('Cheap', $awareness[0]['campaign_name'], 'awareness is judged on CPM');

        $app = $this->service->rank('app_installs', [
            ['campaign_name' => 'Efficient', 'spend' => 1000, 'cpi' => 2.0],
            ['campaign_name' => 'Costly', 'spend' => 1000, 'cpi' => 9.0],
        ]);
        $this->assertSame('Efficient', $app[0]['campaign_name'], 'app_installs still maps to its family');
    }

    /**
     * An unknown objective still ranks when a metric IS reported that its fallback layout covers.
     *
     * `Unknown`'s layout is spend, impressions and clicks — facts rather than verdicts. So "cannot be
     * ranked" is not a blanket refusal for unknown objectives: it is the answer when the numbers are
     * absent, which is the distinction that makes the refusal meaningful.
     */
    public function test_an_unknown_objective_still_orders_by_what_was_actually_reported(): void
    {
        $ranked = $this->service->rank('some_objective_nobody_mapped', [
            ['campaign_name' => 'Busy', 'spend' => 1000, 'clicks' => 900],
            ['campaign_name' => 'Quiet', 'spend' => 1000, 'clicks' => 10],
        ]);

        $this->assertNotSame([], $ranked, 'a reported figure was refused as unrankable');
        $this->assertSame('Busy', $ranked[0]['campaign_name']);
    }
}
