<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Scheduling;

use App\Domains\Metrics\Services\PeriodResolver;
use Illuminate\Support\Carbon;

/**
 * The periods of an external digest (spec §10), built on builder K's `PeriodResolver` so a digest and the screen name
 * the same days. Complete days only: every period ends yesterday at the latest; a period that would be empty (this
 * month on the 1st, this week on its first day) is null and the run is skipped.
 *
 * `PeriodResolver` works in Riyadh time. A subscription in another timezone is resolved by handing it a «now» whose
 * Riyadh date equals the subscription's local date, so the arithmetic is K's and the calendar day is the recipient's.
 */
final class DigestPeriods
{
    public const KEYS = [
        'yesterday', 'last_7', 'this_week_to_yesterday', 'last_week', 'this_month_to_yesterday',
        'last_month', 'last_30', 'active_budget_period', 'ytd',
    ];

    /**
     * Which periods make sense for which frequency, and the alternative offered by «استخدم البديل».
     *
     * @var array<string, array<string, string|null>> frequency => period => null (allowed) | alternative
     */
    public const RULES = [
        'daily' => [
            'yesterday' => null, 'last_7' => null, 'this_week_to_yesterday' => null, 'this_month_to_yesterday' => null,
            'last_30' => null, 'active_budget_period' => null, 'ytd' => null,
            'last_week' => 'last_7', 'last_month' => 'this_month_to_yesterday',
        ],
        'weekly' => [
            'last_week' => null, 'last_7' => null, 'this_month_to_yesterday' => null, 'last_30' => null,
            'active_budget_period' => null, 'ytd' => null,
            'yesterday' => 'last_7', 'this_week_to_yesterday' => 'last_week', 'last_month' => 'this_month_to_yesterday',
        ],
        'monthly' => [
            'last_month' => null, 'last_30' => null, 'active_budget_period' => null, 'ytd' => null,
            'yesterday' => 'last_month', 'last_7' => 'last_month', 'this_week_to_yesterday' => 'last_month',
            'last_week' => 'last_month', 'this_month_to_yesterday' => 'last_month',
        ],
    ];

    /** @return array{ok: bool, alternative: string|null} */
    public static function check(string $periodKey, string $frequency): array
    {
        $rules = self::RULES[$frequency] ?? null;
        if ($rules === null || ! array_key_exists($periodKey, $rules)) {
            return ['ok' => false, 'alternative' => null];
        }

        return ['ok' => $rules[$periodKey] === null, 'alternative' => $rules[$periodKey]];
    }

    /**
     * @param  string  $localDate  the run's date in the subscription timezone (Y-m-d)
     * @param  (callable(string $today): ?string)|null  $budgetStart  first day of the active budget period, for `active_budget_period`
     * @return array{key: string, from: string, to: string}|null
     */
    public static function resolve(string $periodKey, string $localDate, ?callable $budgetStart = null): ?array
    {
        $now = Carbon::parse($localDate.' 12:00:00', PeriodResolver::TIMEZONE);
        $yesterday = Carbon::parse($localDate, PeriodResolver::TIMEZONE)->subDay()->toDateString();

        [$from, $to] = match ($periodKey) {
            'yesterday', 'last_7', 'last_week', 'last_month', 'last_30', 'ytd' => self::fromPreset($periodKey, $now),
            'this_week_to_yesterday' => [PeriodResolver::preset('this_week', $now)['from'], $yesterday],
            'this_month_to_yesterday' => [PeriodResolver::preset('this_month', $now)['from'], $yesterday],
            'active_budget_period' => [$budgetStart === null ? null : $budgetStart($localDate), $yesterday],
            default => [null, null],
        };

        if ($from === null || $to === null) {
            return null;
        }
        if ($to > $yesterday) {
            $to = $yesterday;
        }
        if ($from > $to) {
            return null;
        }

        return ['key' => $periodKey, 'from' => $from, 'to' => $to];
    }

    /** @return array{0: string, 1: string} */
    private static function fromPreset(string $key, Carbon $now): array
    {
        $p = PeriodResolver::preset($key, $now);

        return [$p['from'], $p['to']];
    }
}
