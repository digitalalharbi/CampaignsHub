<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Rates;

use App\Domains\Metrics\Contracts\CurrencyRateSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MONEY-FX-FLOATING-001 — the European Central Bank's euro foreign exchange reference rates, as an OPT-IN source.
 *
 * ## Why this exists, and why it is not on
 *
 * A floating currency (EUR above all) has no legal peg, so its money stays withheld until a dated, named rate
 * exists. The ECB publishes one every working day, free and without a key, under a stable URL. It ships as a
 * class but is selected only by an operator setting `FX_RATE_DRIVER=App\Domains\Metrics\Rates\EcbReferenceRates`:
 * choosing a publisher stays an explicit decision (see `config/fx.php`), and until it is made nothing changes.
 *
 * ## What it answers, and what it refuses
 *
 * The ECB quotes everything against the euro. A pair is answered directly when either side is EUR, and as a
 * cross through the euro when both sides are ECB currencies; both are stated rates of one publication on one
 * date. A currency the ECB does not publish (SAR among them — and the Gulf pegs are `OfficialPegs`' job) is
 * OMITTED, never guessed. `rate_date` is the date the ECB actually published for, which can be earlier than the
 * date asked on a weekend or holiday; it is returned as such, never re-dated.
 *
 * Reference rates are indicative and are not a statement that anyone can transact at them; the source key says
 * where each figure came from so that is never in doubt.
 */
final class EcbReferenceRates implements CurrencyRateSource
{
    private const DAILY = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    private const HISTORY = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml';

    public function key(): string
    {
        return 'ecb_reference';
    }

    public function label(): string
    {
        return 'European Central Bank — euro foreign exchange reference rates';
    }

    /** No key, no account: the publication is public. */
    public function isConfigured(): bool
    {
        return true;
    }

    public function fetch(array $pairs, Carbon $date): array
    {
        if ($pairs === []) {
            return [];
        }

        $days = $this->days($date);
        $published = $this->onOrBefore($days, $date);
        if ($published === null) {
            return [];
        }

        [$on, $perEuro] = $published;
        $perEuro['EUR'] = 1.0;

        $out = [];
        foreach ($pairs as $pair) {
            $base = strtoupper((string) $pair['base']);
            $quote = strtoupper((string) $pair['quote']);
            if (! isset($perEuro[$base], $perEuro[$quote]) || $perEuro[$base] <= 0.0) {
                continue;
            }

            // One unit of base, in quote: (quote per EUR) / (base per EUR). Direct when either is EUR.
            $out[] = ['base' => $base, 'quote' => $quote, 'rate' => $perEuro[$quote] / $perEuro[$base], 'rate_date' => $on];
        }

        return $out;
    }

    /**
     * The publication's days, newest first, each `[date => [currency => per-EUR rate]]`.
     *
     * @return array<string, array<string, float>>
     */
    private function days(Carbon $date): array
    {
        // Today's file for today and the days just gone; the 90-day history for anything older.
        $url = $date->greaterThanOrEqualTo(Carbon::today()->subDays(2)) ? self::DAILY : self::HISTORY;
        $response = Http::timeout(20)->retry(2, 500, throw: false)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('The ECB reference rates could not be read (HTTP '.$response->status().').');
        }

        $xml = @simplexml_load_string($response->body());
        if ($xml === false) {
            throw new RuntimeException('The ECB reference rates were not readable XML.');
        }

        $out = [];
        foreach ($xml->xpath('//*[local-name()="Cube"][@time]') ?: [] as $day) {
            $rates = [];
            foreach ($day->xpath('*[local-name()="Cube"][@currency]') ?: [] as $cube) {
                $rate = (float) $cube['rate'];
                if ($rate > 0.0) {
                    $rates[strtoupper((string) $cube['currency'])] = $rate;
                }
            }
            if ($rates !== []) {
                $out[(string) $day['time']] = $rates;
            }
        }
        krsort($out);

        return $out;
    }

    /**
     * The newest publication on or before the date asked for.
     *
     * @param  array<string, array<string, float>>  $days
     * @return array{0: string, 1: array<string, float>}|null
     */
    private function onOrBefore(array $days, Carbon $date): ?array
    {
        foreach ($days as $on => $rates) {
            if ($on <= $date->toDateString()) {
                return [$on, $rates];
            }
        }

        return null;
    }
}
