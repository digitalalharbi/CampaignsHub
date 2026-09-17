<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\Attention\AttentionAudience;
use App\Domains\Reports\Services\Attention\AttentionFindings;
use PHPUnit\Framework\TestCase;

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — the detector's thresholds and objective families, and the
 * client-safe cut, with no database: the rules are arithmetic, so they are tested as arithmetic.
 */
final class AttentionFindingsTest extends TestCase
{
    /** @return array<string,mixed> */
    private function row(string $family, string $provider = 'meta', array $figures = []): array
    {
        return $figures + [
            'family' => $family, 'provider' => $provider,
            'spend' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0, 'landing_page_views' => 0.0,
            'results' => 0.0, 'revenue' => 0.0, 'spend_withheld_rows' => 0, 'revenue_withheld_rows' => 0,
        ];
    }

    private function build(array $current, array $previous): array
    {
        return (new AttentionFindings)->build($current, $previous, 'SAR');
    }

    public function test_a_lead_cost_that_rose_materially_is_a_problem_judged_on_cpl(): void
    {
        $items = $this->build(
            [$this->row('leads', figures: ['spend' => 3000, 'results' => 50, 'clicks' => 1000])],
            [$this->row('leads', figures: ['spend' => 2000, 'results' => 50, 'clicks' => 1000])],
        );

        $this->assertCount(1, $items);
        $item = $items[0];
        $this->assertSame('cpl_rise', $item['code']);
        $this->assertSame('problem', $item['nature']);
        $this->assertSame('critical', $item['severity'], 'a cost up by half is the critical band');
        $this->assertSame('leads', $item['family']);
        $this->assertSame(40.0, $item['kpis'][0]['before']);
        $this->assertSame(60.0, $item['kpis'][0]['current']);
        $this->assertSame('review_cost_drivers', $item['action']);
        // (60 − 40) × 50 results: arithmetic on the stated figures, in the stated currency.
        $this->assertSame(['kind' => 'extra_cost_vs_previous_rate', 'amount' => 1000.0, 'currency' => 'SAR'], $item['impact']);
    }

    public function test_tiny_volume_produces_no_finding_however_large_the_movement(): void
    {
        // CPL tripled — on 3 leads, and on 40 of spend. Neither is evidence.
        $this->assertSame([], $this->build(
            [$this->row('leads', figures: ['spend' => 90, 'results' => 3])],
            [$this->row('leads', figures: ['spend' => 30, 'results' => 3])],
        ), 'spend under the floor produced a finding');

        $this->assertSame([], $this->build(
            [$this->row('leads', figures: ['spend' => 900, 'results' => 3])],
            [$this->row('leads', figures: ['spend' => 300, 'results' => 3])],
        ), 'results under the floor produced a finding');

        $this->assertSame([], $this->build(
            [$this->row('awareness', figures: ['spend' => 900, 'impressions' => 5000])],
            [$this->row('awareness', figures: ['spend' => 300, 'impressions' => 5000])],
        ), 'impressions under the floor produced a CPM finding');

        // And the floor itself is the whole reason: the same ratio at real volume does fire.
        $this->assertNotSame([], $this->build(
            [$this->row('leads', figures: ['spend' => 900, 'results' => 30])],
            [$this->row('leads', figures: ['spend' => 300, 'results' => 30])],
        ));
    }

    public function test_no_previous_window_is_silence(): void
    {
        $this->assertSame([], $this->build([$this->row('sales', figures: ['spend' => 5000, 'results' => 100])], []));
    }

    public function test_movement_under_the_material_line_is_not_a_finding(): void
    {
        $this->assertSame([], $this->build(
            [$this->row('sales', figures: ['spend' => 1150, 'results' => 100, 'revenue' => 5000])],
            [$this->row('sales', figures: ['spend' => 1000, 'results' => 100, 'revenue' => 5000])],
        ));
    }

    public function test_each_family_is_judged_on_its_own_metrics_only(): void
    {
        // Same raw movement — spend up, results flat — on an awareness row: CPA is not its metric.
        $this->assertSame([], $this->build(
            [$this->row('awareness', figures: ['spend' => 3000, 'results' => 50, 'impressions' => 1_000_000])],
            [$this->row('awareness', figures: ['spend' => 3000, 'results' => 20, 'impressions' => 1_000_000])],
        ), 'awareness was judged on results');

        $awareness = $this->build(
            [$this->row('awareness', figures: ['spend' => 3000, 'impressions' => 1_000_000])],
            [$this->row('awareness', figures: ['spend' => 2000, 'impressions' => 1_000_000])],
        );
        $this->assertSame('cpm_rise', $awareness[0]['code']);

        $traffic = $this->build(
            [$this->row('traffic', figures: ['spend' => 600, 'impressions' => 100_000, 'clicks' => 600, 'landing_page_views' => 500])],
            [$this->row('traffic', figures: ['spend' => 1000, 'impressions' => 100_000, 'clicks' => 1000, 'landing_page_views' => 800])],
        );
        $this->assertSame('ctr_drop', $traffic[0]['code']);
        $this->assertSame('refresh_creative', $traffic[0]['action']);

        $sales = $this->build(
            [$this->row('sales', figures: ['spend' => 2000, 'results' => 100, 'revenue' => 5000])],
            [$this->row('sales', figures: ['spend' => 2000, 'results' => 100, 'revenue' => 10000])],
        );
        $this->assertSame('roas_drop', $sales[0]['code']);
        $this->assertNull($sales[0]['impact'], 'a ROAS drop was given an invented money impact');

        $this->assertSame([], $this->build(
            [$this->row('video', figures: ['spend' => 9000, 'impressions' => 1_000_000])],
            [$this->row('video', figures: ['spend' => 1000, 'impressions' => 1_000_000])],
        ), 'a family with no judged metrics was judged on a neighbour\'s');
    }

    public function test_an_improvement_is_an_opportunity_and_never_critical(): void
    {
        $items = $this->build(
            [$this->row('leads', figures: ['spend' => 1000, 'results' => 100])],
            [$this->row('leads', figures: ['spend' => 1000, 'results' => 20])],
        );

        $this->assertSame('opportunity', $items[0]['nature']);
        $this->assertSame('warning', $items[0]['severity']);
        $this->assertSame('keep_what_works', $items[0]['action']);
        $this->assertNull($items[0]['impact']);
    }

    public function test_withheld_money_is_never_judged_as_a_cost(): void
    {
        $this->assertSame([], $this->build(
            [$this->row('leads', figures: ['spend' => 3000, 'results' => 50, 'spend_withheld_rows' => 2])],
            [$this->row('leads', figures: ['spend' => 1000, 'results' => 50])],
        ));
    }

    public function test_results_that_stopped_under_continuing_spend_is_critical(): void
    {
        $items = $this->build(
            [$this->row('sales', figures: ['spend' => 800, 'results' => 0])],
            [$this->row('sales', figures: ['spend' => 800, 'results' => 40])],
        );

        $this->assertSame('results_stopped', $items[0]['code']);
        $this->assertSame('critical', $items[0]['severity']);
        $this->assertSame('check_conversion_tracking', $items[0]['action']);
    }

    public function test_a_dearer_click_with_steady_ctr_is_a_bidding_matter_for_the_operator(): void
    {
        $items = $this->build(
            [$this->row('traffic', figures: ['spend' => 2000, 'impressions' => 100_000, 'clicks' => 1000])],
            [$this->row('traffic', figures: ['spend' => 1000, 'impressions' => 100_000, 'clicks' => 1000])],
        );

        $this->assertSame('cpc_rise', $items[0]['code']);
        $this->assertSame('review_bidding', $items[0]['action']);
        $this->assertSame('operator', $items[0]['audience']);
    }

    public function test_a_budget_shift_is_only_offered_within_one_family_and_is_operator_internal(): void
    {
        $items = $this->build([
            $this->row('leads', 'meta', ['spend' => 1000, 'results' => 100]),
            $this->row('leads', 'snapchat', ['spend' => 1000, 'results' => 20]),
            // A sales platform is far dearer per result, and must not be compared with leads.
            $this->row('sales', 'tiktok', ['spend' => 5000, 'results' => 10]),
        ], []);

        $this->assertCount(1, $items);
        $this->assertSame('budget_shift', $items[0]['code']);
        $this->assertSame('meta', $items[0]['platform']);
        $this->assertSame('snapchat', $items[0]['kpis'][0]['peer']['platform']);
        $this->assertSame('operator', $items[0]['audience']);
    }

    public function test_the_client_cut_withholds_operator_items_unless_approved_and_hidden_items_always(): void
    {
        $items = $this->build([
            $this->row('leads', 'meta', ['spend' => 3000, 'results' => 50]),
            $this->row('leads', 'snapchat', ['spend' => 3000, 'results' => 150]),
        ], [
            $this->row('leads', 'meta', ['spend' => 2000, 'results' => 50]),
            $this->row('leads', 'snapchat', ['spend' => 3000, 'results' => 150]),
        ]);
        $codes = array_column($items, 'code', 'key');
        $this->assertEqualsCanonicalizing(['cpl_rise', 'budget_shift'], array_values($codes));
        $shift = array_search('budget_shift', $codes, true);
        $rise = array_search('cpl_rise', $codes, true);

        $default = AttentionAudience::forClient($items);
        $this->assertSame(['cpl_rise'], array_column($default, 'code'), 'an operator-internal item reached a client with no approval');
        $this->assertArrayNotHasKey('audience', $default[0]);
        $this->assertArrayNotHasKey('decision', $default[0]);

        $approved = AttentionAudience::forClient($items, [$shift => 'approved']);
        $this->assertEqualsCanonicalizing(['cpl_rise', 'budget_shift'], array_column($approved, 'code'));

        $hidden = AttentionAudience::forClient($items, [$shift => 'approved', $rise => 'hidden']);
        $this->assertSame(['budget_shift'], array_column($hidden, 'code'));

        // A stored item cannot declare itself client-safe: the catalogue decides by its action.
        $forged = $items;
        foreach ($forged as &$item) {
            $item['audience'] = 'client';
        }
        unset($item);
        $this->assertSame(['cpl_rise'], array_column(AttentionAudience::forClient($forged), 'code'));

        // An unknown action fails closed.
        $this->assertSame([], AttentionAudience::forClient([['key' => 'x', 'action' => 'retarget_everyone']]));
    }

    public function test_a_link_hiding_spend_loses_cost_blocks_and_impacts(): void
    {
        $items = $this->build(
            [$this->row('leads', figures: ['spend' => 3000, 'results' => 50, 'clicks' => 1000])],
            [$this->row('leads', figures: ['spend' => 2000, 'results' => 50, 'clicks' => 1000])],
        );

        $this->assertSame([], AttentionAudience::redact($items, hideSpend: true, hideRevenue: false));
        $this->assertSame($items, AttentionAudience::redact($items, hideSpend: false, hideRevenue: false));
    }
}
