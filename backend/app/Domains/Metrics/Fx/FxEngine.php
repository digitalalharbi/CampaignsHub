<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Fx;

use App\Domains\Metrics\Services\CurrencyConverter;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

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

    /**
     * FX-APPLY-001 (builder G) — the rate alone, through this engine, for a caller that multiplies many figures of one pair and
     * day (a GA4 series, a launch budget split, a ledger payment). Same shape as {@see CurrencyConverter::resolve()}; null when
     * withheld or when a currency code is not a currency.
     *
     * @return array{rate: float, rate_date: ?string, source: ?string, kind: ?string, stale: bool, expired: bool, recorded_by: ?string, legs: list<array<string, mixed>>}|null
     */
    public function rate(string $from, string $to, Carbon|string $date): ?array
    {
        try {
            $c = $this->convert(1, $from, $to, $date);
        } catch (InvalidArgumentException) {
            return null;
        }
        if ($c->withheld || $c->rate === null) {
            return null;
        }

        return ['rate' => $c->rate, 'rate_date' => $c->rateDate, 'source' => $c->source, 'kind' => $c->kind, 'stale' => $c->stale, 'expired' => $c->expired, 'recorded_by' => $c->recordedBy, 'legs' => $c->legs];
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
            expired: $resolved['expired'] ?? false,
        );
    }
}
