<?php

declare(strict_types=1);

namespace App\Domains\Reports\Jobs;

use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ReportVersions;
use App\Support\PeriodLabel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Generates a report's data snapshot on the reports queue. Idempotent: re-running re-snapshots. */
final class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(public readonly string $reportId)
    {
        $this->onQueue('reports');
    }

    public function handle(ReportGenerator $generator): void
    {
        $report = Report::withoutGlobalScopes()->find($this->reportId);
        if (! $report) {
            return;
        }
        // REPORT-DUPLICATE-001 — a draft is not built until it is published; publishing is what moves it to `processing`.
        if ($report->isDraft()) {
            return;
        }
        $report->update(['status' => 'processing', 'error' => null]);
        try {
            $data = $generator->generate($report);
            // REPORT-SNAPSHOT-PROTECT-001 — every path that rebuilds a report ends here, so the copy it replaces is kept here.
            DB::transaction(function () use ($report, $data): void {
                app(ReportVersions::class)->keep($report);
                $report->update(['data' => $data, 'status' => 'completed', 'generated_at' => now(), 'error' => null]);
            });
            if ($this->claimReadyNotice($report)) {
                $this->tell($report, 'report_ready');
            }
        } catch (Throwable $e) {
            // Retries stay quiet; the terminal failure speaks once, from failed().
            $report->update(['status' => $report->hasStoredCopy() ? 'completed' : 'failed', 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * EMAIL-REPORT-READY-001 — the person who asked for the report is told when it is there, and when it
     * is not. `report_ready` and `report_failed` have been catalogue types since the catalogue existed;
     * nothing raised them until now. The dispatcher applies the person's own choices, the quiet hours
     * and the honest email state (awaiting credentials until a provider exists).
     */
    private function tell(Report $report, string $type, ?string $error = null): void
    {
        $ready = $type === 'report_ready';
        $name = (string) $report->name;

        app(NotificationDispatcher::class)->dispatch([
            'tenant_id' => (string) $report->tenant_id,
            'project_id' => $report->project_id ? (string) $report->project_id : null,
            'user_id' => $report->created_by ? (int) $report->created_by : null,
            'type' => $type,
            'severity' => $ready ? 'info' : 'error',
            'title' => $ready ? 'Report ready: '.$name : 'Report failed: '.$name,
            'message' => $ready
                ? 'The report «'.$name.'» for '.($report->period_start !== null && $report->period_end !== null ? PeriodLabel::range($report->period_start, $report->period_end, 'en') : '—').' has been generated.'
                : 'The report «'.$name.'» could not be generated'.($error ? ': '.$error : '.'),
            'title_ar' => $ready ? 'التقرير جاهز: '.$name : 'تعذّر توليد التقرير: '.$name,
            'message_ar' => $ready
                ? 'اكتمل توليد تقرير «'.$name.'» للفترة '.($report->period_start !== null && $report->period_end !== null ? PeriodLabel::range($report->period_start, $report->period_end, 'ar') : '—').'.'
                : 'تعذّر توليد تقرير «'.$name.'»'.($error ? ': '.$error : '.'),
            'source' => 'reports',
            'entity_type' => Report::class,
            'entity_id' => (string) $report->id,
            // NOTIF-LINK-001 — portal-relative: the reader's own portal mounts the reports page.
            'action_url' => '/reports',
            'dedup_extra' => $ready ? 'v'.(int) ($report->version ?? 1) : 'failed',
        ]);
    }

    /**
     * CONS-REPORT-READY-ONCE — «Report ready» is said once per report and version, whatever rebuilds it.
     *
     * Every rebuild (a note, a section switch, a pack refresh, a retry) moved `generated_at`, which was the dedup extra, and
     * the dispatcher only collapses repeats inside one hour, so the same report and version was announced 24 times. The key
     * is claimed in one conditional UPDATE: of two jobs finishing together exactly one wins, and a later rebuild of the same
     * version finds it taken. A new version is news again.
     */
    private function claimReadyNotice(Report $report): bool
    {
        $version = (int) ($report->version ?? 1);

        return Report::withoutGlobalScopes()
            ->whereKey($report->getKey())
            ->where(fn ($q) => $q->whereNull('ready_notified_version')->orWhere('ready_notified_version', '<>', $version))
            ->update(['ready_notified_version' => $version]) === 1;
    }

    public function failed(Throwable $e): void
    {
        $report = Report::withoutGlobalScopes()->find($this->reportId);
        if ($report !== null) {
            // A failed rebuild does not take the copy it failed to replace offline.
            $report->update(['status' => $report->hasStoredCopy() ? 'completed' : 'failed', 'error' => $e->getMessage()]);
            $this->tell($report, 'report_failed', $e->getMessage());
        }
    }
}
