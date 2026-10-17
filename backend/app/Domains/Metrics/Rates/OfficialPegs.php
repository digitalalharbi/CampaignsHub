<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Rates;

/**
 * FX-PEG-001 — currencies whose central bank fixes them to the US dollar by law.
 *
 * ## Why this is not a «default publisher»
 *
 * `config/fx.php` refuses to bake a commercial rate vendor into the repository, and that stands. A
 * peg is a different thing: nobody publishes it, nobody sells it and it does not move. The Saudi
 * riyal has been fixed at 3.75 per dollar since 1986 by the Saudi Central Bank; stating that rate is
 * reading a statute, not choosing a supplier. Withholding every riyal figure until an operator buys a
 * subscription for a number that has not changed in forty years is what made Spend read «no data»
 * beside live impressions on Production.
 *
 * ## What it must never become
 *
 * Only currencies that are legally pegged appear here. A floating currency (EUR, GBP, TRY, EGP…)
 * is NEVER answered from this class: it still needs a real, dated, attributable rate, and until one
 * exists its figures stay withheld. Adding a currency here is a statement that its central bank
 * guarantees the rate.
 */
final class OfficialPegs
{
    /** @var array<string, array{per_usd: float, authority: string}> units of the currency per one US dollar */
    private const PEGS = [
        'SAR' => ['per_usd' => 3.75, 'authority' => 'SAMA'],
        'AED' => ['per_usd' => 3.6725, 'authority' => 'CBUAE'],
        'QAR' => ['per_usd' => 3.64, 'authority' => 'QCB'],
        'BHD' => ['per_usd' => 0.376, 'authority' => 'CBB'],
        'OMR' => ['per_usd' => 0.3845, 'authority' => 'CBO'],
        'JOD' => ['per_usd' => 0.709, 'authority' => 'CBJ'],
    ];

    /** 1 $base = ? $quote when both sides are USD or pegged to it; null for anything that floats. */
    public static function rate(string $base, string $quote): ?float
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);

        if ($base === $quote) {
            return 1.0;
        }

        $perUsdBase = self::perUsd($base);
        $perUsdQuote = self::perUsd($quote);

        if ($perUsdBase === null || $perUsdQuote === null) {
            return null;
        }

        return $perUsdQuote / $perUsdBase;
    }

    /** The recorded source name, e.g. «official-peg:SAMA». */
    public static function source(string $base, string $quote): string
    {
        $authority = self::PEGS[strtoupper($base)]['authority'] ?? self::PEGS[strtoupper($quote)]['authority'] ?? 'USD';

        return 'official-peg:'.$authority;
    }

    private static function perUsd(string $currency): ?float
    {
        if ($currency === 'USD') {
            return 1.0;
        }

        return self::PEGS[$currency]['per_usd'] ?? null;
    }
}
