<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Analytics\ObjectiveAnalyticsSection;
use App\Domains\Reports\Support\PreviousComparison;
use PHPUnit\Framework\TestCase;

/**
 * OBJECTIVE-CARDS-PARITY-001 (Owner 2026-11-22) — each objective KPI carries its movement against the equal window before, stated
 * only where both windows reported the figure and the earlier one is not zero; the «compared with the previous period» switch
 * removes it with every other comparison.
 */
final class ObjectiveCardsMovementTest extends TestCase
{
    /** @return array<string, mixed> */
    private function section(): array
    {
        return ['families' => [[
            'family' => 'sales',
            'kpis' => [
                ['key' => 'spend', 'value' => 1490.58, 'state' => 'reported'],
                ['key' => 'purchases', 'value' => 237.0, 'state' => 'reported'],
                ['key' => 'roas', 'value' => null, 'state' => 'unavailable'],
                ['key' => 'cpa', 'value' => 4.22, 'state' => 'reported'],
            ],
        ]]];
    }

    public function test_a_movement_is_stated_only_against_a_reported_non_zero_earlier_figure(): void
    {
        $out = ObjectiveAnalyticsSection::withMovement($this->section(), ['sales' => ['spend' => 1796.0, 'purchases' => 0.0, 'roas' => 2.0]]);
        $kpis = array_column($out['families'][0]['kpis'], null, 'key');

        $this->assertSame(round((1490.58 - 1796.0) / 1796.0, 4), $kpis['spend']['delta']);
        $this->assertNull($kpis['purchases']['delta'], 'no rise out of nothing');
        $this->assertNull($kpis['roas']['delta'], 'no movement for a figure this window did not report');
        $this->assertNull($kpis['cpa']['delta'], 'no movement where the earlier window did not report the figure');
    }

    public function test_the_previous_comparison_switch_removes_the_objective_movements(): void
    {
        $payload = ['objective_analytics' => ObjectiveAnalyticsSection::withMovement($this->section(), ['sales' => ['spend' => 1796.0]])];
        $stripped = PreviousComparison::strip($payload);

        foreach ($stripped['objective_analytics']['families'][0]['kpis'] as $kpi) {
            $this->assertArrayNotHasKey('delta', $kpi);
        }
        $this->assertSame(1490.58, $stripped['objective_analytics']['families'][0]['kpis'][0]['value']);
    }
}
