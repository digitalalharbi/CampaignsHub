<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Metrics\Fx\RateLedger;
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
     * @var array<string, array{rate: float, rate_date: string, source: string, kind: string, stale: bool, recorded_by: ?string, legs: list<array<string, mixed>>}|null>
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
     * The rate, the date it came from and who published it — or null when there is none (FX-001, FX-ENGINE-001).
     *
     * ## Why this does not throw
     *
     * `rateFor()` throws, which is right for a caller asking to convert one figure it must have. It is wrong for the
     * ingest pipeline, where a missing rate for one day of one currency is a fact about that row and not a reason to
     * abandon a sync of thirty thousand. This returns the absence so the caller can withhold that single figure.
     *
     * ## Which rate (Owner order, FX-ENGINE-001)
     *
     * The pair's rate in force ON THE AMOUNT'S OWN DATE, never simply the latest:
     *  1. a dated rate on that day, else the nearest EARLIER one within {@see self::staleAfterDays()} days;
     *  2. else a PEGGED rate (a legal peg such as SAR→USD, SAMA) — it applies to every date and is never stale;
     *  3. else the nearest earlier dated rate older than that — used, but flagged `stale` (⚠);
     *  4. else the inverse pair by the same rule; else, for two non-USD currencies, a cross THROUGH USD
     *     (EUR→SAR = EUR→USD × USD→SAR); else null — the figure is WITHHELD, never zero, never the raw number.
     * A rate past its `effective_to` is not in force. A manual rate wins over an automatic one for the same day at
     * write time ({@see RateLedger}), so the live row of a day is already the winner.
     *
     * The date and the source travel with the rate so a historical conversion is checkable by somebody who was not
     * there. An inverse pair says so in its source (`…:inverse`): calling it anything else would misattribute it.
     *
     * @return array{rate: float, rate_date: string, source: string, kind: string, stale: bool, recorded_by: ?string, legs: list<array<string, mixed>>}|null
     */
    public function resolve(string $from, string $to, Carbon $date): ?array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            // Not a lookup and not a claim about any publisher: a currency is itself at par.
            return ['rate' => 1.0, 'rate_date' => $date->toDateString(), 'source' => 'identity', 'kind' => 'identity',
                'stale' => false, 'recorded_by' => null, 'legs' => []];
        }

        $key = "{$from}:{$to}:{$date->toDateString()}";

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $resolved = $this->pair($from, $to, $date);

        if ($resolved === null && $from !== self::BASE && $to !== self::BASE) {
            $in = $this->pair($from, self::BASE, $date);
            $out = $in === null ? null : $this->pair(self::BASE, $to, $date);
            if ($in !== null && $out !== null) {
                $resolved = [
                    'rate' => $in['rate'] * $out['rate'],
                    // The older DATED publication is the one the figure can be no fresher than; a peg has no age.
                    'rate_date' => $this->crossDate($in, $out),
                    'source' => $in['source'].' × '.$out['source'],
                    'kind' => 'cross',
                    'stale' => $in['stale'] || $out['stale'],
                    'recorded_by' => $in['recorded_by'] ?? $out['recorded_by'],
                    'legs' => [$in + ['from' => $from, 'to' => self::BASE], $out + ['from' => self::BASE, 'to' => $to]],
                ];
            }
        }

        return $this->cache[$key] = $resolved;
    }

    /**
     * @param  array{rate_date: string, kind: string}  $in
     * @param  array{rate_date: string, kind: string}  $out
     */
    private function crossDate(array $in, array $out): string
    {
        $dated = array_filter([$in, $out], static fn (array $leg): bool => $leg['kind'] !== CurrencyRate::PEGGED);

        return $dated === [] ? min($in['rate_date'], $out['rate_date']) : min(array_column($dated, 'rate_date'));
    }

    /** The system base currency: every indirect conversion goes through it. */
    public const BASE = 'USD';

    /** Days after which a dated rate is still used but flagged stale (Owner: 7). */
    public static function staleAfterDays(): int
    {
        return max(1, (int) config('fx.rates.stale_after_days', 7));
    }

    /**
     * The direct pair by the selection rule above, else its inverse.
     *
     * @return array{rate: float, rate_date: string, source: string, kind: string, stale: bool, recorded_by: ?string, legs: list<array<string, mixed>>}|null
     */
    private function pair(string $from, string $to, Carbon $date): ?array
    {
        $direct = $this->leg($from, $to, $date);
        if ($direct !== null) {
            return $direct;
        }

        $inverse = $this->leg($to, $from, $date);
        if ($inverse === null || $inverse['rate'] === 0.0) {
            return null;
        }

        return ['rate' => 1 / $inverse['rate'], 'source' => $inverse['source'].':inverse'] + $inverse;
    }

    /**
     * One stored direction only.
     *
     * @return array{rate: float, rate_date: string, source: string, kind: string, stale: bool, recorded_by: ?string, legs: list<array<string, mixed>>}|null
     */
    private function leg(string $base, string $quote, Carbon $date): ?array
    {
        $day = $date->toDateString();
        $inForce = static fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day);
        // A row written before `kind` existed is labelled from its source; COALESCE keeps NULL out of the comparison.
        $kind = "COALESCE(kind, CASE WHEN source LIKE 'official-peg%' THEN 'pegged' ELSE 'automatic' END)";
        $columns = ['rate', 'rate_date', 'source', 'kind', 'recorded_by_email'];

        $dated = CurrencyRate::query()
            ->where('base_currency', $base)->where('quote_currency', $quote)
            ->whereRaw("{$kind} <> 'pegged'")
            ->whereDate('rate_date', '<=', $day)
            ->where($inForce)
            ->orderByDesc('rate_date')
            ->first($columns);

        $age = $dated === null ? null : (int) Carbon::parse((string) $dated->rate_date)->startOfDay()->diffInDays($date->copy()->startOfDay());

        if ($dated !== null && $age <= self::staleAfterDays()) {
            return $this->row($dated, false);
        }

        $peg = CurrencyRate::query()
            ->where('base_currency', $base)->where('quote_currency', $quote)
            ->whereRaw("{$kind} = 'pegged'")
            ->where($inForce)
            ->orderByDesc('rate_date')
            ->first($columns);

        if ($peg !== null) {
            return $this->row($peg, false);
        }

        return $dated === null ? null : $this->row($dated, true);
    }

    /** @return array{rate: float, rate_date: string, source: string, kind: string, stale: bool, recorded_by: ?string, legs: list<array<string, mixed>>} */
    private function row(CurrencyRate $r, bool $stale): array
    {
        return [
            'rate' => (float) $r->rate,
            'rate_date' => Carbon::parse((string) $r->rate_date)->toDateString(),
            'source' => (string) $r->source,
            'kind' => (string) ($r->kind ?? CurrencyRate::kindOf($r->source)),
            'stale' => $stale,
            'recorded_by' => $r->recorded_by_email,
            'legs' => [],
        ];
    }

    /**
     * BUDGET-FX-DEFAULT-001 superseded by FX-ENGINE-001: every rate is managed from /admin/settings/currency-rates, so a
     * workspace no longer has its own USD→SAR override. Kept as a name for the budget and spend-limit callers; the answer
     * is the platform engine's (the SAR peg is a platform pegged row).
     *
     * @return array{rate: float, rate_date: string, source: string, kind: string, stale: bool, recorded_by: ?string, legs: list<array<string, mixed>>}|null
     */
    public function resolveForWorkspace(string $tenantId, string $from, string $to, Carbon $date): ?array
    {
        return $this->resolve($from, $to, $date);
    }

    public function convert(Money $money, string $to, Carbon $date): Money
    {
        return $money->convert($to, $this->rateFor($money->currency, $to, $date));
    }
}
