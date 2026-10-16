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
