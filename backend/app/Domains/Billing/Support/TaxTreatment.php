<?php

declare(strict_types=1);

namespace App\Domains\Billing\Support;

/**
 * The VAT treatment applied to a quote/invoice. The tax amount is DERIVED from the treatment's rate, never
 * free-typed, so quotes and invoices stay auditable and consistent.
 *
 *   basic_15      — standard KSA VAT, 15% (the default for new documents)
 *   zero_rated    — zero-rated supply, 0%
 *   exempt        — VAT-exempt supply, no tax
 *   out_of_scope  — outside the scope of VAT, no tax
 *   historical_5  — legacy 5% rate; NOT offered for new documents, retained only for old transactions
 */
final class TaxTreatment
{
    public const DEFAULT = 'basic_15';

    /** treatment key => VAT rate as a fraction. */
    private const RATES = [
        'basic_15' => 0.15,
        'zero_rated' => 0.0,
        'exempt' => 0.0,
        'out_of_scope' => 0.0,
        'historical_5' => 0.05,
    ];

    /** @return list<string> every accepted treatment key (including the legacy historical one). */
    public static function keys(): array
    {
        return array_keys(self::RATES);
    }

    public static function isValid(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::RATES);
    }

    /** VAT rate for a treatment (0.0 for unknown/empty). */
    public static function rate(?string $key): float
    {
        return self::RATES[$key] ?? 0.0;
    }

    /** Tax amount for a subtotal under a treatment, rounded to 2 dp. */
    public static function taxFor(?string $key, float $subtotal): float
    {
        return round(max(0.0, $subtotal) * self::rate($key), 2);
    }
}
