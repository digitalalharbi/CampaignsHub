<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Support\DigestSchedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * EMAIL-SETTINGS-DEPTH-001 — «next send», answered by the rules that actually send.
 *
 * A settings screen that says when the next email goes out, computed from its own idea of the
 * schedule, is a screen that will eventually disagree with the sender — and the disagreement is
 * invisible until somebody waits for an email that does not come. So the weekday, month-day and hour
 * rules live in ONE place, and both the console command and the settings answer read them.
 *
 * The rules themselves are EMAIL-SCHEDULE-001's, unchanged: Monday when the weekday is unset or out
 * of range, the 1st when the month-day is, and a month-day capped at 28 because a digest set for the
 * 30th would never arrive in February — silently.
 */
final class DigestNextSendTest extends TestCase
{
    private function at(string $utc): Carbon
    {
        return Carbon::parse($utc, 'UTC');
    }

    /** The daily one is simply the next time that hour comes round, in the reader's own timezone. */
    public function test_the_daily_digest_is_the_next_time_that_hour_comes_round(): void
    {
        $next = DigestSchedule::nextSend('daily', ['digest_hour' => 8, 'timezone' => 'Asia/Riyadh'], $this->at('2026-08-28T02:00:00Z'));

        // 08:00 Riyadh is 05:00 UTC, and 02:00 UTC is before it — so today.
        $this->assertSame('2026-08-28 05:00', $next?->utc()->format('Y-m-d H:i'));
    }

    /** Past this morning's hour, it is tomorrow — not «today, already gone». */
    public function test_after_todays_hour_the_daily_digest_is_tomorrow(): void
    {
        $next = DigestSchedule::nextSend('daily', ['digest_hour' => 8, 'timezone' => 'Asia/Riyadh'], $this->at('2026-08-28T09:00:00Z'));

        $this->assertSame('2026-08-29 05:00', $next?->utc()->format('Y-m-d H:i'));
    }

    /** The weekly goes out on ONE weekday — the one this recipient chose. */
    public function test_the_weekly_digest_lands_on_the_chosen_weekday(): void
    {
        // 2026-08-28 is a Friday. Asking for Wednesday (ISO 3) gives the next Wednesday.
        $next = DigestSchedule::nextSend(
            'weekly',
            ['digest_hour' => 8, 'timezone' => 'UTC', 'digest_weekday' => 3],
            $this->at('2026-08-28T09:00:00Z'),
        );

        $this->assertSame(3, $next?->dayOfWeekIso);
        $this->assertSame('2026-09-02 08:00', $next?->utc()->format('Y-m-d H:i'));
    }

    /** The monthly lands on the chosen day of the month. */
    public function test_the_monthly_digest_lands_on_the_chosen_day(): void
    {
        $next = DigestSchedule::nextSend(
            'monthly',
            ['digest_hour' => 8, 'timezone' => 'UTC', 'digest_monthday' => 15],
            $this->at('2026-08-28T09:00:00Z'),
        );

        $this->assertSame('2026-09-15 08:00', $next?->utc()->format('Y-m-d H:i'));
    }

    /**
     * The same fallbacks the SENDER uses — matched exactly, including one that surprised me.
     *
     * A weekday of 9 falls back to Monday rather than never matching: a digest that stops arriving
     * because a column holds 9 is a failure nobody would think to look for.
     *
     * A month-day of 30 falls back to the FIRST, not to the 28th. `SendDailyDigests::monthday()`
     * reads `$day >= 1 && $day <= 28 ? $day : 1`, while the comment above it says «capped at 28» —
     * and for a value of 30 those two are different days. This asserts what the code does, because
     * the whole point of sharing this module is that the screen and the sender agree; changing the
     * rule would move real recipients' mail and belongs in its own unit, not smuggled in beside a
     * settings screen. Recorded in the matrix.
     */
    public function test_it_falls_back_exactly_as_the_sender_does(): void
    {
        $weekly = DigestSchedule::nextSend('weekly', ['digest_hour' => 8, 'timezone' => 'UTC', 'digest_weekday' => 9], $this->at('2026-08-28T09:00:00Z'));
        $this->assertSame(1, $weekly?->dayOfWeekIso, 'an out-of-range weekday must fall back to Monday');

        $monthly = DigestSchedule::nextSend('monthly', ['digest_hour' => 8, 'timezone' => 'UTC', 'digest_monthday' => 30], $this->at('2026-01-29T09:00:00Z'));
        $this->assertSame(1, $monthly?->day, 'the sender falls back to the 1st for an out-of-range month-day');
    }

    /** An unknown timezone is not a reason to guess — it falls back to UTC, as the sender does. */
    public function test_an_unusable_timezone_falls_back_rather_than_throwing(): void
    {
        $next = DigestSchedule::nextSend('daily', ['digest_hour' => 8, 'timezone' => 'Mars/Olympus'], $this->at('2026-08-28T09:00:00Z'));

        $this->assertSame('2026-08-29 08:00', $next?->utc()->format('Y-m-d H:i'));
    }

    /** A rhythm nobody subscribed to has no next send — null, never a date they will not receive. */
    public function test_an_unknown_rhythm_has_no_next_send(): void
    {
        $this->assertNull(DigestSchedule::nextSend('quarterly', ['digest_hour' => 8, 'timezone' => 'UTC'], $this->at('2026-08-28T09:00:00Z')));
    }
}
