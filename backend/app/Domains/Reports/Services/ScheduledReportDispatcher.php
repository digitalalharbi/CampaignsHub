<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Projects\Concerns\ProjectScope;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns due report schedules into report snapshots + an honest delivery ledger. Reuses the existing report
 * engine (a fresh Report + GenerateReportJob) — no parallel generator. Delivery is honest: with no mail
 * provider each row is "awaiting_provider_credentials", never "sent". An internal report is NEVER delivered
 * to an external recipient (suppressed). Runs from the reports:dispatch-scheduled command.
 */
final class ScheduledReportDispatcher
{
    /** @return int number of schedules dispatched */
    public function dispatchDue(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $count = 0;

        // Schedules are tenant/project-scoped models; dispatch runs cron-wide, so drop the project scope and
        // read across tenants (each report carries its own tenant_id/project_id).
        $due = ReportSchedule::withoutGlobalScope(ProjectScope::class)
            ->where('active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->get();

        foreach ($due as $schedule) {
            $this->dispatchOne($schedule, $now);
            $count++;
        }

        return $count;
    }

    public function dispatchOne(ReportSchedule $schedule, Carbon $now): Report
    {
        // 1) Create a fresh snapshot report from the schedule and queue generation (the existing engine).
        $report = new Report;
        $report->forceFill([
            'tenant_id' => $schedule->tenant_id,
            'project_id' => $schedule->project_id,
            'name' => $schedule->name.' — '.$now->toDateString(),
            'type' => $schedule->type,
            'audience' => $schedule->audience ?? 'client',
            'mode' => 'snapshot',
            'status' => 'processing',
            'period_start' => $now->copy()->subDays(29)->toDateString(),
            'period_end' => $now->toDateString(),
            'currency' => 'SAR',
            'created_by' => $schedule->created_by,
        ])->save();
        GenerateReportJob::dispatch((string) $report->id);

        // 2) Record an honest delivery per recipient × format.
        $recipients = is_array($schedule->recipients) ? $schedule->recipients : [];
        $formats = is_array($schedule->formats) && $schedule->formats !== [] ? $schedule->formats : ['pdf'];
        foreach ($recipients as $recipient) {
            $email = is_array($recipient) ? ($recipient['email'] ?? null) : $recipient;
            if (! $email) {
                continue;
            }
            foreach ($formats as $format) {
                DB::table('report_deliveries')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $schedule->tenant_id,
                    'schedule_id' => $schedule->id,
                    'report_id' => $report->id,
                    'channel' => 'email',
                    'recipient' => $email,
                    'format' => $format,
                    'audience' => $schedule->audience ?? 'client',
                    // Internal reports never leave the tenant — an external recipient is suppressed. Otherwise
                    // there is no mail provider yet, so the honest state is awaiting_provider_credentials.
                    'status' => $this->deliveryStatus($schedule, $email),
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // 3) Advance the schedule.
        $schedule->forceFill([
            'last_run_at' => $now,
            'next_run_at' => $this->nextRun($schedule, $now),
        ])->save();

        return $report;
    }

    private function deliveryStatus(ReportSchedule $schedule, string $email): string
    {
        if (($schedule->audience ?? 'client') === 'internal') {
            // Internal reports may only go to a user of the same tenant.
            $internal = DB::table('users')->where('tenant_id', $schedule->tenant_id)->whereRaw('lower(email) = ?', [Str::lower($email)])->exists();
            if (! $internal) {
                return 'suppressed';
            }
        }

        return 'awaiting_provider_credentials';
    }

    /** Compute the next run in the schedule's timezone from daily/weekly/monthly/custom + day + time. */
    public function nextRun(ReportSchedule $schedule, Carbon $now): Carbon
    {
        $tz = $schedule->timezone ?: 'Asia/Riyadh';
        [$h, $m] = array_pad(explode(':', (string) ($schedule->time ?: '08:00')), 2, '00');
        $local = $now->copy()->setTimezone($tz)->setTime((int) $h, (int) $m, 0);

        $next = match ($schedule->frequency) {
            'daily' => $local->lte($now) ? $local->addDay() : $local,
            'weekly' => $this->nextWeekly($local, $now, (string) ($schedule->day ?: 'sunday')),
            'monthly' => $this->nextMonthly($local, $now, (int) ($schedule->day ?: 1)),
            'custom' => $local->addDay(), // a real cron parser would consult $schedule->cron; daily fallback
            default => $local->addWeek(),
        };

        return $next->setTimezone(config('app.timezone', 'UTC'));
    }

    private function nextWeekly(Carbon $local, Carbon $now, string $day): Carbon
    {
        // Carbon::next() rewinds to 00:00, which would silently fire every weekly schedule at midnight
        // instead of its configured hour — re-apply the time the schedule actually asked for.
        $target = $local->copy()->next(ucfirst($day))->setTimeFrom($local);
        // If today IS the target weekday and the time is still ahead, keep today.
        if (strtolower($local->englishDayOfWeek) === strtolower($day) && $local->gt($now)) {
            return $local;
        }

        return $target;
    }

    private function nextMonthly(Carbon $local, Carbon $now, int $dom): Carbon
    {
        $candidate = $local->copy()->day(min($dom, $local->daysInMonth));
        if ($candidate->lte($now)) {
            $candidate = $candidate->addMonthNoOverflow()->day(min($dom, $candidate->copy()->addMonthNoOverflow()->daysInMonth));
        }

        return $candidate;
    }
}
