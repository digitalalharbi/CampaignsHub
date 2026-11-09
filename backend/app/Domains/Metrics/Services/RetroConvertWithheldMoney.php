<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FX-RETRO-001 — a rate that arrives AFTER the money did converts the money that was waiting for it.
 *
 * ## What was missing
 *
 * FX-001 withholds a figure when no rate exists (`value` NULL, the original kept «so the row converts
 * itself the day a rate exists»). But nothing made it so: recording a rate only wrote the rate. The
 * withheld rows converted if the sweep happened to re-ask their day (the last seven), and otherwise
 * waited for somebody to run `metrics:renormalise-currency` by hand. Karam Al-Nakheel's EUR Google Ads
 * spend sat withheld with an EUR→USD rate on file.
 *
 * ## The rules it keeps
 *
 *  - ONLY withheld rows are touched (`value`/`spend`/`revenue` NULL with the original held). A row
 *    that already carries a figure is never restated by a rate that arrived later: history does not
 *    move because a newer quote exists.
 *  - Converted from the ORIGINAL, never from a stored figure, at the rate for the row's OWN day
 *    (nearest on-or-before — {@see CurrencyConverter::resolve}). A day older than every quote stays
 *    withheld; nothing is back-dated.
 *  - Idempotent: a second run finds nothing withheld for that pair and writes nothing.
 *  - Report snapshots are other tables and are never read or written here.
 */
final class RetroConvertWithheldMoney
{
    /** @return array{daily_metrics: int, entity_daily_metrics: int} rows converted */
    public function forPair(string $base, string $quote): array
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);

        if ($base === $quote) {
            return ['daily_metrics' => 0, 'entity_daily_metrics' => 0];
        }

        // A fresh converter: its memo caches misses, and the miss that just stopped being true must not survive.
        $rates = new CurrencyConverter;

        return [
            'daily_metrics' => $this->dailyMetrics($rates, $base, $quote),
            'entity_daily_metrics' => $this->entityDailyMetrics($rates, $base, $quote),
        ];
    }

    private function dailyMetrics(CurrencyConverter $rates, string $base, string $quote): int
    {
        $converted = 0;

        $days = DB::table('daily_metrics')
            ->whereNull('value')
            ->whereNotNull('original_amount')
            ->where('original_currency', $base)
            ->where('project_currency', $quote)
            ->distinct()
            ->pluck('metric_date');

        foreach ($days as $day) {
            $resolved = $rates->resolve($base, $quote, Carbon::parse((string) $day));

            if ($resolved === null) {
                continue;
            }

            $converted += DB::table('daily_metrics')
                ->whereNull('value')
                ->whereNotNull('original_amount')
                ->where('original_currency', $base)
                ->where('project_currency', $quote)
                ->whereDate('metric_date', (string) $day)
                ->update([
                    'value' => DB::raw('original_amount * '.(float) $resolved['rate']),
                    'converted_amount' => DB::raw('original_amount * '.(float) $resolved['rate']),
                    'exchange_rate' => $resolved['rate'],
                    'rate_date' => $resolved['rate_date'],
                    'rate_source' => $resolved['source'],
                ]);
        }

        return $converted;
    }

    private function entityDailyMetrics(CurrencyConverter $rates, string $base, string $quote): int
    {
        $converted = 0;

        $days = DB::table('entity_daily_metrics')
            ->where('original_currency', $base)
            ->where('project_currency', $quote)
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->whereNull('spend')->whereNotNull('spend_original'))
                ->orWhere(fn ($r) => $r->whereNull('revenue')->whereNotNull('revenue_original')))
            ->distinct()
            ->pluck('metric_date');

        foreach ($days as $day) {
            $resolved = $rates->resolve($base, $quote, Carbon::parse((string) $day));

            if ($resolved === null) {
                continue;
            }

            $rate = (float) $resolved['rate'];

            foreach (['spend', 'revenue'] as $column) {
                $converted += DB::table('entity_daily_metrics')
                    ->where('original_currency', $base)
                    ->where('project_currency', $quote)
                    ->whereDate('metric_date', (string) $day)
                    ->whereNull($column)
                    ->whereNotNull("{$column}_original")
                    ->update([$column => DB::raw("{$column}_original * {$rate}")]);
            }
        }

        return $converted;
    }
}
