<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Preflight for the PDF report engine. Surfaces exactly why client exports would fail BEFORE a user
 * hits the button — so a missing Node/Chromium/print-URL is a visible "renderer unavailable" state,
 * not a silent Dompdf fallback. Exit non-zero if any hard dependency is missing.
 */
final class ReportsHealthCommand extends Command
{
    protected $signature = 'reports:health {--json : Machine-readable output}';

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

        if ($this->option('json')) {
            $this->line((string) json_encode(['ready' => $ready, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $ready ? self::SUCCESS : self::FAILURE;
        }

        foreach ($checks as $name => $c) {
            $this->line(sprintf('  %s %-22s %s', $c['ok'] ? '✅' : '❌', $name, $c['detail']));
        }
        $this->newLine();
        $ready
            ? $this->info('PDF Renderer: Chromium — Ready')
            : $this->error('PDF Renderer unavailable — client/executive exports are BLOCKED (fail-closed).');

        return $ready ? self::SUCCESS : self::FAILURE;
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
