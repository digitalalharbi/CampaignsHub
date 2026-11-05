<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportVersion;

/** REPORT-SNAPSHOT-PROTECT-001 — keeps the stored copy a rebuild or a restore is about to replace. */
final class ReportVersions
{
    /** Keyed on the copy's own generation time, so a retried job keeps a copy once. */
    public function keep(Report $report): void
    {
        if (! $report->hasStoredCopy()) {
            return;
        }

        ReportVersion::withoutGlobalScopes()->firstOrCreate(
            ['report_id' => $report->getKey(), 'generated_at' => $report->generated_at],
            ['tenant_id' => $report->tenant_id, 'project_id' => $report->project_id, 'data' => $report->data],
        );
    }
}
