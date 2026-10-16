<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Metrics\Support\FinancialRatios;
use PHPUnit\Framework\TestCase;

/**
 * FINANCIAL-RATIOS-001 — one arithmetic for every spend-derived figure.
 */
final class FinancialRatiosTest extends TestCase
{
    public function test_a_cost_per_result_needs_spend_and_a_positive_result_count(): void
    {
        $this->assertSame(2.5, FinancialRatios::costPer(100.0, 40.0, 2));
        $this->assertNull(FinancialRatios::costPer(null, 40.0), 'withheld spend must never be divided');
        $this->assertNull(FinancialRatios::costPer(100.0, 0.0), 'zero results has no cost per result');
        $this->assertNull(FinancialRatios::costPer(100.0, null));
    }

    public function test_a_per_thousand_cost_scales_the_quotient(): void
    {
        $this->assertSame(5.0, FinancialRatios::costPer(50.0, 10000.0, 2, 1000.0));
    }

    public function test_roas_needs_valid_spend_and_an_existing_revenue_figure(): void
    {
        $this->assertSame(4.0, FinancialRatios::roas(100.0, 400.0));
        $this->assertNull(FinancialRatios::roas(null, 400.0), 'no spend, no return');
        $this->assertNull(FinancialRatios::roas(100.0, null), 'an absent revenue has no ROAS');
        $this->assertNull(FinancialRatios::roas(0.0, 400.0), 'a return over zero spend is not a number');
    }

    public function test_a_measured_zero_revenue_is_a_real_zero_return(): void
    {
        $this->assertSame(0.0, FinancialRatios::roas(900.0, 0.0));
    }
}
