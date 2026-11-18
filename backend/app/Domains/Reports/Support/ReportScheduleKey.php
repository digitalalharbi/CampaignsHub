<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Reports\Models\ReportSchedule;
use Illuminate\Support\Str;

/**
 * CONS-SCHEDULE-ONE — what makes two report schedules the SAME schedule.
 *
 * Two rows that would build the same report, for the same project, at the same moment, in the same language and send it
 * to the same people are one schedule saved twice: each fires, each makes a report, each says «ready». The key is every
 * field that changes what is built or who receives it, normalised (case, order, defaults the dispatcher applies), and
 * nothing that does not (the id, the run times, the paused state).
 *
 * Recipients and formats ARE part of it on purpose: a copy that reaches a different person is not a copy, and disabling
 * it would silently stop somebody's delivery. Only true copies collapse.
 */
final class ReportScheduleKey
{
    /** @param  ReportSchedule|array<string,mixed>  $s */
    public static function for(ReportSchedule|array $s): string
    {
        $v = $s instanceof ReportSchedule ? $s->getAttributes() + ['formats' => $s->formats, 'recipients' => $s->recipients] : $s;

        $recipients = collect(self::list($v['recipients'] ?? []))
            ->map(fn ($r) => Str::lower(trim((string) (is_array($r) ? ($r['email'] ?? '') : $r))))
            ->filter()->unique()->sort()->values()->all();
        $formats = collect(self::list($v['formats'] ?? []))->map(fn ($f) => Str::lower((string) $f))->filter()->unique()->sort()->values()->all();

        return hash('sha256', json_encode([
            (string) ($v['tenant_id'] ?? ''),
            (string) ($v['project_id'] ?? ''),
            // A schedule tied to a saved report is that report's schedule; otherwise its name says which report it builds.
            filled($v['report_id'] ?? null) ? 'report:'.$v['report_id'] : 'name:'.Str::lower(trim((string) ($v['name'] ?? ''))),
            Str::lower((string) ($v['type'] ?? '')),
            Str::lower((string) ($v['frequency'] ?? '')),
            Str::lower(trim((string) ($v['day'] ?? ''))),
            (string) (($v['time'] ?? null) ?: '08:00'),
            (string) (($v['timezone'] ?? null) ?: 'Asia/Riyadh'),
            (string) (($v['audience'] ?? null) ?: 'client'),
            (string) (($v['language'] ?? null) ?: 'ar'),
            trim((string) ($v['cron'] ?? '')),
            $recipients,
            $formats === [] ? ['pdf'] : $formats,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @return array<int,mixed> */
    private static function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values($value) : [];
    }
}
