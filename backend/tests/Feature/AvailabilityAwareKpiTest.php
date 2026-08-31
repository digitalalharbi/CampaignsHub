<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\PlatformObjectiveMap;
use Tests\TestCase;

/**
 * CONTENT-KPI-AVAILABILITY-001 — objective-aware AND availability-aware, in that order.
 *
 * Two things were wrong at once in production, and each hid the other.
 *
 * `unified_campaigns.objective` held `SALES` — Snapchat's own word, written into the column that is
 * supposed to hold a {@see CampaignObjective} value — so `tryFrom` failed, every campaign resolved to
 * `ObjectiveFamily::Unknown`, and objective-aware KPI selection was silently off for the whole
 * account. Fixing only that would have made the visible cards WORSE: a correctly classified sales
 * creative headlines on orders, cpa, revenue and roas, and this platform reports neither revenue nor
 * roas at creative grain, so two of four cells would have gone blank.
 *
 * So the selection has to ask two questions rather than one. What does this campaign's objective
 * judge on — and which of those can THIS row, in THIS window, actually answer?
 */
final class AvailabilityAwareKpiTest extends TestCase
{
    private CreativeMetrics $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = app(CreativeMetrics::class);
    }

    /**
     * The figures of a real production creative, recorded by `integrations:diagnose` on 2026-08-24.
     *
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function productionRow(array $over = []): array
    {
        return array_merge([
            // Withheld: the account spends USD and there is no USD→SAR rate. The ORIGINAL survives.
            'spend' => null,
            'spend_original' => 79.614004,
            'spend_withheld_rows' => 11,
            /*
             * Revenue was NOT established by the production trace. `revenue_original` sums to zero,
             * and a sum of zero is what an empty set produces — it is not the platform reporting a
             * monetary zero. Treating it as one is what put «0.00 USD» on a sales card.
             */
            'revenue' => null,
            'revenue_original' => 0.0,
            'revenue_withheld_rows' => 11,
            'money_original_currency' => 'USD',
            'money_original_currencies' => 1,

            'impressions' => 33967.0,
            'clicks' => 546.0,
            'ctr' => 0.0161,
            'conversions' => 0.0,
            'orders' => 0.0,
            'conversion_rate' => 0.0,
            'video_views' => 4409.0,

            // Not reported at creative grain by this platform — null, and never a zero.
            'reach' => null,
            'frequency' => null,
            'cpc' => null,
            'cpm' => null,
            'cpa' => null,
            'roas' => null,
            'aov' => null,
        ], $over);
    }

    // ── the objective, first ───────────────────────────────────────────────────────────────────

    /**
     * OBJECTIVE-NORMALIZATION-002 — Snapchat's current word for a sales campaign is `SALES`.
     *
     * The map was written against the older names (`CATALOG_SALES`), so `SALES` resolved to nothing,
     * the resolver declined to classify, and the raw string was left standing in the canonical column.
     */
    public function test_the_platforms_own_canonical_word_is_recognised(): void
    {
        $map = app(PlatformObjectiveMap::class);

        $this->assertSame(CampaignObjective::Sales, $map->resolve('snapchat', 'SALES'));
        $this->assertSame(CampaignObjective::Awareness, $map->resolve('snapchat', 'AWARENESS'));
        $this->assertSame(CampaignObjective::Traffic, $map->resolve('snapchat', 'TRAFFIC'));
        $this->assertSame(CampaignObjective::VideoViews, $map->resolve('snapchat', 'VIDEO_VIEWS'));

        // The explicit table still wins where the platform's word is NOT ours.
        $this->assertSame(CampaignObjective::Sales, $map->resolve('snapchat', 'CATALOG_SALES'));
        $this->assertSame(CampaignObjective::Conversions, $map->resolve('snapchat', 'WEBSITE_CONVERSIONS'));
    }

    /**
     * The rule is an exact match on a closed vocabulary, not a guess.
     *
     * Google Ads reports `advertisingChannelType`, which is deliberately unmapped — a channel is
     * where an ad runs, not what it is for — and it must keep landing unclassified.
     */
    public function test_a_platform_word_that_is_not_ours_still_resolves_to_nothing(): void
    {
        $map = app(PlatformObjectiveMap::class);

        $this->assertNull($map->resolve('google_ads', 'PERFORMANCE_MAX'));
        $this->assertNull($map->resolve('snapchat', 'SOMETHING_NOBODY_MAPPED'));
        $this->assertNull($map->resolve('snapchat', ''));
        $this->assertNull($map->resolve('snapchat', null));
    }

    // ── then availability ──────────────────────────────────────────────────────────────────────

    /**
     * The production case, end to end.
     *
     * A sales creative that reports orders, impressions, clicks and CTR — and neither revenue in a
     * convertible currency nor a ROAS to divide. It must lead with its own verdict and fill the rest
     * with figures it really has, never with two blank cells reserved for the family template.
     */
    public function test_a_sales_creative_leads_with_orders_and_fills_from_what_it_reported(): void
    {
        $headline = array_slice($this->metrics->headline('sales', $this->productionRow()), 0, 4);

        // The exact card for production creative 81632089.
        $this->assertSame(['spend', 'orders', 'conversion_rate', 'impressions'], $headline);

        // Unanswerable for this row, and therefore absent — not blank, and not invented.
        $this->assertNotContains('cpa', $headline);
        $this->assertNotContains('roas', $headline);
        $this->assertNotContains('aov', $headline);
        $this->assertNotContains('revenue', $headline);
    }

    /**
     * A measured ZERO is an answer; a summed original of zero is not.
     *
     * `orders` is a genuine 0 the platform reported and belongs on a sales card. `revenue` is not:
     * its original sums to zero, which is what an empty set sums to, and no part of the trace
     * established that Snapchat reported revenue for this creative at all.
     */
    public function test_a_measured_zero_counts_but_a_zero_original_does_not(): void
    {
        $headline = $this->metrics->headline('sales', $this->productionRow());

        $this->assertContains('orders', $headline, 'A reported zero is a fact about a sales creative.');
        $this->assertContains('spend', $headline, 'Spend is withheld with a POSITIVE original and one currency.');
        $this->assertNotContains('revenue', $headline, 'A zero original is not proof the provider reported a zero.');
    }

    /** The same row with revenue never sent at all: it goes, and a real figure takes the cell. */
    public function test_a_metric_the_platform_never_sent_is_replaced_rather_than_left_blank(): void
    {
        $headline = array_slice(
            $this->metrics->headline('sales', $this->productionRow([
                'revenue' => null,
                'revenue_original' => null,
                'revenue_withheld_rows' => 0,
            ])),
            0,
            4,
        );

        $this->assertNotContains('revenue', $headline);
        $this->assertContains('impressions', $headline, 'The cell is filled from what this row really holds.');
        $this->assertCount(4, $headline);
    }

    // ── the money contract, case by case (CONTENT-KPI-MONEY-PROVENANCE-001) ────────────────────

    /** A — a converted figure answers, and a converted ZERO answers too: the rate existed. */
    public function test_a_converted_zero_is_answerable(): void
    {
        $headline = $this->metrics->headline('sales', $this->productionRow([
            'spend' => 0.0,
            'spend_original' => null,
            'spend_withheld_rows' => 0,
        ]));

        $this->assertContains('spend', $headline);
    }

    /** B — a withheld amount answers only when every part of the claim it makes is true. */
    public function test_a_positive_withheld_original_in_one_currency_is_answerable(): void
    {
        $this->assertContains('spend', $this->metrics->headline('sales', $this->productionRow()));
    }

    /** C — nothing converted and nothing preserved is nothing to show. */
    public function test_money_with_neither_a_value_nor_an_original_is_not_answerable(): void
    {
        $headline = $this->metrics->headline('sales', $this->productionRow([
            'spend' => null,
            'spend_original' => null,
            'spend_withheld_rows' => 0,
        ]));

        $this->assertNotContains('spend', $headline);
    }

    /**
     * D — a zero original is not evidence of a reported zero.
     *
     * `SUM(...) FILTER (...)` returns zero for an empty set as readily as for a set of zeros, and
     * the card cannot tell them apart. The one that would be wrong is the one it must not print.
     */
    public function test_a_zero_original_is_not_answerable_on_its_own(): void
    {
        $headline = $this->metrics->headline('sales', $this->productionRow([
            'spend' => null,
            'spend_original' => 0.0,
            'spend_withheld_rows' => 11,
        ]));

        $this->assertNotContains('spend', $headline);
    }

    /**
     * D again, from the other side: rows must actually have been withheld.
     *
     * An original with no withheld rows behind it is not the sync saying «I had an amount and
     * refused to convert it» — and that sentence is the entire basis for showing the original.
     */
    public function test_an_original_with_no_withheld_rows_is_not_answerable(): void
    {
        $headline = $this->metrics->headline('sales', $this->productionRow([
            'spend' => null,
            'spend_original' => 50.0,
            'spend_withheld_rows' => 0,
        ]));

        $this->assertNotContains('spend', $headline);
    }

    /**
     * E — two unconvertible currencies cannot be added into one amount.
     *
     * The reader already refuses to NAME a currency when this is not exactly 1; the headline must
     * refuse to offer the cell at all, rather than present a sum under a label that is wrong.
     */
    public function test_mixed_original_currencies_are_not_answerable(): void
    {
        $headline = $this->metrics->headline('sales', $this->productionRow([
            'money_original_currencies' => 2,
        ]));

        $this->assertNotContains('spend', $headline);
        // And the card is still filled from what the row really holds.
        $this->assertContains('orders', $headline);
        $this->assertContains('impressions', $headline);
    }

    /** A currency count of one but no name is the same refusal — a label nobody can print. */
    public function test_a_withheld_amount_with_no_currency_name_is_not_answerable(): void
    {
        $this->assertNotContains('spend', $this->metrics->headline('sales', $this->productionRow([
            'money_original_currency' => null,
        ])));

        $this->assertNotContains('spend', $this->metrics->headline('sales', $this->productionRow([
            'money_original_currency' => '   ',
        ])));
    }

    /**
     * The awareness family, which is most of this account when classified correctly.
     *
     * It names `frequency`, and Snapchat's creative-grain stats call does not ask for it.
     */
    public function test_an_awareness_creative_does_not_reserve_a_cell_for_an_unreported_frequency(): void
    {
        $this->assertContains('frequency', ObjectiveFamily::Awareness->headlineMetrics(), 'The family definition is untouched.');

        $headline = array_slice($this->metrics->headline('awareness', $this->productionRow([
            'reach' => 20000.0,
            'cpm' => null,
        ])), 0, 4);

        $this->assertNotContains('frequency', $headline);
        $this->assertSame(['spend', 'impressions', 'reach', 'video_views'], $headline);
    }

    /**
     * An app-install creative — the family whose every named metric this table lacks a column for.
     *
     * Before availability entered the selection this returned a bare `['spend']`, and on this account
     * that single figure is a withheld one. One cell is how a card comes to look broken.
     */
    public function test_an_app_install_creative_is_not_left_with_a_single_figure(): void
    {
        $headline = array_slice($this->metrics->headline('app_installs', $this->productionRow()), 0, 4);

        $this->assertSame('spend', $headline[0]);
        $this->assertNotContains('installs', $headline);
        $this->assertNotContains('cpi', $headline);
        $this->assertCount(4, $headline);
    }

    /**
     * The refusal that matters most: never pad with a metric this row cannot answer.
     *
     * A creative whose platform sent nothing gets an EMPTY headline, and the card falls to the
     * «did not run in this period» panel — which is the true statement. Four cells of «no data»
     * would be four apologies dressed as a report.
     */
    public function test_a_row_that_answers_nothing_is_given_no_headline_to_fill(): void
    {
        $nothing = [
            'spend' => null, 'spend_original' => null, 'revenue' => null, 'revenue_original' => null,
            'impressions' => null, 'clicks' => null, 'ctr' => null, 'cpm' => null,
            'conversions' => null, 'orders' => null, 'video_views' => null,
        ];

        $this->assertSame([], $this->metrics->headline('sales', $nothing));
        $this->assertSame([], $this->metrics->headline('awareness', $nothing));
        $this->assertSame([], $this->metrics->headline(null, $nothing));
    }

    /**
     * With no figures in hand the selection is unchanged — every caller that describes a card rather
     * than rendering one still gets the family's shape.
     */
    public function test_without_figures_the_family_shape_is_returned_as_before(): void
    {
        $this->assertSame(['spend', 'orders', 'cpa', 'revenue'], array_slice($this->metrics->headline('sales'), 0, 4));
        $this->assertSame(['spend', 'impressions', 'reach', 'cpm'], array_slice($this->metrics->headline('awareness'), 0, 4));
    }

    /**
     * Stated once over every objective rather than family by family, so an objective added later
     * inherits the guarantee without anybody remembering to.
     */
    public function test_no_objective_ever_headlines_a_metric_this_row_cannot_answer(): void
    {
        $row = $this->productionRow();

        $objectives = array_map(static fn (CampaignObjective $c): string => $c->value, CampaignObjective::cases());

        foreach ([...$objectives, null] as $objective) {
            foreach ($this->metrics->headline($objective, $row) as $key) {
                $answered = in_array($key, ['spend', 'revenue'], true)
                    ? ($row[$key] ?? null) !== null || ($row[$key.'_original'] ?? null) !== null
                    : ($row[$key] ?? null) !== null;

                $this->assertTrue(
                    $answered,
                    sprintf('«%s» headlines on «%s», which this row cannot answer.', $objective ?? 'null', $key),
                );
            }
        }
    }
}
