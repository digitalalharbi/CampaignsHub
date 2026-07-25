<?php

declare(strict_types=1);

namespace App\Domains\Reports\Jobs;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $report->update(['status' => 'processing', 'error' => null]);
        try {
            $data = $generator->generate($report);
            $report->update(['data' => $data, 'status' => 'completed', 'generated_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $report->update(['status' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Report::withoutGlobalScopes()->where('id', $this->reportId)->update(['status' => 'failed', 'error' => $e->getMessage()]);
    }
}
