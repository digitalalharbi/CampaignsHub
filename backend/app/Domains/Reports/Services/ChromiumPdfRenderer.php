<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Reports\Models\Report;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Renders a report to PDF by driving headless Chromium (Playwright) over the React print route, so the
 * PDF is pixel-identical to the interactive report (same components, fonts, RTL). It mints a print
 * token via the internal endpoint, points Chromium at /reports/print/{token}, and waits for the page's
 * readiness signals. Any failure throws — the caller marks the export Failed rather than shipping a
 * broken/partial file. Config lives in config/reports.php.
 */
final class ChromiumPdfRenderer
{
    public function __construct(private readonly ExportReadinessGate $gate) {}

    public function isEnabled(): bool
    {
        return (bool) config('reports.chromium.enabled', false);
    }

    /**
     * @param  'presentation'|'document'  $type
     */
    public function render(Report $report, string $type = 'presentation', string $theme = 'light'): string
    {
        $this->gate->ensureReady($report);

        $token = $this->issueToken($report, $type, $theme);
        $appUrl = rtrim((string) config('reports.chromium.app_url'), '/');
        $url = "{$appUrl}/reports/print/{$token}?type={$type}&theme={$theme}";

        $out = tempnam(sys_get_temp_dir(), 'chpdf_').'.pdf';
        $config = [
            'url' => $url,
            'out' => $out,
            'landscape' => $type === 'presentation',
            'timeoutMs' => (int) config('reports.chromium.timeout_ms', 45000),
            'chromiumPath' => config('reports.chromium.chromium_path') ?: null,
            'requireBase' => config('reports.chromium.require_base'),
        ];

        $process = new Process(
            [config('reports.chromium.node_bin', 'node'), config('reports.chromium.script'), json_encode($config)],
            base_path(),
            ['NODE_ENV' => 'production'],
            null,
            (int) config('reports.chromium.timeout_ms', 45000) / 1000 + 15,
        );
        $process->run();

        if (! $process->isSuccessful() || ! is_file($out) || filesize($out) === 0) {
            $err = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            @unlink($out);
            throw new RuntimeException('Chromium PDF render failed: '.Str::limit($err, 500));
        }

        $this->normalizeArabicTextLayer($out);

        $bytes = (string) file_get_contents($out);
        @unlink($out);

        return $bytes;
    }

    /**
     * Rewrite the Arabic text layer (ToUnicode) in place so copy/search/AT get base letters
     * instead of Chromium's presentation-form glyphs, then VALIDATE. This is a fail-closed gate:
     * if normalisation cannot drive presentation forms to zero (or any invariant fails), the
     * script exits non-zero and we throw — a client Arabic PDF is never shipped with a broken
     * text layer. The written report (text-layer-validation.json) is returned to the caller.
     *
     * @return array<string,mixed> the validation report
     */
    private function normalizeArabicTextLayer(string $path): array
    {
        if (! config('reports.chromium.arabic_textlayer_fix', true)) {
            return ['status' => 'skipped'];
        }
        $script = (string) config('reports.chromium.textlayer_script');
        if ($script === '' || ! is_file($script)) {
            throw new RuntimeException('Arabic text-layer normaliser missing: '.$script);
        }

        $report = $path.'.textlayer.json';
        $proc = new Process(
            [(string) config('reports.chromium.python_bin', 'python3'), $script, $path, '--report', $report],
            base_path(),
            null,
            null,
            120,
        );
        $proc->run();

        $data = is_file($report) ? json_decode((string) file_get_contents($report), true) : null;
        @unlink($report);

        if (! $proc->isSuccessful() || ! is_array($data) || ($data['status'] ?? null) !== 'passed') {
            $reason = is_array($data)
                ? 'checks='.json_encode($data['checks'] ?? [], JSON_UNESCAPED_UNICODE)
                : Str::limit(trim($proc->getErrorOutput()), 300);
            throw new RuntimeException('Arabic text-layer validation failed — export blocked. '.$reason);
        }

        return $data;
    }

    /** Call the internal print-token endpoint so the token is minted through the same gated flow. */
    private function issueToken(Report $report, string $type, string $theme): string
    {
        // In-process token mint (no HTTP round-trip): mirror ReportPrintController::issue. The audience
        // carries through so the print route receives backend-filtered (client-safe) data.
        $token = Str::random(48);
        Cache::put(
            'report-print:'.hash('sha256', $token),
            ['report_id' => (string) $report->id, 'type' => $type, 'theme' => $theme, 'audience' => $report->audience ?? 'client'],
            300,
        );

        return $token;
    }

    /** Best-effort reachability check for the print app (used by health/preflight). */
    public function appReachable(): bool
    {
        try {
            return Http::timeout(3)->get((string) config('reports.chromium.app_url'))->status() < 500;
        } catch (\Throwable) {
            return false;
        }
    }
}
