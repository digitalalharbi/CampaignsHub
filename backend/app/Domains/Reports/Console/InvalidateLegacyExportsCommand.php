<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Services\ReportExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Marks PDF exports produced by an older engine/template (or with no provenance) as failed and
 * removes their stored file, so they can never be downloaded. Scoped to demo data by default —
 * real client exports are only ever invalidated with an explicit --all and confirmation.
 */
final class InvalidateLegacyExportsCommand extends Command
{
    protected $signature = 'reports:invalidate-legacy-exports {--demo-only : Only touch is_demo exports} {--all : Include real exports (requires confirmation)}';

    protected $description = 'Invalidate stale/legacy PDF report exports so they cannot be downloaded.';

    public function handle(): int
    {
        $demoOnly = (bool) $this->option('demo-only') || ! (bool) $this->option('all');
        if (! $demoOnly && ! $this->confirm('Invalidate REAL client exports too? This cannot be undone.')) {
            $this->warn('Aborted. Re-run with --demo-only for a safe pass.');

            return self::FAILURE;
        }

        $currentRv = (string) config('reports.chromium.renderer_version', 'chromium-1228');
        $query = ReportExport::withoutGlobalScopes()->where('format', 'pdf')->where('status', 'completed');
        if ($demoOnly) {
            $query->where('is_demo', true);
        }

        $touched = 0;
        foreach ($query->cursor() as $export) {
            $legacy = ($export->validation_status ?? 'unknown') !== 'passed'
                || $export->renderer_version !== $currentRv
                || $export->template_version !== ReportExporter::TEMPLATE_VERSION;
            if (! $legacy) {
                continue;
            }
            if ($export->path && Storage::disk($export->disk)->exists($export->path)) {
                Storage::disk($export->disk)->delete($export->path);
            }
            $export->update(['status' => 'failed', 'error' => 'invalidated: legacy renderer/template', 'path' => null, 'signed_token' => null]);
            $touched++;
        }

        $this->info("Invalidated {$touched} legacy export(s)".($demoOnly ? ' (demo only).' : '.'));

        return self::SUCCESS;
    }
}
