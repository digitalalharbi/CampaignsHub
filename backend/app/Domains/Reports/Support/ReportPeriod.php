<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use Illuminate\Support\Carbon;

/**
 * REPORT-PERIOD-001 — daily, weekly, monthly and custom are DATA-WINDOW semantics, not labels.
 *
 * Each kind decides four things the rest of the report is built from:
 *
 *   window      daily = the anchor day · weekly = the ISO week (Mon–Sun) holding the anchor · monthly = the
 *               calendar month holding the anchor · custom = exactly what was asked.
 *   comparison  the window it is compared with — the previous DAY, the previous ISO WEEK, the previous
 *               CALENDAR MONTH (28–31 days, so «same length before» would compare 31 days with 30) — and,
 *               for custom, the same length immediately before.
 *   grain       what one trend point is: a day, a week or a month. Daily data cannot honestly draw an
 *               intra-day curve, so a daily report's trend is the trailing week with the day marked; a
 *               year of daily points, on the other hand, is a chart nobody can read.
 *   wording     what the comparison is called — «مقابل الأمس», «مقابل الأسبوع السابق», «مقابل الشهر السابق».
 *
 * A window is never extended past `$today`: a monthly report opened on the 10th covers the 1st to the
 * 10th and says so through `partial`, and its comparison is the previous month's SAME number of days,
 * not the whole of it — comparing ten days with thirty would print a collapse that did not happen.
 */
final class ReportPeriod
{
    public const KINDS = ['daily', 'weekly', 'monthly', 'custom'];

    /**
     * @return array{kind: string, from: Carbon, to: Carbon, previous_from: Carbon, previous_to: Carbon, grain: string, partial: bool, comparison_ar: string, comparison_en: string}
     */
    public static function resolve(?string $kind, Carbon $from, Carbon $to, ?Carbon $today = null): array
    {
        $kind = in_array($kind, self::KINDS, true) ? (string) $kind : 'custom';
        $today ??= Carbon::now();

        return match ($kind) {
            'daily' => self::daily($to, $today),
            'weekly' => self::weekly($to, $today),
            'monthly' => self::monthly($to, $today),
            default => self::custom($from, $to),
        };
    }

    private static function daily(Carbon $anchor, Carbon $today): array
    {
        $day = $anchor->copy()->startOfDay()->min($today->copy()->startOfDay());

        return [
            'kind' => 'daily', 'from' => $day, 'to' => $day->copy(),
            'previous_from' => $day->copy()->subDay(), 'previous_to' => $day->copy()->subDay(),
            // A single day has no curve: the trend is the trailing week, one point per day.
            'grain' => 'day', 'partial' => false,
            'comparison_ar' => 'مقابل الأمس', 'comparison_en' => 'vs the previous day',
        ];
    }

    private static function weekly(Carbon $anchor, Carbon $today): array
    {
        $start = $anchor->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $end = $start->copy()->addDays(6)->min($today->copy()->startOfDay());
        $length = (int) $start->diffInDays($end) + 1;
        $previousStart = $start->copy()->subWeek();

        return [
            'kind' => 'weekly', 'from' => $start, 'to' => $end,
            'previous_from' => $previousStart, 'previous_to' => $previousStart->copy()->addDays($length - 1),
            'grain' => 'day', 'partial' => $length < 7,
            'comparison_ar' => 'مقابل الأسبوع السابق', 'comparison_en' => 'vs the previous week',
        ];
    }

    private static function monthly(Carbon $anchor, Carbon $today): array
    {
        $start = $anchor->copy()->startOfMonth()->startOfDay();
        $full = $start->copy()->endOfMonth()->startOfDay();
        $end = $full->copy()->min($today->copy()->startOfDay());
        $length = (int) $start->diffInDays($end) + 1;
        $previousStart = $start->copy()->subMonthNoOverflow()->startOfMonth();
        $previousEnd = $previousStart->copy()->addDays($length - 1)->min($previousStart->copy()->endOfMonth()->startOfDay());

        return [
            'kind' => 'monthly', 'from' => $start, 'to' => $end,
            'previous_from' => $previousStart, 'previous_to' => $previousEnd,
            'grain' => $length > 31 ? 'week' : 'day', 'partial' => $end->lt($full),
            'comparison_ar' => 'مقابل الشهر السابق', 'comparison_en' => 'vs the previous month',
        ];
    }

    private static function custom(Carbon $from, Carbon $to): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;

        return [
            'kind' => 'custom', 'from' => $start, 'to' => $end,
            'previous_from' => $start->copy()->subDays($days), 'previous_to' => $start->copy()->subDay(),
            'grain' => $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month'), 'partial' => false,
            'comparison_ar' => 'مقابل الفترة السابقة', 'comparison_en' => 'vs the previous period',
        ];
    }

    /**
     * One trend point per grain bucket: additive measures are SUMMED, and every ratio is dropped — a
     * weekly CTR is not the mean of seven daily ones, and the chart reads the additive keys.
     *
     * @param  list<array<string, mixed>>  $series  daily rows keyed by `date`
     * @return list<array<string, mixed>>
     */
    public static function bucket(array $series, string $grain): array
    {
        if ($grain === 'day') {
            return $series;
        }

        $additive = ['impressions', 'clicks', 'conversions', 'leads', 'spend', 'revenue', 'video_views', 'video_completions', 'engagements', 'purchases', 'installs'];
        $buckets = [];

        foreach ($series as $row) {
            $day = Carbon::parse((string) ($row['date'] ?? ''));
            $key = $grain === 'week' ? $day->copy()->startOfWeek(Carbon::MONDAY)->toDateString() : $day->copy()->startOfMonth()->toDateString();
            $buckets[$key] ??= ['date' => $key];

            foreach ($additive as $measure) {
                if (isset($row[$measure]) && is_numeric($row[$measure])) {
                    $buckets[$key][$measure] = ($buckets[$key][$measure] ?? 0) + (float) $row[$measure];
                }
            }
        }

        ksort($buckets);

        return array_values($buckets);
    }
}
