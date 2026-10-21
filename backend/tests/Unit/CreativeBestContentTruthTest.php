<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\CreativeRankingService;
use Tests\TestCase;

/**
 * CONTENT-RANKING-TRUTH-001 — «best content» never contains content with nothing on its own basis.
 */
final class CreativeBestContentTruthTest extends TestCase
{
    public function test_a_creative_with_zero_results_is_not_best_on_a_results_basis(): void
    {
        $service = new CreativeRankingService;

        $ranked = $service->rank('leads', [
            ['name' => 'Works', 'spend' => 100, 'leads' => 20, 'cpl' => 5.0],
            ['name' => 'Spent for nothing', 'spend' => 80, 'leads' => 0, 'cpl' => null],
        ]);

        $this->assertSame(['Works'], array_column($ranked, 'name'));
    }

    public function test_a_scope_where_nothing_produced_the_basis_has_no_best_content(): void
    {
        $service = new CreativeRankingService;

        $this->assertSame([], $service->rank('sales', [
            ['name' => 'A', 'spend' => 50, 'roas' => 0.0, 'cpa' => null],
            ['name' => 'B', 'spend' => 70, 'roas' => null, 'cpa' => null],
        ]));
    }

    /** CONTENT-RANKING-MATERIALITY-001 — a ratio over under one percent of the spend cannot lead. */
    public function test_a_ratio_over_an_immaterial_spend_does_not_lead(): void
    {
        $service = new CreativeRankingService;
        $ranked = $service->rank('sales', [
            ['name' => 'One order on 0.74', 'spend' => 0.74, 'conversions' => 1, 'revenue' => 132.0, 'roas' => 178.0],
            ['name' => 'Dozens', 'spend' => 911.0, 'conversions' => 71, 'revenue' => 2822.0, 'roas' => 3.1],
            ['name' => 'Twenty', 'spend' => 453.0, 'conversions' => 21, 'revenue' => 1727.0, 'roas' => 3.8],
        ]);
        $this->assertSame(['Twenty', 'Dozens'], array_column($ranked, 'name'));

        $worst = $service->worst('sales', [
            ['name' => 'Nothing on 0.67', 'spend' => 0.67, 'conversions' => 0, 'revenue' => 0.0, 'roas' => 0.0],
            ['name' => 'Dozens', 'spend' => 911.0, 'conversions' => 71, 'revenue' => 2822.0, 'roas' => 3.1],
            ['name' => 'Weak', 'spend' => 400.0, 'conversions' => 2, 'revenue' => 100.0, 'roas' => 0.25],
        ]);
        $this->assertSame('Weak', $worst[0]['name']);
    }

    /** A volume basis is the measurement itself and is not gated; and a gate that would empty the list gates nothing. */
    public function test_volumes_are_not_gated_and_a_tiny_scope_keeps_its_ads(): void
    {
        $service = new CreativeRankingService;
        $byVolume = $service->rank('awareness', [
            ['name' => 'Tiny', 'spend' => 0.5, 'impressions' => 90_000, 'cpm' => 0.0056],
            ['name' => 'Big', 'spend' => 100.0, 'impressions' => 10_000, 'cpm' => 10.0],
        ]);
        $this->assertNotEmpty($byVolume);

        $tiny = $service->rank('sales', [
            ['name' => 'A', 'spend' => 0.5, 'conversions' => 1, 'revenue' => 5.0, 'roas' => 10.0],
        ]);
        $this->assertSame(['A'], array_column($tiny, 'name'));
    }

    public function test_a_positive_basis_still_ranks_in_the_objectives_own_direction(): void
    {
        $service = new CreativeRankingService;

        $ranked = $service->rank('leads', [
            ['name' => 'Dear', 'spend' => 100, 'leads' => 5, 'cpl' => 20.0],
            ['name' => 'Cheap', 'spend' => 100, 'leads' => 25, 'cpl' => 4.0],
        ]);

        $this->assertSame(['Cheap', 'Dear'], array_column($ranked, 'name'));
    }
}
