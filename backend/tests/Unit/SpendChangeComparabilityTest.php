<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Metrics\Services\MetricsAggregator;
use PHPUnit\Framework\TestCase;

/**
 * AGGREGATION-TRUTH-001 — a trend between two windows that cannot be compared.
 *
 * FX-001 withholds a conversion when no rate exists rather than inventing one, so a window whose
 * money was never converted reports 0. Subtracting that from a previous window that WAS converted
 * produces «spend down 100%» — the most alarming number this method can return, and the least true:
 * nothing collapsed, the rate was simply unavailable.
 *
 * The symmetric cases were always harmless (0 against 0 is no trend). It is the asymmetric pair that
 * fabricates, which is why it went unnoticed on accounts that convert normally.
 */
final class SpendChangeComparabilityTest extends TestCase
{
    public function test_an_ordinary_change_is_still_measured(): void
    {
        self::assertSame(-0.5, MetricsAggregator::spendChange(1000.0, 500.0));
        self::assertSame(0.25, MetricsAggregator::spendChange(800.0, 1000.0));
    }

    public function test_a_window_whose_money_was_never_converted_reports_no_trend(): void
    {
        // Previously 5,000 converted; this window holds its money and cannot state it.
        self::assertNull(
            MetricsAggregator::spendChange(5000.0, 0.0, currentComparable: false),
            'a withheld window was read as a 100% collapse'
        );
    }

    public function test_a_genuine_stop_is_still_a_genuine_stop(): void
    {
        // Comparable and zero: the campaign really did stop spending, and that IS a finding.
        self::assertSame(-1.0, MetricsAggregator::spendChange(5000.0, 0.0));
    }

    public function test_no_previous_window_is_no_trend_rather_than_a_rise_from_nothing(): void
    {
        self::assertNull(MetricsAggregator::spendChange(null, 900.0));
        self::assertNull(MetricsAggregator::spendChange(0.0, 900.0));
    }
}
