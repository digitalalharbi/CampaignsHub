<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Support\ReportPeriod;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * REPORT-PERIOD-001 — daily, weekly, monthly and custom change the WINDOW, the COMPARISON and the GRAIN.
 */
final class ReportPeriodTest extends TestCase
{
    private function day(string $d): Carbon
    {
        return Carbon::parse($d);
    }

    public function test_daily_is_one_day_compared_with_the_day_before(): void
    {
        $w = ReportPeriod::resolve('daily', $this->day('2026-10-01'), $this->day('2026-10-14'), $this->day('2026-10-16'));

        $this->assertSame(['2026-10-14', '2026-10-14'], [$w['from']->toDateString(), $w['to']->toDateString()]);
        $this->assertSame(['2026-10-13', '2026-10-13'], [$w['previous_from']->toDateString(), $w['previous_to']->toDateString()]);
        $this->assertSame('مقابل الأمس', $w['comparison_ar']);
    }

    public function test_weekly_is_the_iso_week_compared_with_the_previous_week(): void
    {
        // 2026-10-14 is a Wednesday: the week is Mon 12 – Sun 18, clipped to «today» (the 16th).
        $w = ReportPeriod::resolve('weekly', $this->day('2026-10-01'), $this->day('2026-10-14'), $this->day('2026-10-16'));

        $this->assertSame('2026-10-12', $w['from']->toDateString());
        $this->assertSame('2026-10-16', $w['to']->toDateString());
        $this->assertTrue($w['partial']);
        // Same NUMBER of days before: five days against five, never five against seven.
        $this->assertSame(['2026-10-05', '2026-10-09'], [$w['previous_from']->toDateString(), $w['previous_to']->toDateString()]);
    }

    public function test_monthly_is_the_calendar_month_and_its_comparison_is_the_previous_month_not_thirty_days(): void
    {
        $w = ReportPeriod::resolve('monthly', $this->day('2026-09-01'), $this->day('2026-09-20'), $this->day('2026-10-16'));

        $this->assertSame(['2026-09-01', '2026-09-30'], [$w['from']->toDateString(), $w['to']->toDateString()]);
        $this->assertFalse($w['partial']);
        // September (30 days) is compared with August (31) — the previous CALENDAR month.
        $this->assertSame('2026-08-01', $w['previous_from']->toDateString());
        $this->assertSame('2026-08-30', $w['previous_to']->toDateString());
    }

    public function test_a_month_in_progress_is_compared_with_the_same_days_of_the_last_one(): void
    {
        $w = ReportPeriod::resolve('monthly', $this->day('2026-10-01'), $this->day('2026-10-10'), $this->day('2026-10-10'));

        $this->assertTrue($w['partial']);
        $this->assertSame(['2026-10-01', '2026-10-10'], [$w['from']->toDateString(), $w['to']->toDateString()]);
        $this->assertSame(['2026-09-01', '2026-09-10'], [$w['previous_from']->toDateString(), $w['previous_to']->toDateString()]);
    }

    public function test_custom_is_exactly_the_range_asked_for_with_the_same_length_before(): void
    {
        $w = ReportPeriod::resolve('custom', $this->day('2026-09-01'), $this->day('2026-09-10'));

        $this->assertSame(['2026-09-01', '2026-09-10'], [$w['from']->toDateString(), $w['to']->toDateString()]);
        $this->assertSame(['2026-08-22', '2026-08-31'], [$w['previous_from']->toDateString(), $w['previous_to']->toDateString()]);
        $this->assertSame('day', $w['grain']);
    }

    public function test_the_grain_follows_the_length_of_a_custom_range(): void
    {
        $this->assertSame('week', ReportPeriod::resolve('custom', $this->day('2026-06-01'), $this->day('2026-08-31'))['grain']);
        $this->assertSame('month', ReportPeriod::resolve('custom', $this->day('2025-01-01'), $this->day('2026-01-01'))['grain']);
    }

    public function test_weekly_buckets_sum_the_additive_measures_and_drop_every_ratio(): void
    {
        $days = [];
        foreach (['2026-10-05' => 10, '2026-10-06' => 20, '2026-10-12' => 5] as $date => $clicks) {
            $days[] = ['date' => $date, 'clicks' => $clicks, 'impressions' => $clicks * 100, 'ctr' => 0.01];
        }

        $weeks = ReportPeriod::bucket($days, 'week');

        $this->assertEquals([['date' => '2026-10-05', 'clicks' => 30.0, 'impressions' => 3000.0], ['date' => '2026-10-12', 'clicks' => 5.0, 'impressions' => 500.0]], $weeks);
    }

    /** NASEL 2026-10-28 — a rolling link's ceiling a year ahead made «this month» 2027-10-01 → 2026-10-28 and every figure 0. */
    public function test_a_future_anchor_never_moves_the_month_or_week_past_today(): void
    {
        $today = $this->day('2026-10-28');

        $m = ReportPeriod::resolve('monthly', $this->day('2026-09-28'), $this->day('2027-10-28'), $today);
        $this->assertSame(['2026-10-01', '2026-10-28'], [$m['from']->toDateString(), $m['to']->toDateString()]);
        $this->assertSame(['2026-09-01', '2026-09-28'], [$m['previous_from']->toDateString(), $m['previous_to']->toDateString()]);

        $w = ReportPeriod::resolve('weekly', $this->day('2026-09-28'), $this->day('2027-10-28'), $today);
        $this->assertSame(['2026-10-26', '2026-10-28'], [$w['from']->toDateString(), $w['to']->toDateString()]);
        $this->assertTrue($m['from']->lte($m['to']) && $w['from']->lte($w['to']), 'a window never ends before it starts');
    }

    public function test_an_unknown_kind_is_custom(): void
    {
        $this->assertSame('custom', ReportPeriod::resolve('hourly', $this->day('2026-09-01'), $this->day('2026-09-02'))['kind']);
    }
}
