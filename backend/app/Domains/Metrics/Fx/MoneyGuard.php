<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Fx;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FX-ENGINE-001 safety net — a monetary figure must never reach a reader without passing through FxEngine::convert().
 *
 * Two halves, both cheap:
 *
 * 1. THE HOOK, in the money serializer. `serialize()` is how a money figure leaves the backend: it returns the
 *    FxConversion's array (stamped `via: fx.convert`). A serializer that has an amount in one currency and must show it
 *    in another, and has NO conversion for it, calls `unconverted()` instead of printing the number — that records the
 *    bypass (a per-day counter in the cache, the first contexts kept) and logs a warning once per context per request.
 *
 * 2. THE DAILY CHECK (`fx:monitor`). Reads only the last few days of stored figures and logs an ERROR for any stored
 *    converted figure with no rate behind it — `value` present while the original is in another currency and no
 *    `exchange_rate` was recorded (a path that wrote a number without the engine), or a converted figure whose unit
 *    nobody stated — and for any bypass the hook counted that day.
 */
final class MoneyGuard
{
    private const KEY = 'fx-money-guard:';

    /** @var array<string, true> contexts already logged in this request */
    private static array $logged = [];

    /** @return array<string, mixed> */
    public static function serialize(FxConversion $conversion): array
    {
        return $conversion->toArray();
    }

    /** Record that `$where` had money it could not put through convert(). Returns the withheld shape to serialize. */
    public static function unconverted(string $where, float $amount, ?string $currency, string $displayCurrency): array
    {
        $day = Carbon::today()->toDateString();
        $key = self::KEY.$day;
        $seen = (array) Cache::get($key, []);
        $seen[$where] = ($seen[$where] ?? 0) + 1;
        if (count($seen) <= 50) {
            Cache::put($key, $seen, now()->addDays(3));
        }
        if (! isset(self::$logged[$where])) {
            self::$logged[$where] = true;
            Log::warning('fx.money_guard.unconverted', ['where' => $where, 'currency' => $currency, 'display' => $displayCurrency]);
        }

        return [
            'original_amount' => $amount, 'original_currency' => $currency, 'currency' => strtoupper($displayCurrency),
            'converted_amount' => null, 'withheld' => true, 'stale' => false, 'via' => 'fx.guard',
            'pending_label' => $currency === null ? 'بانتظار سعر صرف' : 'بانتظار سعر صرف ('.strtoupper($currency).')',
            'rates_page' => FxConversion::RATES_PAGE,
        ];
    }

    /** @return array<string, int> today's bypasses by context */
    public static function bypassesOn(string $day): array
    {
        return array_map('intval', (array) Cache::get(self::KEY.$day, []));
    }

    /**
     * The cheap daily scan. Logs an error per finding and returns the summary (also kept for the admin page).
     *
     * @return array{checked_from: string, stored_without_rate: int, unit_unknown_but_converted: int, bypasses: array<string, int>, ok: bool}
     */
    public function check(?Carbon $today = null, int $days = 3): array
    {
        $today ??= Carbon::today();
        $since = $today->copy()->subDays($days)->toDateString();
        $recent = static fn () => DB::table('daily_metrics')->where('metric_date', '>=', $since)->whereNotNull('value');

        $withoutRate = (int) $recent()->whereNotNull('original_currency')->whereNotNull('project_currency')
            ->whereRaw('UPPER(original_currency) <> UPPER(project_currency)')->whereNull('exchange_rate')->count();
        $unitUnknown = (int) $recent()->whereNotNull('original_amount')->whereNull('original_currency')->count();
        $bypasses = self::bypassesOn($today->copy()->subDay()->toDateString()) + self::bypassesOn($today->toDateString());

        if ($withoutRate > 0) {
            Log::error('fx.money_guard.stored_without_rate', ['since' => $since, 'rows' => $withoutRate]);
        }
        if ($unitUnknown > 0) {
            Log::error('fx.money_guard.unit_unknown_but_converted', ['since' => $since, 'rows' => $unitUnknown]);
        }
        foreach ($bypasses as $where => $n) {
            Log::error('fx.money_guard.bypass', ['where' => $where, 'count' => $n]);
        }

        $summary = [
            'checked_from' => $since,
            'stored_without_rate' => $withoutRate,
            'unit_unknown_but_converted' => $unitUnknown,
            'bypasses' => $bypasses,
            'ok' => $withoutRate === 0 && $unitUnknown === 0 && $bypasses === [],
        ];
        Cache::put('fx-money-guard:last', $summary + ['ran_at' => now()->toIso8601String()], now()->addDays(8));

        return $summary;
    }

    /** @return array<string, mixed>|null */
    public static function lastRun(): ?array
    {
        $last = Cache::get('fx-money-guard:last');

        return is_array($last) ? $last : null;
    }
}
