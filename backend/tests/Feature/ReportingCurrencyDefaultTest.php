<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Metrics\Services\ReportingCurrency;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * MONEY-USD-001 — the reporting currency a project gets when nobody has stated one.
 *
 * This is the currency figures are COMPARED in — not a claim about what any provider bills in. An
 * account's own currency is provider truth (USD, SAR, EUR, GBP, …), read from the account and never
 * inferred from the platform's name.
 *
 * An account already publishing the reporting currency converts at par via the converter's identity
 * rate. An account in any other currency needs a real rate for the metric's date and, without one,
 * FX-001 still refuses to invent it — `value` null, money withheld, original preserved. That rule is
 * untouched; only which conversions are needed changes.
 */
final class ReportingCurrencyDefaultTest extends TestCase
{
    public function test_the_reporting_default_is_usd(): void
    {
        $this->assertSame('USD', ReportingCurrency::DEFAULT);
    }

    public function test_the_default_is_a_well_formed_iso_code(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', ReportingCurrency::DEFAULT);
    }

    public function test_a_source_currency_equal_to_the_reporting_currency_converts_at_par(): void
    {
        // Par is an identity, not a rate lookup and not a claim about any publisher. This is why a
        // USD-publishing account needs no FX row — and it is the ONLY case that needs none.
        $out = app(CurrencyConverter::class)->resolve('USD', 'USD', Carbon::parse('2026-08-27'));

        $this->assertSame(1.0, $out['rate']);
        $this->assertSame('identity', $out['source']);
    }

    public function test_a_different_source_currency_still_requires_a_real_rate(): void
    {
        // The rule the change must NOT weaken: no rate, no converted figure. Not zero, not the
        // unconverted amount — an absence, with the original preserved beside it.
        $out = app(CurrencyConverter::class)->resolve('EUR', 'USD', Carbon::parse('2026-08-27'));

        $this->assertNull($out, 'a currency pair with no stored rate must not resolve');
    }
}
