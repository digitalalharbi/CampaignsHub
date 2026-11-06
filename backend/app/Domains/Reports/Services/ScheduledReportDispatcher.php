<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Reports\Support\ReportIdentity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns due report schedules into report snapshots + an honest delivery ledger. Reuses the existing report
 * engine (a fresh Report + GenerateReportJob) — no parallel generator. Delivery is honest: with no mail
 * provider each row is "awaiting_provider_credentials", never "sent". An internal report is NEVER delivered
 * to an external recipient (suppressed). Runs from the reports:dispatch-scheduled command.
 *
 * EMAIL-GAPS — a row is `queued` only when mail may actually follow, and {@see ScheduledReportMailer}
 * reads nothing else. `awaiting_provider_credentials` keeps meaning no provider, and a live install with
 * the mail switched off now says that instead of claiming to wait for credentials it already has.
 */
final class ScheduledReportDispatcher
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    /** @return int number of schedules dispatched */
    public function dispatchDue(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $count = 0;

        // Schedules are tenant/project-scoped models; dispatch runs cron-wide with no tenant resolved, so BOTH
        // scopes go. Dropping only the project scope left TenantScope failing closed: the cron found nothing.
        // Each report carries its own tenant_id/project_id.
        $due = ReportSchedule::withoutGlobalScopes()
            ->where('active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->get();

        foreach ($due as $schedule) {
            if ($this->claimDue($schedule, $now)) {
                $this->dispatchOne($schedule, $now);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Take this run, or learn that an overlapping sweep already took it.
     *
     * The `next_run_at` this copy read is part of the condition, so of two sweeps holding the same due
     * row exactly one moves it on. The other gets no row back and creates no report.
     */
    public function claimDue(ReportSchedule $schedule, Carbon $now): bool
    {
        return ReportSchedule::withoutGlobalScopes()
            ->whereKey($schedule->getKey())
            ->where('active', true)
            ->where('next_run_at', $schedule->next_run_at)
            ->update(['next_run_at' => $this->nextRun($schedule, $now)]) === 1;
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
            'currency' => ReportingCurrency::DEFAULT,
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
                    /*
                     * REPORT-TITLE-METADATA-001 — the line the recipient reads first, decided now.
                     *
                     * With no mail provider on this install every row waits at
                     * `awaiting_provider_credentials`, so a subject left to send-time would be chosen
                     * months later from a report whose name may have changed. Recorded here it is the
                     * document's own name at the moment the delivery was scheduled.
                     */
                    'subject' => ReportIdentity::subject($report, $schedule->language ?? 'ar'),
                    'audience' => $schedule->audience ?? 'client',
                    // Internal reports never leave the tenant — an external recipient is suppressed. Otherwise
                    // there is no mail provider yet, so the honest state is awaiting_provider_credentials.
                    ...$this->deliveryStatus($schedule, $email),
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

    /** @return array{status: string, reason: ?string} */
    private function deliveryStatus(ReportSchedule $schedule, string $email): array
    {
        if (($schedule->audience ?? 'client') === 'internal') {
            // Internal reports may only go to a user of the same tenant.
            $internal = User::query()->inTenant((string) $schedule->tenant_id)
                ->whereRaw('lower(email) = ?', [Str::lower($email)])->exists();
            if (! $internal) {
                return ['status' => 'suppressed', 'reason' => 'internal_audience'];
            }
        }

        if (! $this->providers->isConfigured('email')) {
            return ['status' => 'awaiting_provider_credentials', 'reason' => null];
        }

        return config('reports.schedule_mail.enabled') === true
            ? ['status' => 'queued', 'reason' => null]
            : ['status' => 'suppressed', 'reason' => 'schedule_mail_off'];
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
