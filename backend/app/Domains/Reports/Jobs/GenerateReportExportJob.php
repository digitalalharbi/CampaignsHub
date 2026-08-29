<?php

declare(strict_types=1);

namespace App\Domains\Reports\Jobs;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Services\ReportExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Renders a report export file (pdf/xlsx/csv) on the reports queue and marks it completed/failed. */
final class GenerateReportExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    /**
     * Three attempts, not three attempts in one second.
     *
     * A render fails for reasons that are still true a moment later — memory pressure on the box, a
     * storage endpoint refusing writes, a font or binary temporarily unavailable. Retrying instantly
     * repeats the failure while its cause is at its worst, spends the attempts, and reports a
     * permanent failure for a condition that would have cleared. Longer than the data job's ladder
     * because a render costs more and its failures are more often about resources than about input.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 90, 300];
    }

    public function __construct(public readonly string $exportId)
    {
        $this->onQueue('reports');
    }

    public function handle(ReportExporter $exporter): void
    {
        $export = ReportExport::withoutGlobalScopes()->find($this->exportId);
        if (! $export) {
            return;
        }
        $report = Report::withoutGlobalScopes()->find($export->report_id);
        if (! $report) {
            $export->update(['status' => 'failed', 'error' => 'Report not found.']);

            return;
        }
        try {
            $exporter->export($report, $export);
        } catch (Throwable $e) {
            $export->update(['status' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        ReportExport::withoutGlobalScopes()->where('id', $this->exportId)->update(['status' => 'failed', 'error' => $e->getMessage()]);
    }
}
