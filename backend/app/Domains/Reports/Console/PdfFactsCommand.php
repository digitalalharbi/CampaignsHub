<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use App\Domains\Reports\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — measure a PDF production actually produced.
 *
 * The acceptance bar for this row is a real production file that opens and reads correctly. Every
 * other check we have stops short of it: `reports:health` says the renderer COULD run, the export row
 * says it DID run, and neither has looked inside the bytes. This does, on the box that wrote them.
 *
 * ## Demo exports only, deliberately
 *
 * A real client's report is not an acceptance fixture. `--demo` is the default and the only path this
 * command offers, so proving the pipeline never means reading somebody's figures. Demo reports go
 * through the identical chain — same renderer, same validators, same storage — so what passes here is
 * a statement about the pipeline, not about the data.
 *
 * ## What it prints
 *
 * Structural facts and provenance. Never the report's name, never the stored path, never the signed
 * token, and not one word of the content: the Arabic and Latin figures are counts of codepoint ranges
 * in the text layer, and identifiers are counted rather than named. The output belongs in a workflow
 * log, and that is the whole constraint.
 */
final class PdfFactsCommand extends Command
{
    protected $signature = 'reports:pdf-facts {--format=pdf : The export format to inspect}';

    protected $description = 'Measure the newest completed demo export: structure, fonts, text layer, numerals.';

    public function handle(): int
    {
        $format = (string) $this->option('format');

        $export = ReportExport::withoutGlobalScopes()
            ->where('is_demo', true)
            ->where('format', $format)
            ->where('status', 'completed')
            ->whereNotNull('path')
            ->orderByDesc('created_at')
            ->first();

        if ($export === null) {
            $this->error("No completed demo {$format} export to measure. Generate one first.");

            return self::FAILURE;
        }

        // Provenance first: it is what says this file came from the renderer we think it did.
        $this->line('export provenance');
        foreach ([
            'renderer' => $export->renderer,
            'renderer_version' => $export->renderer_version,
            'template_version' => $export->template_version,
            'layout_mode' => $export->layout_mode,
            'validation_status' => $export->validation_status,
            'locale' => $export->locale,
            'size' => $export->size,
        ] as $key => $value) {
            $this->line(sprintf('  %-18s %s', $key, $value === null ? '—' : (string) $value));
        }

        if (! Storage::disk((string) $export->disk)->exists((string) $export->path)) {
            $this->error('The export row points at a file this disk does not hold.');

            return self::FAILURE;
        }

        // A local copy for the inspector: the disk may not be a local filesystem, and the inspector
        // takes a path. Removed in every case, including a throw.
        $tmp = tempnam(sys_get_temp_dir(), 'pdffacts_').'.'.$format;

        try {
            file_put_contents($tmp, Storage::disk((string) $export->disk)->get((string) $export->path));

            $script = base_path('scripts/pdf-production-facts.py');
            if (! is_file($script)) {
                $this->error('The inspector is missing from this deployment: scripts/pdf-production-facts.py');

                return self::FAILURE;
            }

            $proc = new Process([(string) config('reports.chromium.python_bin', 'python3'), $script, $tmp], base_path(), null, null, 120);
            $proc->run();

            if (! $proc->isSuccessful()) {
                // The inspector's own stderr, which names no file of ours.
                $this->error('The inspector could not read the file: '.trim($proc->getErrorOutput()));

                return self::FAILURE;
            }

            $this->newLine();
            $this->line('measured facts');
            $this->line($proc->getOutput());

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Could not measure the export: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($tmp);
        }
    }
}
