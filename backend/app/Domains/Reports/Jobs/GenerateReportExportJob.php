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
