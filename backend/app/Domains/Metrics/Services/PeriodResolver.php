<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * DATE-RANGE-001 — what a named period IS, on the Riyadh clock.
 *
 * The twin of `frontend/src/lib/period/periodMath.ts`; both are pinned to the same fixtures on the same
 * fixed clock (2026-11-15). Every rolling preset ends YESTERDAY — that is the ad platforms' own rule, and
 * the reason a «last 30 days» total never matched Meta's or Snapchat's when it ended today. Only the
 * presets that say «today» or «this» include today, and those are `ongoing`: their newest day is not
 * finished, so their numbers are not final.
 *
 * The week starts on Sunday. Months are calendar months. A comparison is the same number of days
 * straight before the period, or the same dates one year earlier.
 */
final class PeriodResolver
{
    public const TIMEZONE = 'Asia/Riyadh';

    /** @var list<string> */
    public const PRESETS = [
        'today', 'yesterday', 'today_yesterday',
        'last_7', 'last_14', 'last_28', 'last_30', 'last_90',
        'this_week', 'last_week', 'this_month', 'last_month',
        'last_3m', 'last_6m', 'last_12m', 'ytd',
    ];

    /** Today's date in Riyadh — never the server's own clock, which is UTC. */
    public static function today(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())->copy()->setTimezone(self::TIMEZONE)->startOfDay();
    }

    /**
     * @return array{key:string,from:string,to:string,ongoing:bool}
     */
    public static function preset(string $key, ?Carbon $now = null): array
    {
        $t = self::today($now);
        $y = $t->copy()->subDay();

        [$from, $to] = match ($key) {
            'today' => [$t, $t],
            'yesterday' => [$y, $y],
            'today_yesterday' => [$y, $t],
            'last_7' => [$t->copy()->subDays(7), $y],
            'last_14' => [$t->copy()->subDays(14), $y],
            'last_28' => [$t->copy()->subDays(28), $y],
            'last_30' => [$t->copy()->subDays(30), $y],
            'last_90' => [$t->copy()->subDays(90), $y],
            'this_week' => [self::startOfWeek($t), $t],
            'last_week' => [self::startOfWeek($t)->subWeek(), self::startOfWeek($t)->subDay()],
            'this_month' => [$t->copy()->startOfMonth(), $t],
            'last_month' => [$t->copy()->startOfMonth()->subMonthNoOverflow(), $t->copy()->startOfMonth()->subDay()],
            'last_3m' => [$t->copy()->subMonthsNoOverflow(3), $y],
            'last_6m' => [$t->copy()->subMonthsNoOverflow(6), $y],
            'last_12m' => [$t->copy()->subMonthsNoOverflow(12), $y],
            'ytd' => self::yearToDate($t, $y),
            default => throw new InvalidArgumentException("Unknown period: {$key}"),
        };

        return ['key' => $key, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'ongoing' => $to->gte($t)];
    }

    /**
     * A span named by two dates: the newest day decides whether it is still in progress.
     *
     * @return array{key:string,from:string,to:string,ongoing:bool}
     */
    public static function custom(string $from, string $to, ?Carbon $now = null): array
    {
        $a = Carbon::parse($from, self::TIMEZONE)->startOfDay();
        $b = Carbon::parse($to, self::TIMEZONE)->startOfDay();

        if ($a->gt($b)) {
            [$a, $b] = [$b, $a];
        }

        return ['key' => 'custom', 'from' => $a->toDateString(), 'to' => $b->toDateString(), 'ongoing' => $b->gte(self::today($now))];
    }

    /**
     * The days to set the period against: `previous` (same length, straight before) or `last_year`.
     *
     * @return array{mode:string,from:string,to:string}
     */
    public static function comparison(string $from, string $to, string $mode): array
    {
        $a = Carbon::parse($from, self::TIMEZONE)->startOfDay();
        $b = Carbon::parse($to, self::TIMEZONE)->startOfDay();

        return match ($mode) {
            'previous' => (function () use ($a, $b): array {
                $len = (int) $a->diffInDays($b) + 1;

                return ['mode' => 'previous', 'from' => $a->copy()->subDays($len)->toDateString(), 'to' => $a->copy()->subDay()->toDateString()];
            })(),
            'last_year' => ['mode' => 'last_year', 'from' => $a->copy()->subYearNoOverflow()->toDateString(), 'to' => $b->copy()->subYearNoOverflow()->toDateString()],
            default => throw new InvalidArgumentException("Unknown comparison: {$mode}"),
        };
    }

    private static function startOfWeek(Carbon $d): Carbon
    {
        return $d->copy()->startOfWeek(Carbon::SUNDAY);
    }

    /** @return array{0:Carbon,1:Carbon} On 1 January there is no complete day of the year: show today alone. */
    private static function yearToDate(Carbon $t, Carbon $y): array
    {
        $from = $t->copy()->startOfYear();

        return [$from, $y->lt($from) ? $t : $y];
    }
}
