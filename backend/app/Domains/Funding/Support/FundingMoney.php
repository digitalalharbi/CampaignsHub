<?php

declare(strict_types=1);

namespace App\Domains\Funding\Support;

/**
 * BUDGET-CURRENCY-001 — the ONE place a money figure of the budgets surfaces becomes text, so every one of them carries a short
 * currency mark beside the number: ر.س for SAR, $ for USD, € for EUR, the ISO code for any other — the same in Arabic and English.
 *
 * Conversion policy (Owner, 2026-11-14): the budget is SAR; each platform's own spend is first normalised to USD (one unit for
 * platforms), and totals, remaining and balances are shown in SAR. A figure with no rate keeps its OWN currency's mark.
 *
 * Used by the PDF, the XLSX/CSV, the notices and the spend-summary mail. `FundingMoneySourceGuardTest` fails when a Funding
 * output formats a figure on its own.
 */
final class FundingMoney
{
    private const MARKS = ['SAR' => 'ر.س', 'USD' => '$', 'EUR' => '€'];

    public static function mark(string $currency): string
    {
        $code = strtoupper(trim($currency));

        return self::MARKS[$code] ?? $code;
    }

    /** `1,234.50 ر.س` — the number is always Latin digits; `$decimals` 0 for notices, 2 for statements. */
    public static function format(float|int|null $amount, string $currency = 'SAR', int $decimals = 2): string
    {
        if ($amount === null) {
            return '—';
        }

        return number_format((float) $amount, $decimals, '.', ',').' '.self::mark($currency);
    }

    /** The number alone, for a cell whose mark is drawn beside it. */
    public static function number(float|int|null $amount, int $decimals = 2): string
    {
        return $amount === null ? '—' : number_format((float) $amount, $decimals, '.', ',');
    }

    /** Two decimals, no thousands separator: for a machine-readable cell (CSV) whose column header names the currency. */
    public static function plain(float|int|null $amount): string
    {
        return $amount === null ? '' : number_format((float) $amount, 2, '.', '');
    }

    /** The spreadsheet number format that shows the mark after the number, e.g. `#,##0.00 "ر.س"`. */
    public static function excelFormat(string $currency): string
    {
        return '#,##0.00 "'.self::mark($currency).'"';
    }
}
