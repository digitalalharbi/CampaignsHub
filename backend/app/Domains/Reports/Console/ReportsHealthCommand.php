<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Preflight for the PDF report engine. Surfaces exactly why client exports would fail BEFORE a user
 * hits the button — so a missing Node/Chromium/print-URL is a visible "renderer unavailable" state,
 * not a silent Dompdf fallback. Exit non-zero if any hard dependency is missing.
 */
final class ReportsHealthCommand extends Command
{
    protected $signature = 'reports:health
        {--json : Machine-readable output}
        {--recent=0 : Also list the N most recent export attempts, with why each one ended as it did}';

    protected $description = 'Check the report PDF renderer dependencies (Node, Playwright, Chromium, print URL, fonts, storage, queue, normalizer).';

    public function handle(): int
    {
        $checks = [
            'chromium_flag' => $this->ok((bool) config('reports.chromium.enabled'), 'REPORTS_CHROMIUM_ENABLED'),
            'node' => $this->probe([config('reports.chromium.node_bin', 'node'), '--version']),
            'print_script' => $this->ok(is_file((string) config('reports.chromium.script')), (string) config('reports.chromium.script')),
            'playwright' => $this->ok($this->hasPlaywright(), 'playwright-core resolvable'),
            'chromium_binary' => $this->ok($this->hasChromium(), 'Playwright Chromium installed'),
            'print_url' => $this->reachable((string) config('reports.chromium.app_url')),
            'arabic_font' => $this->ok($this->hasFont(), 'IBM Plex Sans Arabic (@fontsource)'),
            'textlayer_normalizer' => $this->ok(is_file((string) config('reports.chromium.textlayer_script')), 'fix-arabic-textlayer.py'),
            'python' => $this->probe([config('reports.chromium.python_bin', 'python3'), '--version']),
            'storage' => $this->ok($this->storageWritable(), 'local disk writable'),
            'queue' => $this->ok(config('queue.default') !== null, 'queue connection: '.config('queue.default')),
        ];

        $ready = ! in_array(false, array_map(fn ($c) => $c['ok'], $checks), true);

        /*
         * REPORT-EXPORT-FUNCTIONAL-001 — the preflight says the renderer COULD work. This says what
         * it actually did.
         *
         * The owner reports that PDF export does not work in the real product, and every check above
         * can pass while every export still fails: a dependency present on the box is not a PDF in
         * somebody's hands. The export rows carry the answer — status, the reason a failure carried,
         * the renderer version that produced it and whether the Arabic text layer validated — and
         * that answer lives on the server and nowhere else.
         *
         * Read-only, and deliberately says nothing about WHAT any report contained: an id, a status,
         * a size and a reason. A diagnostic that printed a client's report name would be a different
         * kind of problem.
         */
        $recent = $this->recentExports((int) $this->option('recent'));

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['ready' => $ready, 'checks' => $checks, 'recent_exports' => $recent],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $ready ? self::SUCCESS : self::FAILURE;
        }

        foreach ($checks as $name => $c) {
            $this->line(sprintf('  %s %-22s %s', $c['ok'] ? '✅' : '❌', $name, $c['detail']));
        }
        $this->newLine();
        $ready
            ? $this->info('PDF Renderer: Chromium — Ready')
            : $this->error('PDF Renderer unavailable — client/executive exports are BLOCKED (fail-closed).');

        if ($recent !== []) {
            $this->newLine();
            $this->line('  RECENT EXPORTS — what the renderer actually produced');
            $this->table(
                ['created', 'format', 'status', 'bytes', 'renderer', 'validation', 'reason'],
                array_map(static fn (array $r): array => [
                    $r['created_at'] ?? '—',
                    $r['format'] ?? '—',
                    $r['status'] ?? '—',
                    $r['size'] === null ? '—' : (string) $r['size'],
                    $r['renderer_version'] ?? '—',
                    $r['validation_status'] ?? '—',
                    $r['error'] ?? '',
                ], $recent),
            );
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The most recent export attempts, and why each ended as it did.
     *
     * No report name, no path, no signed token — an export's IDENTITY is not what a renderer
     * diagnostic is for, and a workflow log is not a place to put one. `error` is the renderer's own
     * message, which is ours rather than a client's.
     *
     * @return list<array<string,mixed>>
     */
    private function recentExports(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return DB::table('report_exports')
            ->orderByDesc('created_at')
            ->limit(min($limit, 50))
            ->get(['created_at', 'format', 'status', 'size', 'renderer', 'renderer_version', 'validation_status', 'error'])
            ->map(static fn (object $r): array => [
                'created_at' => (string) $r->created_at,
                'format' => $r->format,
                'status' => $r->status,
                'size' => $r->size === null ? null : (int) $r->size,
                'renderer' => $r->renderer,
                'renderer_version' => $r->renderer_version,
                'validation_status' => $r->validation_status,
                // Truncated: a stack trace in a workflow log helps nobody and hides the line that does.
                'error' => $r->error === null ? null : Str::limit((string) $r->error, 300),
            ])
            ->all();
    }

    /** @return array{ok:bool,detail:string} */
    private function ok(bool $ok, string $detail): array
    {
        return ['ok' => $ok, 'detail' => $detail];
    }

    /** @param list<string> $cmd @return array{ok:bool,detail:string} */
    private function probe(array $cmd): array
    {
        try {
            $p = new Process($cmd, base_path());
            $p->run();

            return $this->ok($p->isSuccessful(), trim($p->getOutput()) ?: implode(' ', $cmd));
        } catch (Throwable $e) {
            return $this->ok(false, $e->getMessage());
        }
    }

    private function hasPlaywright(): bool
    {
        $base = (string) config('reports.chromium.require_base');

        return $base !== '' && is_file(dirname($base).'/node_modules/playwright-core/package.json');
    }

    private function hasChromium(): bool
    {
        $home = getenv('HOME') ?: '';

        return $home !== '' && is_dir($home.'/Library/Caches/ms-playwright')
            && count(glob($home.'/Library/Caches/ms-playwright/chromium-*') ?: []) > 0;
    }

    private function hasFont(): bool
    {
        $base = (string) config('reports.chromium.require_base');

        return $base !== '' && is_dir(dirname($base).'/node_modules/@fontsource/ibm-plex-sans-arabic');
    }

    private function storageWritable(): bool
    {
        try {
            $probe = 'reports/.health-'.uniqid();
            Storage::disk('local')->put($probe, 'ok');
            $ok = Storage::disk('local')->exists($probe);
            Storage::disk('local')->delete($probe);

            return $ok;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{ok:bool,detail:string} */
    private function reachable(string $url): array
    {
        try {
            return $this->ok(Http::timeout(3)->get($url)->status() < 500, $url);
        } catch (Throwable) {
            return $this->ok(false, $url.' (unreachable)');
        }
    }
}
