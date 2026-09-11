<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\ReportExporter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * BUDGET-GOVERNANCE-001 — the export carries the same columns the screen does.
 *
 * The budget rungs gained «expected to date», «daily average» and «over / under», and the CSV and
 * XLSX kept exporting five columns. An owner replacing a spreadsheet opens the export and finds
 * fewer figures than the page they exported it from — so the spreadsheet stays, which is the whole
 * thing the export exists to end.
 *
 * A refused figure exports EMPTY rather than as a zero, which is the money contract's rule wearing
 * a spreadsheet's clothes: an empty cell is «not stated», and `0` is a claim.
 */
final class BudgetExportColumnsTest extends TestCase
{
    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private function sheet(array $budget): array
    {
        $exporter = (new \ReflectionClass(ReportExporter::class))->newInstanceWithoutConstructor();

        $sheets = [];
        $add = function (string $name, array $head, array $rows) use (&$sheets): void {
            $sheets[$name] = [$head, $rows];
        };

        $method = new ReflectionMethod(ReportExporter::class, 'budgetSheet');
        $method->setAccessible(true);
        $method->invoke($exporter, $add, ['budget' => $budget]);

        return $sheets['Budget'] ?? [[], []];
    }

    public function test_the_sheet_carries_every_column_the_table_shows(): void
    {
        [$head] = $this->sheet([[
            'provider' => 'meta', 'budget' => 10_000, 'spent' => 7_500, 'remaining' => 2_500,
            'consumed_pct' => 0.75, 'expected_to_date' => 5_000, 'daily_average' => 500,
            'pace' => 1.4, 'projected_spend' => 14_000, 'over_under' => 4_000,
        ]]);

        foreach (['Expected To Date', 'Daily Average', 'Projected', 'Over / Under'] as $column) {
            $this->assertContains($column, $head);
        }
    }

    public function test_the_figures_land_in_their_own_columns(): void
    {
        [$head, $rows] = $this->sheet([[
            'provider' => 'meta', 'budget' => 10_000, 'spent' => 7_500, 'remaining' => 2_500,
            'consumed_pct' => 0.75, 'expected_to_date' => 5_000, 'daily_average' => 500,
            'pace' => 1.4, 'projected_spend' => 14_000, 'over_under' => 4_000,
        ]]);

        $at = static fn (string $column): int => (int) array_search($column, $head, true);

        $this->assertSame(5_000, $rows[0][$at('Expected To Date')]);
        $this->assertSame(500, $rows[0][$at('Daily Average')]);
        $this->assertSame(4_000, $rows[0][$at('Over / Under')]);
    }

    /** A refused figure is an EMPTY cell, never a zero — `0` is a claim and blank is an absence. */
    public function test_a_refused_figure_exports_blank_rather_than_zero(): void
    {
        [$head, $rows] = $this->sheet([[
            'provider' => 'snapchat', 'budget' => 4_000, 'spent' => null, 'remaining' => null,
            'consumed_pct' => null, 'expected_to_date' => null, 'daily_average' => null,
            'pace' => null, 'projected_spend' => null, 'over_under' => null,
        ]]);

        $at = static fn (string $column): int => (int) array_search($column, $head, true);

        $this->assertNull($rows[0][$at('Expected To Date')]);
        $this->assertNull($rows[0][$at('Over / Under')]);
    }
}
