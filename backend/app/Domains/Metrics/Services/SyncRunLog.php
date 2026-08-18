<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

/**
 * INTEG-RUNTIME §8 §9 — the same answer, forty-eight times a day, said once.
 *
 * ## What this is for
 *
 * The sweep runs every thirty minutes. An account whose platform has nothing to report produces an
 * identical run each time: same status, same window, same sentence. A log of that is forty-eight
 * indistinguishable rows a day, and the brief names the consequence outright — «لا تكرر نفس الخطأ كل
 * ٣٠ دقيقة في واجهة مزعجة». Nobody scrolls it, so the one row that IS different is the one nobody
 * sees.
 *
 * Every run is still recorded. This collapses only what is CONSECUTIVE and IDENTICAL, and says how
 * many and since when — «لا توجد بيانات للفترة · ٤٨ محاولة متطابقة منذ 06:00». Nothing is hidden: a
 * change of any kind — a different status, a different window, a different error — starts a new row,
 * which is exactly the moment a reader needs to notice.
 *
 * ## Consecutive, not grouped
 *
 * Grouping by status across the whole log would put yesterday's failure next to today's, which is a
 * different claim and a false one. Runs are collapsed only while they are adjacent in time, so the
 * log stays a chronology.
 */
final class SyncRunLog
{
    /** The fields that make two runs THE SAME EVENT rather than two events with the same shape. */
    private const IDENTITY = ['status', 'trigger', 'window_start', 'window_end', 'error', 'provider'];

    /**
     * @param  list<array<string,mixed>>  $rows  in the order they will be read (newest first)
     * @return list<array<string,mixed>>
     */
    public static function collapse(array $rows): array
    {
        $collapsed = [];

        foreach ($rows as $row) {
            $previous = $collapsed === [] ? null : $collapsed[count($collapsed) - 1];

            if ($previous !== null && self::sameEvent($previous, $row)) {
                $index = count($collapsed) - 1;
                $collapsed[$index]['repeats'] = ((int) ($previous['repeats'] ?? 1)) + 1;
                /*
                 * The rows arrive newest first, so each further match is OLDER — it moves the START
                 * of the streak backwards, never its end. Writing the end here would report the
                 * oldest attempt as the most recent one.
                 */
                $collapsed[$index]['repeats_since'] = $row['started_at'] ?? $previous['repeats_since'] ?? null;

                continue;
            }

            $row['repeats'] = 1;
            $row['repeats_since'] = $row['started_at'] ?? null;
            $collapsed[] = $row;
        }

        return $collapsed;
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private static function sameEvent(array $a, array $b): bool
    {
        foreach (self::IDENTITY as $field) {
            if (($a[$field] ?? null) !== ($b[$field] ?? null)) {
                return false;
            }
        }

        /*
         * A run that STORED something is never folded into one that did not, even when every other
         * field matches. «Nothing happened, forty-eight times» and «nothing happened forty-seven
         * times and then data arrived» are different days, and the second is the one being waited for.
         */
        return (int) ($a['metrics_imported'] ?? 0) === (int) ($b['metrics_imported'] ?? 0);
    }
}
