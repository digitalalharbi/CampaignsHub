<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use App\Domains\Branding\Services\SharedLinkBranding;
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
    protected $signature = 'reports:pdf-facts
        {--format=pdf : The export format to inspect}
        {--allow-real : Measure a real report\'s export when no demo one exists. Structural facts only — see the note below}';

    protected $description = 'Measure the newest completed demo export: structure, fonts, text layer, numerals.';

    public function handle(): int
    {
        $format = (string) $this->option('format');

        /*
         * `--allow-real` exists because a production box holds no demo reports.
         *
         * The default stays demo-only and the safeguard is the default for a reason: proving the
         * pipeline must not mean reading somebody's figures. What makes the opt-in defensible is that
         * this command prints NO CONTENT under either setting — page counts, byte sizes, font names
         * and codepoint-RANGE counts. Nothing it emits could reconstruct a client's numbers, and the
         * report's name, path and token are asserted absent by its tests.
         */
        $allowReal = (bool) $this->option('allow-real');

        $export = ReportExport::withoutGlobalScopes()
            ->when(! $allowReal, fn ($q) => $q->where('is_demo', true))
            ->where('format', $format)
            ->where('status', 'completed')
            ->whereNotNull('path')
            ->orderByDesc('created_at')
            ->first();

        if ($export === null) {
            $this->error($allowReal
                ? "No completed {$format} export to measure at all. Generate one first."
                : "No completed demo {$format} export to measure. Generate one first, or pass --allow-real.");

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
            'is_demo' => $export->is_demo ? 'yes' : 'no — real report, structural facts only',
        ] as $key => $value) {
            $this->line(sprintf('  %-18s %s', $key, $value === null ? '—' : (string) $value));
        }

        /*
         * The branding this report is CONFIGURED with, so a measured logo count means something.
         *
         * `PrintDocument` renders a logo only when `logoUrl` is non-null, and its own docblock warns
         * that «code containing `logo_url` is not the same thing as a logo rendering». Without the
         * configuration beside the measurement, a file with no images is indistinguishable from a
         * report that was never given a logo — and a branding check that cannot tell those apart
         * passes vacuously, which is worse than not checking.
         *
         * The NAME is not printed. Whether a name and a logo exist, and where the logo came from, is
         * all this needs to say.
         */
        try {
            $report = $export->report()->withoutGlobalScopes()->first();
            /*
             * The url builder returns a SENTINEL, not a url — and that is the right answer here.
             *
             * `forReport()` takes the callback that would build a logo's address, and calls it only
             * when an asset actually exists. This command needs to know whether one exists and does not
             * want its address: a signed logo url in a workflow log is a url in a workflow log. So the
             * callback returns a fixed word, `logo_url` becomes that word when a logo is configured and
             * stays null when none is, and the distinction survives with nothing leaked.
             */
            $branding = app(SharedLinkBranding::class)->forReport(
                $report,
                (string) $export->tenant_id,
                static fn (?string $path = null): string => 'configured',
            );

            $this->newLine();
            $this->line('configured branding');
            $this->line(sprintf('  %-18s %s', 'name', ($branding['name'] ?? '') !== '' ? 'present' : 'absent'));
            $this->line(sprintf('  %-18s %s', 'logo', ($branding['logo_url'] ?? null) !== null ? 'configured' : 'none configured'));
            $this->line(sprintf('  %-18s %s', 'logo_source', (string) ($branding['logo_source'] ?? '—')));
        } catch (Throwable $e) {
            $this->warn('  could not read the configured branding: '.$e->getMessage());
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
