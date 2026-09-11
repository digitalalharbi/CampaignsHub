<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ReportObjectiveLens;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * AGGREGATION-TRUTH-001 — «أفضل من المتوسط» was decided by an unweighted mean of ratios.
 *
 * `platformNotes()` compared each platform's ratio to `array_sum(ratios) / count(ratios)`: every
 * platform weighing the same regardless of what it spent. One tiny test budget with a freak return
 * drags that mean far above anything the account actually achieved, and the solid performer beneath
 * it is written up as «دون المتوسط» — in a report a CLIENT reads.
 *
 * The fixtures below are chosen so the two comparators DISAGREE, because a fixture where they agree
 * proves nothing: the middle platform flips from weakness to strength once the comparator is the
 * pooled figure. That figure is what the rest of the product already uses — `ClientBudgetRollup`,
 * `portfolioBudget` and the cross-provider totals all recompute derived ratios from the totals and
 * never average them.
 */
final class PlatformNoteAverageTest extends TestCase
{
    /** @param list<array<string, mixed>> $platforms */
    private function notes(ReportObjectiveLens $lens, array $platforms): array
    {
        $generator = (new \ReflectionClass(ReportGenerator::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ReportGenerator::class, 'platformNotes');
        $method->setAccessible(true);

        return $method->invoke($generator, $lens, $platforms, 'SAR');
    }

    /**
     * Mean ROAS is 8.0 — dragged there by ten riyals of spend returning twenty times over. The
     * account's real return is 152,700 on 101,010, about 1.51×, and the middle platform beats it.
     */
    public function test_a_solid_platform_is_not_called_below_average_by_one_freak_result(): void
    {
        $notes = $this->notes(new ReportObjectiveLens('sales'), [
            ['provider' => 'meta', 'spend' => 100_000, 'revenue' => 150_000, 'roas' => 1.5],
            ['provider' => 'tiktok', 'spend' => 1_000, 'revenue' => 2_500, 'roas' => 2.5],
            ['provider' => 'snapchat', 'spend' => 10, 'revenue' => 200, 'roas' => 20.0],
        ]);

        $this->assertCount(1, $notes['tiktok']['strengths'], 'returns 2.5× against an account returning 1.51×');
        $this->assertSame([], $notes['tiktok']['weaknesses']);
    }

    /**
     * A cost metric points the other way and the pooled figure is still the comparator. Mean CPA is
     * 60.67; the account actually paid 101,010 for 1,017.5 results, about 99.3 each.
     */
    public function test_a_cost_metric_compares_against_what_the_account_actually_paid(): void
    {
        $notes = $this->notes(new ReportObjectiveLens('leads'), [
            ['provider' => 'meta', 'spend' => 100_000, 'conversions' => 1_000, 'cpa' => 100.0],
            ['provider' => 'tiktok', 'spend' => 1_000, 'conversions' => 12.5, 'cpa' => 80.0],
            ['provider' => 'snapchat', 'spend' => 10, 'conversions' => 5, 'cpa' => 2.0],
        ]);

        $this->assertCount(1, $notes['tiktok']['strengths'], 'pays 80 where the account pays 99.3');
        $this->assertSame([], $notes['tiktok']['weaknesses']);
    }

    /** One platform still has nobody to compare with, and says nothing rather than comparing to itself. */
    public function test_a_single_platform_is_still_not_compared_to_itself(): void
    {
        $notes = $this->notes(new ReportObjectiveLens('sales'), [
            ['provider' => 'meta', 'spend' => 100_000, 'revenue' => 150_000, 'roas' => 1.5],
        ]);

        $this->assertSame([], $notes['meta']['strengths']);
        $this->assertSame([], $notes['meta']['weaknesses']);
    }
}
