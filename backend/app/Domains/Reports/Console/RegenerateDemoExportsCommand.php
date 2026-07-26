<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Services\ReportExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Regenerates a fresh PDF export for every completed DEMO report through the current pipeline
 * (Chromium + all validators). Only demo data — never touches real client reports.
 */
final class RegenerateDemoExportsCommand extends Command
{
    protected $signature = 'reports:regenerate-demo-exports {--format=pdf : Export format to regenerate}';

    protected $description = 'Regenerate demo report exports through the current renderer + validators.';

    public function handle(ReportExporter $exporter): int
    {
        $format = (string) $this->option('format');
        $reports = Report::withoutGlobalScopes()
            ->where('is_demo', true)->where('status', 'completed')->whereNotNull('data')->get();

        $ok = $fail = 0;
        foreach ($reports as $report) {
            $export = ReportExport::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $report->tenant_id,
                'report_id' => $report->id,
                'format' => $format,
                'status' => 'processing',
                'is_demo' => true,
            ]);

            try {
                $exporter->export($report->fresh(), $export);
                $ok++;
                $this->line("  ✓ {$report->name} → {$export->fresh()->renderer}");
            } catch (Throwable $e) {
                $export->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 300)]);
                $fail++;
                $this->warn("  ✗ {$report->name}: ".Str::limit($e->getMessage(), 120));
            }
        }

        $this->info("Regenerated {$ok} demo export(s), {$fail} failed.");

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }
}
