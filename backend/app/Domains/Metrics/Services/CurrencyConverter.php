<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\ValueObjects\Money;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Converts money to a project currency using the stored daily FX rate for a given date. Rates are
 * looked up as of the metric date (nearest on-or-before), so re-converting old data stays stable.
 * A same-currency conversion is a no-op with rate 1.
 */
final class CurrencyConverter
{
    /**
     * In-request memo of resolved rates, including the MISSES.
     *
     * A null is cached deliberately: an ingest window of thirty days in an unquoted currency would
     * otherwise run the same two failing queries sixty times, and the answer cannot change inside one
     * request. `array_key_exists` rather than `isset` is what makes a cached null readable.
     *
     * @var array<string, array{rate: float, rate_date: string, source: string}|null>
     */
    private array $cache = [];

    public function rateFor(string $from, string $to, Carbon $date): float
    {
        $resolved = $this->resolve($from, $to, $date);

        if ($resolved === null) {
            throw new RuntimeException(
                'No FX rate for '.strtoupper($from).'->'.strtoupper($to).' on or before '.$date->toDateString()
            );
        }

        return $resolved['rate'];
    }

    /**
     * The rate, the date it came from and who published it — or null when there is none (FX-001).
     *
     * ## Why this does not throw
     *
     * `rateFor()` throws, which is right for a caller asking to convert one figure it must have. It
     * is wrong for the ingest pipeline, where a missing rate for one day of one currency is a fact
     * about that row and not a reason to abandon a sync of thirty thousand. This returns the absence
     * so the caller can withhold that single figure and carry on.
     *
     * ## Why the date and the source travel with the rate
     *
     * The lookup is «nearest on-or-before», so a Saturday with no quote is converted at Thursday's
     * rate — and from the stored `exchange_rate` alone nobody can tell that afterwards. Returning
     * `rate_date` makes a historical conversion checkable by somebody who was not there, which is the
     * whole reason `currency_rates` records a date rather than overwriting one row per pair.
     *
     * An inverse pair still resolves, and says so: the quote genuinely came from the SAR→USD row, and
     * calling its source anything else would misattribute a figure to a publisher that never made it.
     *
     * @return array{rate: float, rate_date: string, source: string}|null
     */
    public function resolve(string $from, string $to, Carbon $date): ?array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            // Not a lookup and not a claim about any publisher: a currency is itself at par.
            return ['rate' => 1.0, 'rate_date' => $date->toDateString(), 'source' => 'identity'];
        }

        $key = "{$from}:{$to}:{$date->toDateString()}";

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $direct = CurrencyRate::query()
            ->where('base_currency', $from)
            ->where('quote_currency', $to)
            ->whereDate('rate_date', '<=', $date->toDateString())
            ->orderByDesc('rate_date')
            ->first(['rate', 'rate_date', 'source']);

        if ($direct !== null) {
            return $this->cache[$key] = [
                'rate' => (float) $direct->rate,
                'rate_date' => Carbon::parse((string) $direct->rate_date)->toDateString(),
                'source' => (string) $direct->source,
            ];
        }

        $inverse = CurrencyRate::query()
            ->where('base_currency', $to)
            ->where('quote_currency', $from)
            ->whereDate('rate_date', '<=', $date->toDateString())
            ->orderByDesc('rate_date')
            ->first(['rate', 'rate_date', 'source']);

        if ($inverse !== null && (float) $inverse->rate !== 0.0) {
            return $this->cache[$key] = [
                'rate' => 1 / (float) $inverse->rate,
                'rate_date' => Carbon::parse((string) $inverse->rate_date)->toDateString(),
                // Named so an auditor can see the figure was derived rather than published this way.
                'source' => $inverse->source.':inverse',
            ];
        }

        return $this->cache[$key] = null;
    }

    public function convert(Money $money, string $to, Carbon $date): Money
    {
        return $money->convert($to, $this->rateFor($money->currency, $to, $date));
    }
}
