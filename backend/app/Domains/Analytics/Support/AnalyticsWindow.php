<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Support;

use App\Domains\Metrics\Services\PeriodResolver;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * ANALYTICS-DATA-001 — the analytics window, on the Riyadh calendar, through the one {@see PeriodResolver}.
 *
 * A named `period` (every rolling preset ends yesterday) or a `from`/`to` pair; nothing given means the last 30
 * days. The span is capped so a request can never ask for more than a bounded read.
 */
final class AnalyticsWindow
{
    public const MAX_DAYS = 365;

    public const DEFAULT = 'last_30';

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: Carbon, 1: Carbon, 2: array{key: string, from: string, to: string, ongoing: bool}}
     *
     * @throws InvalidArgumentException
     */
    public static function resolve(array $input, ?Carbon $now = null): array
    {
        $from = $input['from'] ?? null;
        $to = $input['to'] ?? null;

        $period = is_string($from) && $from !== '' && is_string($to) && $to !== ''
            ? PeriodResolver::custom($from, $to, $now)
            : PeriodResolver::preset(is_string($input['period'] ?? null) && $input['period'] !== '' ? $input['period'] : self::DEFAULT, $now);

        $a = Carbon::parse($period['from'], PeriodResolver::TIMEZONE)->startOfDay();
        $b = Carbon::parse($period['to'], PeriodResolver::TIMEZONE)->startOfDay();

        if ((int) $a->diffInDays($b) + 1 > self::MAX_DAYS) {
            $a = $b->copy()->subDays(self::MAX_DAYS - 1);
            $period['from'] = $a->toDateString();
        }

        return [$a, $b, $period];
    }
}
