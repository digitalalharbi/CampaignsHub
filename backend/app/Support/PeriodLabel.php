<?php

declare(strict_types=1);

namespace App\Support;

use App\Domains\Metrics\Services\PeriodResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * PERIOD-LABEL-001 — the ONE way a period is written in an export, a PDF, a mail, a digest or a notification
 * (Owner order 2026-11-19). The twin of `frontend/src/lib/period/formatPeriodLabel.ts`; both read the same
 * examples from `frontend/src/lib/period/periodLabelExamples.json`, so the two cannot drift.
 *
 *   «{اسم الاختيار}: {تاريخ البداية} – {تاريخ النهاية} {السنة}»
 *
 *   آخر 7 أيام: 12 نوفمبر – 18 نوفمبر 2026        Last 7 days: Nov 12 – Nov 18, 2026
 *   أمس: 18 نوفمبر 2026                           a one-day period is one date
 *   15 ديسمبر 2025 – 14 يناير 2026                a custom range has no name; two years → both years
 *
 * Latin digits, Gregorian, the en-dash «–», the year once at the end (on the first date too only when the two
 * ends are in different years). Dates are Riyadh calendar days, taken as carried (a `YYYY-MM-DD` string, or a date-cast Carbon's own day).
 * `tests/Feature/PeriodLabelGuardTest.php` fails the build when an export or a notification writes a period by hand.
 */
final class PeriodLabel
{
    public const MONTHS_AR = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

    public const MONTHS_EN = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** @var array<string, array{ar: string, en: string}> */
    public const NAMES = [
        'today' => ['ar' => 'اليوم (جاري)', 'en' => 'Today (in progress)'],
        'yesterday' => ['ar' => 'أمس', 'en' => 'Yesterday'],
        'today_yesterday' => ['ar' => 'اليوم وأمس', 'en' => 'Today and yesterday'],
        'last_7' => ['ar' => 'آخر 7 أيام', 'en' => 'Last 7 days'],
        'last_14' => ['ar' => 'آخر 14 يوم', 'en' => 'Last 14 days'],
        'last_28' => ['ar' => 'آخر 28 يوم', 'en' => 'Last 28 days'],
        'last_30' => ['ar' => 'آخر 30 يوم', 'en' => 'Last 30 days'],
        'last_90' => ['ar' => 'آخر 90 يوم', 'en' => 'Last 90 days'],
        'this_week' => ['ar' => 'هذا الأسبوع', 'en' => 'This week'],
        'last_week' => ['ar' => 'الأسبوع الماضي', 'en' => 'Last week'],
        'this_month' => ['ar' => 'هذا الشهر', 'en' => 'This month'],
        'last_month' => ['ar' => 'الشهر الماضي', 'en' => 'Last month'],
        'last_3m' => ['ar' => 'آخر 3 أشهر', 'en' => 'Last 3 months'],
        'last_6m' => ['ar' => 'آخر 6 أشهر', 'en' => 'Last 6 months'],
        'last_12m' => ['ar' => 'آخر 12 شهر', 'en' => 'Last 12 months'],
        'ytd' => ['ar' => 'من بداية السنة', 'en' => 'Year to date'],
        'this_year' => ['ar' => 'السنة الحالية', 'en' => 'This year'],
        'lifetime' => ['ar' => 'طوال المدة', 'en' => 'All time'],
    ];

    /**
     * The label. `$key` is the preset the person (or the schedule) chose; absent or `custom` → the dates alone.
     *
     * @param  string|CarbonInterface  $from  `YYYY-MM-DD` or a date
     */
    public static function format(string|CarbonInterface $from, string|CarbonInterface $to, string $locale = 'ar', ?string $key = null): string
    {
        $range = self::range($from, $to, $locale);

        if ($key === null || $key === 'custom' || ! isset(self::NAMES[$key])) {
            return $range;
        }

        return self::NAMES[$key][self::lang($locale)].': '.$range;
    }

    /** The presets a pair of dates can be named after, in the frontend's `matchPreset` order (lifetime is never inferred). */
    private const INFERRED = [
        'today', 'yesterday', 'today_yesterday', 'last_7', 'last_14', 'last_28', 'last_30', 'last_90',
        'this_week', 'last_week', 'this_month', 'last_month', 'last_3m', 'last_6m', 'last_12m', 'ytd', 'this_year',
    ];

    /**
     * For a notification that knows its dates but not the choice behind them: named after the preset those dates ARE on
     * the Riyadh clock today («أمس», «آخر 7 أيام», «الشهر الماضي»), else the dates alone. `$now` is injectable for tests.
     */
    public static function inferred(string|CarbonInterface $from, string|CarbonInterface $to, string $locale = 'ar', ?CarbonInterface $now = null): string
    {
        $a = self::ymd($from);
        $b = self::ymd($to);
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }
        $at = $now === null ? null : Carbon::instance($now);

        foreach (self::INFERRED as $key) {
            $p = PeriodResolver::preset($key, $at);
            if ($p['from'] === $a && $p['to'] === $b) {
                return self::format($a, $b, $locale, $key);
            }
        }

        return self::range($a, $b, $locale);
    }

    /** The two dates alone: «12 نوفمبر – 18 نوفمبر 2026». */
    public static function range(string|CarbonInterface $from, string|CarbonInterface $to, string $locale = 'ar'): string
    {
        $a = self::ymd($from);
        $b = self::ymd($to);
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }
        $lang = self::lang($locale);

        if ($a === $b) {
            return self::day($a, $lang, true);
        }

        $differentYears = substr($a, 0, 4) !== substr($b, 0, 4);

        return self::day($a, $lang, $differentYears).' – '.self::day($b, $lang, true);
    }

    /** A range that may be open at either end: «1 نوفمبر – …». */
    public static function bounds(string|CarbonInterface|null $from, string|CarbonInterface|null $to, string $locale = 'ar'): string
    {
        if ($from !== null && $to !== null) {
            return self::range($from, $to, $locale);
        }
        $lang = self::lang($locale);

        return match (true) {
            $from !== null => self::day(self::ymd($from), $lang, true).' – …',
            $to !== null => '… – '.self::day(self::ymd($to), $lang, true),
            default => '',
        };
    }

    private static function lang(string $locale): string
    {
        return str_starts_with(strtolower($locale), 'ar') ? 'ar' : 'en';
    }

    private static function ymd(string|CarbonInterface $d): string
    {
        if ($d instanceof CarbonInterface) {
            return $d->toDateString(); // a date as it is carried — the model's date cast is already the calendar day
        }

        return substr(trim($d), 0, 10);
    }

    private static function day(string $ymd, string $lang, bool $withYear): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return $ymd;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if ($lang === 'ar') {
            return $d.' '.self::MONTHS_AR[$mo - 1].($withYear ? ' '.$y : '');
        }

        return self::MONTHS_EN[$mo - 1].' '.$d.($withYear ? ', '.$y : '');
    }
}
