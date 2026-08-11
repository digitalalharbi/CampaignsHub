<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Rates;

use App\Domains\Metrics\Contracts\CurrencyRateSource;
use App\Domains\Metrics\Models\CurrencyRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FX-FEED-001 — the state of the rate supply, and the one place that imports from it.
 *
 * ## The distinction this class exists to keep
 *
 * The FX ENGINE is verified: money is converted at ingest, at a dated rate, from a named source, and
 * a rate that cannot be vouched for withholds the figure (FX-001, COMMERCE-FX-001). The rate FEED is
 * a different thing entirely, and on a fresh install it does not exist — `currency_rates` is written
 * by nothing automatic.
 *
 * Collapsing those two into one verdict is how a product ends up either claiming a capability it has
 * not got, or reporting a working engine as broken because nobody has bought a data subscription. So
 * the state is reported as one of:
 *
 *  - `awaiting_configuration` — no driver chosen. Not a fault; a decision nobody has made yet.
 *  - `driver_not_configured`  — a driver is chosen and is missing something it needs (a key).
 *  - `ready`                  — a usable source is bound and can be asked.
 *
 * ## Which pairs are needed is DERIVED, never declared
 *
 * A configured list of currencies would go stale the first time a client connected a shop in a
 * currency nobody listed, and the figures it fails to convert are exactly the ones nobody notices.
 * The pairs come from the data itself: every conversion the pipeline has already had to withhold.
 * That also makes the operator's decision evidence-based — the /admin surface can say «USD→SAR cost
 * you 412 figures this month» rather than «configure a feed».
 */
final class CurrencyRateFeed
{
    public function __construct(private readonly ?CurrencyRateSource $source = null) {}

    /**
     * @return array{
     *     state: string, driver: ?string, label: ?string,
     *     stale_after_days: int, last_rate_date: ?string, rates: int
     * }
     */
    public function status(): array
    {
        $driver = config('fx.rates.driver');
        $driver = is_string($driver) && $driver !== '' ? $driver : null;

        $state = match (true) {
            $this->source === null => 'awaiting_configuration',
            ! $this->source->isConfigured() => 'driver_not_configured',
            default => 'ready',
        };

        return [
            'state' => $state,
            'driver' => $driver,
            'label' => $this->source?->label(),
            'stale_after_days' => (int) config('fx.rates.stale_after_days', 3),
            'last_rate_date' => CurrencyRate::query()->max('rate_date') === null
                ? null
                : Carbon::parse((string) CurrencyRate::query()->max('rate_date'))->toDateString(),
            'rates' => (int) CurrencyRate::query()->count(),
        ];
    }

    /**
     * The conversions this deployment has already been unable to make, worst first.
     *
     * Read from BOTH pipelines, because a currency can cost an operator ad figures, store figures or
     * both, and a surface that only knew about one would send them to buy a feed that fixes half the
     * problem.
     *
     * @return list<array{base: string, quote: string, withheld: int, earliest: ?string, latest: ?string, sources: list<string>}>
     */
    public function unmetPairs(): array
    {
        $ads = DB::table('daily_metrics')
            ->selectRaw('original_currency AS base, project_currency AS quote')
            ->selectRaw('COUNT(*) AS withheld')
            ->selectRaw('MIN(metric_date) AS earliest, MAX(metric_date) AS latest')
            ->whereNull('value')
            ->whereNotNull('original_amount')
            ->whereNotNull('original_currency')
            ->whereNotNull('project_currency')
            ->groupBy('original_currency', 'project_currency')
            ->get();

        $store = DB::table('commerce_orders')
            ->selectRaw('original_currency AS base, currency AS quote')
            ->selectRaw('COUNT(*) AS withheld')
            ->selectRaw('MIN(placed_at)::date AS earliest, MAX(placed_at)::date AS latest')
            ->whereNull('total')
            ->whereNotNull('original_total')
            ->whereNotNull('original_currency')
            ->whereNotNull('currency')
            ->groupBy('original_currency', 'currency')
            ->get();

        $pairs = [];

        foreach ([['advertising', $ads], ['commerce', $store]] as [$origin, $rows]) {
            foreach ($rows as $row) {
                $key = strtoupper((string) $row->base).'|'.strtoupper((string) $row->quote);

                $pairs[$key] ??= [
                    'base' => strtoupper((string) $row->base),
                    'quote' => strtoupper((string) $row->quote),
                    'withheld' => 0,
                    'earliest' => null,
                    'latest' => null,
                    'sources' => [],
                ];

                $pairs[$key]['withheld'] += (int) $row->withheld;
                $pairs[$key]['sources'][] = $origin;
                $pairs[$key]['earliest'] = $this->earlier($pairs[$key]['earliest'], $row->earliest);
                $pairs[$key]['latest'] = $this->later($pairs[$key]['latest'], $row->latest);
            }
        }

        $pairs = array_values($pairs);

        usort($pairs, static fn (array $a, array $b): int => $b['withheld'] <=> $a['withheld']);

        return $pairs;
    }

    /**
     * Ask the configured source for every pair the data says is missing, and record what came back.
     *
     * @return array{state: string, requested: int, imported: int, missing: list<string>, error: ?string}
     */
    public function import(Carbon $date): array
    {
        $status = $this->status();
        $pairs = $this->unmetPairs();

        if ($status['state'] !== 'ready' || $this->source === null) {
            // Not an error and not silence: the caller says what is missing, and NOTHING is written.
            // A feed that invented a rate here would defeat every fail-closed decision upstream.
            return [
                'state' => $status['state'],
                'requested' => count($pairs),
                'imported' => 0,
                'missing' => array_map(static fn (array $p): string => $p['base'].'→'.$p['quote'], $pairs),
                'error' => null,
            ];
        }

        if ($pairs === []) {
            return ['state' => 'ready', 'requested' => 0, 'imported' => 0, 'missing' => [], 'error' => null];
        }

        try {
            $fetched = $this->source->fetch(
                array_map(static fn (array $p): array => ['base' => $p['base'], 'quote' => $p['quote']], $pairs),
                $date,
            );
        } catch (Throwable $e) {
            return [
                'state' => 'ready',
                'requested' => count($pairs),
                'imported' => 0,
                'missing' => array_map(static fn (array $p): string => $p['base'].'→'.$p['quote'], $pairs),
                'error' => $e->getMessage(),
            ];
        }

        $imported = 0;
        $answered = [];

        foreach ($fetched as $row) {
            $rate = (float) ($row['rate'] ?? 0);

            // A zero or negative rate is not a rate. Recording one would convert a real amount to
            // nothing, which is precisely the «spent nothing» figure the fail-closed rule forbids.
            if ($rate <= 0) {
                continue;
            }

            $this->record(
                base: (string) $row['base'],
                quote: (string) $row['quote'],
                rate: $rate,
                date: Carbon::parse((string) ($row['rate_date'] ?? $date->toDateString())),
                source: $this->source->key(),
            );

            $answered[] = strtoupper((string) $row['base']).'|'.strtoupper((string) $row['quote']);
            $imported++;
        }

        $missing = [];

        foreach ($pairs as $pair) {
            if (! in_array($pair['base'].'|'.$pair['quote'], $answered, true)) {
                $missing[] = $pair['base'].'→'.$pair['quote'];
            }
        }

        return ['state' => 'ready', 'requested' => count($pairs), 'imported' => $imported, 'missing' => $missing, 'error' => null];
    }

    /**
     * Write one rate, replacing any earlier answer for the same pair and date.
     *
     * `updateOrCreate` on the natural key rather than an insert: a source restating a day's rate
     * (a correction, a late fixing) must not leave two rows that the nearest-on-or-before lookup
     * would choose between arbitrarily.
     */
    public function record(string $base, string $quote, float $rate, Carbon $date, string $source): CurrencyRate
    {
        return CurrencyRate::updateOrCreate(
            [
                'base_currency' => strtoupper($base),
                'quote_currency' => strtoupper($quote),
                'rate_date' => $date->toDateString(),
            ],
            ['rate' => $rate, 'source' => $source],
        );
    }

    private function earlier(?string $current, mixed $candidate): ?string
    {
        $value = $candidate === null ? null : Carbon::parse((string) $candidate)->toDateString();

        return $current === null || ($value !== null && $value < $current) ? $value ?? $current : $current;
    }

    private function later(?string $current, mixed $candidate): ?string
    {
        $value = $candidate === null ? null : Carbon::parse((string) $candidate)->toDateString();

        return $current === null || ($value !== null && $value > $current) ? $value ?? $current : $current;
    }
}
