<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Services\ExportFailureReason;
use App\Domains\Reports\Services\ReportExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — render ONE report, so there is a real file to judge.
 *
 * ## Why this exists rather than the demo path
 *
 * The acceptance run reached production, found the renderer ready, and had nothing to render. The
 * census says why, in counts only:
 *
 *     reports 14 · completed 14 · with a snapshot 5 · demo 0 · renderable 5 · exports 0
 *
 * Fourteen real reports, five of them renderable, **no demo report at all**, and not one export ever
 * produced on that box. `reports:regenerate-demo-exports` is therefore correct to do nothing, and the
 * bar this row is held to — a real production PDF that opens — cannot be met by the demo path there.
 *
 * ## What it does, and what it deliberately will not
 *
 * Exactly ONE report, the most recently updated renderable one, through the ordinary chain: the same
 * `ReportExporter` the export button drives, the same renderer, the same validators, the same storage.
 * It is the artisan spelling of a click, on a report that already exists.
 *
 * It sends nothing, shares nothing and creates no link: one export row and one file, which is what the
 * button produces. `--allow-real` is required and there is no default that touches a client's report by
 * accident — a run without it says what it would have done and stops.
 *
 * ## What it prints
 *
 * Whether it rendered, the bytes, and the provenance stamped on the row. Never the report's name,
 * never the stored path, never the signed token — this output belongs in a workflow log, and
 * `reports:pdf-facts` is what then looks inside the file.
 */
final class RenderAcceptanceExportCommand extends Command
{
    protected $signature = 'reports:render-acceptance
        {--format=pdf : The export format to produce}
        {--allow-real : Required. Renders ONE real report — see the note in this class}';

    protected $description = 'Render one existing report through the real export chain, so a produced file exists to measure.';

    public function handle(ReportExporter $exporter): int
    {
        $format = (string) $this->option('format');

        $report = Report::withoutGlobalScopes()
            ->where('status', 'completed')
            ->whereNotNull('data')
            ->orderByDesc('updated_at')
            ->first();

        if ($report === null) {
            $this->error('No renderable report on this installation — nothing to render.');

            return self::FAILURE;
        }

        // A dry statement of intent, so the flag is a decision and not a formality.
        if (! $this->option('allow-real')) {
            $this->warn(sprintf(
                'Would render one %s for the most recently updated renderable report (is_demo=%s). Pass --allow-real to do it.',
                $format,
                $report->is_demo ? 'yes' : 'no',
            ));

            return self::FAILURE;
        }

        $export = ReportExport::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $report->tenant_id,
            'report_id' => $report->id,
            'format' => $format,
            'status' => 'processing',
            'is_demo' => (bool) $report->is_demo,
        ]);

        try {
            $exporter->export($report->fresh(), $export);
        } catch (Throwable $e) {
            /*
             * The reason, classified — not the renderer's stderr.
             *
             * `ExportFailureReason` exists so an operator reads «the renderer is not enabled on this
             * server» instead of a stack trace, and a failure here is exactly the case it was written
             * for. The raw message is stored on the row, where the diagnostic already knows to truncate
             * it; what this prints is the word.
             */
            $export->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 300)]);

            $this->error('The render failed: '.ExportFailureReason::classify($e->getMessage()));

            return self::FAILURE;
        }

        $fresh = $export->fresh();

        $this->info('Rendered one export.');
        foreach ([
            'status' => $fresh?->status,
            'bytes' => $fresh?->size,
            'renderer' => $fresh?->renderer,
            'renderer_version' => $fresh?->renderer_version,
            'template_version' => $fresh?->template_version,
            'layout_mode' => $fresh?->layout_mode,
            'validation_status' => $fresh?->validation_status,
            'is_demo' => $fresh?->is_demo ? 'yes' : 'no — a real report, rendered as the button would',
        ] as $key => $value) {
            $this->line(sprintf('  %-18s %s', $key, $value === null ? '—' : (string) $value));
        }

        return self::SUCCESS;
    }
}
