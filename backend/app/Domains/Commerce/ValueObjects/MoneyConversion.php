<?php

declare(strict_types=1);

namespace App\Domains\Commerce\ValueObjects;

/**
 * COMMERCE-FX-001 — one rate, resolved once, applied to every amount on one order.
 *
 * An order is not six independent monetary facts. Its subtotal, shipping, tax, discount, total and
 * refund are all in ONE currency and belong to ONE moment, so they must all be converted at one rate
 * or none of them should be. Resolving per amount would let a total and its own subtotal come from
 * different days' quotes and disagree by fractions no reader could ever account for.
 *
 * `$rate === null` is the fail-closed state: a rate nobody can vouch for. Every amount then converts
 * to null and the provider's own figures survive in the `original_*` columns.
 */
final readonly class MoneyConversion
{
    public function __construct(
        public string $originalCurrency,
        public string $reportingCurrency,
        public ?float $rate,
        public ?string $rateDate,
        public ?string $rateSource,
    ) {}

    /** Whether a figure converted through this can be trusted at all. */
    public function isResolved(): bool
    {
        return $this->rate !== null;
    }

    /**
     * The amount in the reporting currency — or null, which means one of two honest things.
     *
     * Null in, null out: the provider did not state this figure. Null out from a real amount: there
     * was no rate, and the converted figure is WITHHELD. The two are told apart downstream by the
     * `original_*` column beside it, which is why the original is always written.
     */
    public function convert(mixed $amount): ?float
    {
        $original = $this->original($amount);

        if ($original === null || $this->rate === null) {
            return null;
        }

        // Six decimals because that is the column's precision; rounding here rather than letting the
        // database truncate keeps a re-read equal to what this returned.
        return round($original * $this->rate, 6);
    }

    /** The provider's own figure, as a number or not at all. */
    public function original(mixed $amount): ?float
    {
        return is_numeric($amount) ? (float) $amount : null;
    }

    /**
     * The provenance columns shared by every amount on the row.
     *
     * @return array<string,mixed>
     */
    public function columns(): array
    {
        return [
            'currency' => $this->reportingCurrency,
            'original_currency' => $this->originalCurrency,
            'exchange_rate' => $this->rate,
            'rate_date' => $this->rateDate,
            'rate_source' => $this->rateSource,
        ];
    }
}
