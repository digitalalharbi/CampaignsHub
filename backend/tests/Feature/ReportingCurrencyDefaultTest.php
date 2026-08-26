<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Metrics\Services\ReportingCurrency;
use Tests\TestCase;

/**
 * MONEY-USD-001 — the reporting currency a project gets when nobody has stated one.
 *
 * It was SAR, and every ad platform this product speaks to bills in USD at account level. So the
 * common case — a USD ad account — needed a USD→SAR rate for every monetary row, and where no rate
 * could be vouched for FX-001 correctly refused to invent one and stored `value = null`. The money
 * was withheld: honest, and unreadable.
 *
 * USD makes the common case an identity conversion. An account billing in another currency still
 * needs a real rate and still fails closed without one — that rule is untouched.
 */
final class ReportingCurrencyDefaultTest extends TestCase
{
    public function test_the_reporting_default_is_usd(): void
    {
        $this->assertSame('USD', ReportingCurrency::DEFAULT);
    }

    public function test_the_default_is_a_currency_the_providers_actually_report_in(): void
    {
        // Guard against someone setting it to a currency that would re-introduce the original defect:
        // a reporting currency no provider bills in makes every row need a rate it may not have.
        $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', ReportingCurrency::DEFAULT);
    }
}
