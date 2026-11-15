<?php

declare(strict_types=1);

namespace Tests\Unit\Metrics;

use App\Domains\Metrics\Services\PeriodResolver;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DATE-RANGE-001 — the backend twin of `frontend/src/lib/period/period.test.ts`, on the same fixed clock.
 * 2026-11-15 is a Sunday. Every rolling preset ends yesterday; only «today»/«this» include today.
 */
final class PeriodResolverTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-11-15 09:00:00', 'Asia/Riyadh');
    }

    /** @return array<string, array{string,string,string,bool}> */
    public static function presets(): array
    {
        return [
            'last 30 days ends yesterday' => ['last_30', '2026-10-16', '2026-11-14', false],
            'last 7 days' => ['last_7', '2026-11-08', '2026-11-14', false],
            'last 14' => ['last_14', '2026-11-01', '2026-11-14', false],
            'last 28' => ['last_28', '2026-10-18', '2026-11-14', false],
            'last 90' => ['last_90', '2026-08-17', '2026-11-14', false],
            'this month is ongoing' => ['this_month', '2026-11-01', '2026-11-15', true],
            'last month' => ['last_month', '2026-10-01', '2026-10-31', false],
            'this week starts Sunday' => ['this_week', '2026-11-15', '2026-11-15', true],
            'last week' => ['last_week', '2026-11-08', '2026-11-14', false],
            'today' => ['today', '2026-11-15', '2026-11-15', true],
            'yesterday' => ['yesterday', '2026-11-14', '2026-11-14', false],
            'today and yesterday' => ['today_yesterday', '2026-11-14', '2026-11-15', true],
            'last 3 months' => ['last_3m', '2026-08-15', '2026-11-14', false],
            'last 6 months' => ['last_6m', '2026-05-15', '2026-11-14', false],
            'last 12 months' => ['last_12m', '2025-11-15', '2026-11-14', false],
            'year to date' => ['ytd', '2026-01-01', '2026-11-14', false],
        ];
    }

    #[DataProvider('presets')]
    public function test_preset_resolves_on_the_fixed_clock(string $key, string $from, string $to, bool $ongoing): void
    {
        $this->assertSame(['key' => $key, 'from' => $from, 'to' => $to, 'ongoing' => $ongoing], PeriodResolver::preset($key, $this->now));
    }

    public function test_the_clock_is_riyadh_not_utc(): void
    {
        // 21:30 UTC on the 14th is already the 15th in Riyadh.
        $this->assertSame('2026-11-15', PeriodResolver::today(Carbon::parse('2026-11-14 21:30:00', 'UTC'))->toDateString());
        $this->assertSame('2026-11-14', PeriodResolver::today(Carbon::parse('2026-11-14 20:59:00', 'UTC'))->toDateString());
    }

    public function test_previous_period_of_last_30_days(): void
    {
        $p = PeriodResolver::preset('last_30', $this->now);

        $this->assertSame(['mode' => 'previous', 'from' => '2026-09-16', 'to' => '2026-10-15'], PeriodResolver::comparison($p['from'], $p['to'], 'previous'));
    }

    public function test_same_period_last_year_clamps_leap_day(): void
    {
        $this->assertSame(['mode' => 'last_year', 'from' => '2027-02-28', 'to' => '2027-03-02'], PeriodResolver::comparison('2028-02-29', '2028-03-02', 'last_year'));
    }

    public function test_a_custom_span_is_ongoing_only_when_it_reaches_today(): void
    {
        $this->assertTrue(PeriodResolver::custom('2026-11-10', '2026-11-15', $this->now)['ongoing']);
        $this->assertFalse(PeriodResolver::custom('2026-11-10', '2026-11-14', $this->now)['ongoing']);
        $this->assertSame('2026-11-01', PeriodResolver::custom('2026-11-14', '2026-11-01', $this->now)['from']);
    }

    public function test_year_to_date_on_new_years_day_is_today_alone(): void
    {
        $this->assertSame(['key' => 'ytd', 'from' => '2027-01-01', 'to' => '2027-01-01', 'ongoing' => true], PeriodResolver::preset('ytd', Carbon::parse('2027-01-01 10:00', 'Asia/Riyadh')));
    }
}
