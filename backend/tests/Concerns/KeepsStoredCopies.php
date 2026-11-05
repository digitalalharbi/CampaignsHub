<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportVersion;
use App\Domains\Reports\Services\ReportGenerator;

/**
 * REPORT-SNAPSHOT-PROTECT-001 — one statement of «this path rebuilds a report and the copy it replaces is kept», for every
 * test class that owns the fixtures of one such path.
 */
trait KeepsStoredCopies
{
    /**
     * Make the report a completed one holding a generated copy, as a client would have seen it.
     *
     * @return array<string, mixed> the stored copy
     */
    protected function storeCopy(string $reportId, string $generatedAt = '2026-09-01 10:00:00'): array
    {
        $report = Report::withoutGlobalScopes()->findOrFail($reportId);
        // A real document from the generator, so every reader's own validation accepts it; the marker tells this copy from a rebuilt one.
        $data = app(ReportGenerator::class)->generate($report) + ['marker' => 'the copy the client saw'];
        $report->forceFill(['status' => 'completed', 'data' => $data, 'generated_at' => $generatedAt, 'error' => null])->save();

        return $data;
    }

    /**
     * The path under test has asked for a rebuild; run it and require the replaced copy in the history, as it was.
     *
     * @param  array<string, mixed>  $original
     */
    protected function assertCopyKeptThroughRebuild(string $reportId, array $original, string $generatedAt = '2026-09-01 10:00:00'): void
    {
        $before = Report::withoutGlobalScopes()->findOrFail($reportId);
        $this->assertSame('processing', $before->status, 'the path did not ask for a rebuild');
        $this->assertEquals($original, $before->data, 'the stored copy changed before the rebuild finished');

        (new GenerateReportJob($reportId))->handle(app(ReportGenerator::class));

        $versions = ReportVersion::withoutGlobalScopes()->where('report_id', $reportId)->get();
        $this->assertCount(1, $versions, 'the copy the rebuild replaced was not kept exactly once');
        $this->assertEquals($original, $versions[0]->data, 'the kept copy is not the copy that was stored');
        $this->assertSame($generatedAt, $versions[0]->generated_at->format('Y-m-d H:i:s'));

        $after = Report::withoutGlobalScopes()->findOrFail($reportId);
        $this->assertSame('completed', $after->status);
        $this->assertNotSame('the copy the client saw', $after->data['marker'] ?? null, 'the rebuild never replaced the copy');
    }
}
