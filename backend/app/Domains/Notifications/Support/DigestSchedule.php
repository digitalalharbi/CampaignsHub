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
    /**
     * ISO-8601 weekday, SUNDAY when unset or out of range — DIGESTS-CADENCE-BUDGETS-001.
     *
     * The Owner's weekly lands on Sunday at 08:00 Riyadh: the working week here runs Sunday to Thursday, so a
     * Monday summary arrives after the week it is meant to open. A stored, chosen day is never moved; only the
     * fallback for a missing or broken value (and the column default for new rows) changed.
     */
    public const DEFAULT_WEEKDAY = 7;

    public static function weekday(mixed $value): int
    {
        $day = (int) ($value ?? self::DEFAULT_WEEKDAY);

        return $day >= 1 && $day <= 7 ? $day : self::DEFAULT_WEEKDAY;
    }

    /**
     * DIGESTS-CADENCE-BUDGETS-001 — which rhythms of the PER-PROJECT report this person asked for.
     *
     * `per_project` is the daily one (DIGESTS-VISUAL-V2-001). The weekly and monthly keys are new, and a map stored before
     * they existed has neither: absent means «the same as the daily answer», so somebody who already receives a report per
     * project starts receiving the weekly and monthly ones too, and somebody who never asked receives none. An explicit
     * `false` is always kept.
     *
     * @param  array<string,mixed>  $digests  the stored `notification_preferences.digests` map
     * @return list<string> a subset of daily, weekly, monthly
     */
    public static function perProjectKinds(array $digests): array
    {
        $daily = ($digests['per_project'] ?? false) === true;
        $out = $daily ? ['daily'] : [];
        foreach (['weekly' => 'per_project_weekly', 'monthly' => 'per_project_monthly'] as $kind => $key) {
            $on = array_key_exists($key, $digests) ? $digests[$key] === true : $daily;
            if ($on) {
                $out[] = $kind;
            }
        }

        return $out;
    }

    /**
     * The finished calendar month before `$sendDay`'s month, and the month before THAT — plain Gregorian months.
     *
     * Sent on the 1st, so the report is about a month that is over. No rolling «last 30 days» and no other calendar:
     * November is 1–30 November, compared with 1–31 October, whatever their lengths.
     *
     * @return array{from: Carbon, to: Carbon, previous_from: Carbon, previous_to: Carbon}
     */
    public static function previousMonth(Carbon $sendDay): array
    {
        $from = $sendDay->copy()->startOfMonth()->subMonthNoOverflow()->startOfDay();
        $prev = $from->copy()->subMonthNoOverflow();

        return [
            'from' => $from,
            'to' => $from->copy()->endOfMonth(),
            'previous_from' => $prev->copy()->startOfDay(),
            'previous_to' => $prev->copy()->endOfMonth(),
        ];
    }

    /** Budget pace above this is «ahead of plan» — the same band the report's pacing column colours by. */
    public const PACE_AHEAD = 1.1;

    /** Budget pace below this is «behind plan». */
    public const PACE_BEHIND = 0.9;

    /**
     * on_track / ahead / behind for a pace ratio (projected spend ÷ budget), or null when no pace can be stated.
     */
    public static function paceBand(?float $pace): ?string
    {
        if ($pace === null) {
            return null;
        }

        return $pace > self::PACE_AHEAD ? 'ahead' : ($pace < self::PACE_BEHIND ? 'behind' : 'on_track');
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
