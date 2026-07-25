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
    /** @var array<string, float> in-request memo of resolved rates */
    private array $cache = [];

    public function rateFor(string $from, string $to, Carbon $date): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return 1.0;
        }

        $key = "{$from}:{$to}:{$date->toDateString()}";
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $rate = CurrencyRate::query()
            ->where('base_currency', $from)
            ->where('quote_currency', $to)
            ->whereDate('rate_date', '<=', $date->toDateString())
            ->orderByDesc('rate_date')
            ->value('rate');

        if ($rate === null) {
            // Try the inverse pair before giving up.
            $inverse = CurrencyRate::query()
                ->where('base_currency', $to)
                ->where('quote_currency', $from)
                ->whereDate('rate_date', '<=', $date->toDateString())
                ->orderByDesc('rate_date')
                ->value('rate');
            if ($inverse !== null && (float) $inverse != 0.0) {
                $rate = 1 / (float) $inverse;
            }
        }

        if ($rate === null) {
            throw new RuntimeException("No FX rate for {$from}->{$to} on or before {$date->toDateString()}");
        }

        return $this->cache[$key] = (float) $rate;
    }

    public function convert(Money $money, string $to, Carbon $date): Money
    {
        return $money->convert($to, $this->rateFor($money->currency, $to, $date));
    }
}
