<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use Illuminate\Support\Carbon;

/**
 * When the next digest goes out — EMAIL-SETTINGS-DEPTH-001, answered by the rules that SEND it.
 *
 * A settings screen that computes «next send» from its own idea of the schedule will eventually
 * disagree with the sender, and the disagreement is invisible until somebody waits for an email that
 * never comes. So the weekday, month-day, hour and timezone rules live here, and both
 * `SendDailyDigests` and the settings answer read them.
 *
 * The rules are EMAIL-SCHEDULE-001's, unchanged and for its stated reasons: Monday when the weekday
 * is unset or out of range — a digest that stops arriving because a column holds 9 is a failure
 * nobody would think to look for — and a month-day capped at 28, because one set for the 30th would
 * never arrive in February and would do so silently.
 */
final class DigestSchedule
{
    /** ISO-8601 weekday, Monday when unset or out of range. */
    public static function weekday(mixed $value): int
    {
        $day = (int) ($value ?? 1);

        return $day >= 1 && $day <= 7 ? $day : 1;
    }

    /**
     * Day of month — 1–28 accepted, anything else falls back to the FIRST.
     *
     * Matched to `SendDailyDigests::monthday()` exactly, including the gap between its behaviour and
     * its own comment: that comment says «capped at 28», while the code falls back to 1, and for a
     * value of 30 those are different days. The screen must agree with the sender, so this copies the
     * behaviour rather than the comment. Changing the rule would move real recipients' mail and
     * belongs in its own unit.
     */
    public static function monthday(mixed $value): int
    {
        $day = (int) ($value ?? 1);

        return $day >= 1 && $day <= 28 ? $day : 1;
    }

    /** An unusable timezone falls back to UTC rather than throwing on a settings screen. */
    public static function timezone(mixed $value): string
    {
        $tz = (string) ($value ?? 'UTC');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }

    /**
     * The next time this rhythm fires for these preferences, or null when it is not a rhythm we send.
     *
     * Null rather than a guess: a screen that shows a date for a digest nobody will receive is worse
     * than one that shows nothing, because it is believed.
     *
     * @param  array<string, mixed>  $prefs
     */
    public static function nextSend(string $kind, array $prefs, ?Carbon $now = null): ?Carbon
    {
        $tz = self::timezone($prefs['timezone'] ?? null);
        $hour = (int) ($prefs['digest_hour'] ?? 8);
        $hour = $hour >= 0 && $hour <= 23 ? $hour : 8;

        $local = ($now ?? Carbon::now())->copy()->setTimezone($tz);
        $at = $local->copy()->setTime($hour, 0);

        return match ($kind) {
            'daily' => $at->greaterThan($local) ? $at : $at->addDay(),
            'weekly' => self::nextWeekday($at, $local, self::weekday($prefs['digest_weekday'] ?? null)),
            'monthly' => self::nextMonthday($at, $local, self::monthday($prefs['digest_monthday'] ?? null)),
            default => null,
        };
    }

    private static function nextWeekday(Carbon $at, Carbon $local, int $weekday): Carbon
    {
        $candidate = $at->copy();
        // At most seven steps: today counts only if its hour has not already passed.
        for ($i = 0; $i < 8; $i++) {
            if ($candidate->dayOfWeekIso === $weekday && $candidate->greaterThan($local)) {
                return $candidate;
            }
            $candidate = $candidate->addDay();
        }

        return $candidate;
    }

    private static function nextMonthday(Carbon $at, Carbon $local, int $monthday): Carbon
    {
        $candidate = $at->copy()->day($monthday);

        return $candidate->greaterThan($local) ? $candidate : $candidate->addMonthNoOverflow()->day($monthday);
    }
}
