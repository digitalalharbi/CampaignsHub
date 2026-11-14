<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Funding\Models\FundingPayment;
use App\Domains\Reports\Services\ChromiumPdfRenderer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * BUDGET-PDF-001 — the budgets summary as a PDF: every project the reader reaches, with its period, budget, spend,
 * remaining and the part each platform spent, and (when asked) the ledger entries under each project.
 *
 * The figures are the ones the XLSX and CSV carry — the controller hands the SAME `FundingBalance` rows to all
 * three — so the file cannot disagree with the page. Rendered the way the invoice is: Blade → headless
 * Chromium (which shapes and joins the Arabic) → the report pipeline's fail-closed Arabic text-layer repair,
 * so the Arabic in the file is real text that can be searched and copied.
 */
final class FundingPdf
{
    public function __construct(private readonly ChromiumPdfRenderer $chromium) {}

    public function available(): bool
    {
        return (bool) config('reports.chromium.enabled', false);
    }

    /**
     * @param  list<array<string, mixed>>  $rows  client + project + FundingBalance::of()
     * @param  array<string, list<FundingPayment>>  $history  ledger entries by project id (empty = not asked for)
     */
    public function html(array $rows, array $history, string $asOf, string $lang = 'ar'): string
    {
        $lang = $lang === 'en' ? 'en' : 'ar';
        $prepaid = array_filter($rows, static fn (array $r): bool => $r['payment_method'] === 'prepaid');

        return View::make('funding.document', [
            'rows' => $rows,
            'history' => $history,
            'asOf' => $asOf,
            'lang' => $lang,
            'dir' => $lang === 'ar' ? 'rtl' : 'ltr',
            'totals' => [
                'approved' => array_sum(array_column($prepaid, 'approved')),
                'spent' => array_sum(array_column($prepaid, 'spent')),
                'remaining' => array_sum(array_map(static fn (array $r): float => (float) $r['remaining'], $prepaid)),
            ],
        ])->render();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, list<FundingPayment>>  $history
     * @return string the PDF bytes
     */
    public function render(array $rows, array $history, string $asOf, string $lang = 'ar'): string
    {
        if (! $this->available()) {
            throw new RuntimeException('PDF rendering is not enabled on this deployment.');
        }

        $html = tempnam(sys_get_temp_dir(), 'chfund_').'.html';
        $out = tempnam(sys_get_temp_dir(), 'chfund_').'.pdf';
        file_put_contents($html, $this->html($rows, $history, $asOf, $lang));

        $timeout = (int) config('reports.chromium.timeout_ms', 45000);
        $process = new Process(
            [config('reports.chromium.node_bin', 'node'), base_path('scripts/html-pdf.mjs'), json_encode([
                'html' => $html,
                'out' => $out,
                'landscape' => true,
                'timeoutMs' => $timeout,
                'chromiumPath' => config('reports.chromium.chromium_path') ?: null,
                'requireBase' => config('reports.chromium.require_base'),
            ])],
            base_path(),
            ['NODE_ENV' => 'production'],
            null,
            $timeout / 1000 + 15,
        );
        $process->run();
        @unlink($html);

        if (! $process->isSuccessful() || ! is_file($out) || filesize($out) === 0) {
            $err = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            @unlink($out);
            throw new RuntimeException('Budgets PDF render failed: '.Str::limit($err, 400));
        }

        $this->chromium->normalizeArabicTextLayer($out);
        $bytes = (string) file_get_contents($out);
        @unlink($out);

        return $bytes;
    }
}
