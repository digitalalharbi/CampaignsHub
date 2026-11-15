<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Fx;

use App\Domains\Metrics\Services\CurrencyConverter;
use Illuminate\Support\Carbon;

/**
 * FX-ENGINE-001 — the ONE `convert(amount, from, to, date)`.
 *
 * Base system currency USD; an indirect pair (EUR→SAR) goes through USD. The rate is chosen by
 * {@see CurrencyConverter::resolve()} (the rate in force on the amount's own date; nearest earlier within 7 days;
 * older is used and flagged stale; pegged applies to every date; none → withheld). Raw platform numbers are never
 * modified: the original travels inside the result.
 */
final class FxEngine
{
    public function __construct(private readonly CurrencyConverter $rates) {}

    public function convert(float|int|string $amount, string $from, string $to, Carbon|string $date): FxConversion
    {
        return $this->convertValue(MoneyValue::of($amount, $from, $date), $to);
    }

    public function convertValue(MoneyValue $money, string $to): FxConversion
    {
        $to = strtoupper(trim($to));
        $resolved = $this->rates->resolve($money->currency, $to, Carbon::parse($money->date));

        if ($resolved === null) {
            return new FxConversion($money, $to, null, null, null, null, null, null, false, true);
        }

        return new FxConversion(
            original: $money,
            target: $to,
            converted: $money->amount * $resolved['rate'],
            rate: $resolved['rate'],
            rateDate: $resolved['rate_date'],
            source: $resolved['source'],
            kind: $resolved['kind'],
            recordedBy: $resolved['recorded_by'],
            stale: $resolved['stale'],
            withheld: false,
            legs: $resolved['legs'],
        );
    }
}
