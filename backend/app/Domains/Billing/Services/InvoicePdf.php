<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\Invoice;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Reports\Services\ChromiumPdfRenderer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * INV-PDF-001 — the professional invoice PDF.
 *
 * ## One document, drawn from the record
 *
 * Every figure, name and bank detail on the page is read from the INVOICE and its frozen profile snapshot,
 * never from the live billing profile: an invoice issued last month must print what it said last month,
 * whatever the profile says today. A draft prints from the same fields and says so in a watermark, so a
 * reviewer sees exactly what will be issued and nobody mistakes a draft for a tax invoice.
 *
 * ## Why headless Chromium
 *
 * The invoice is bilingual and the Arabic has to JOIN and read right-to-left. Chromium shapes it, and the
 * same Arabic text-layer repair the report PDF uses is applied afterwards, fail-closed, so the file can be
 * searched, copied and read aloud.
 */
final class InvoicePdf
{
    public function __construct(private readonly ChromiumPdfRenderer $chromium) {}

    public function available(): bool
    {
        return (bool) config('reports.chromium.enabled', false);
    }

    /** The invoice as a self-contained HTML document — also what the tests read. */
    public function html(Invoice $invoice, string $lang = 'ar'): string
    {
        $lang = $lang === 'en' ? 'en' : 'ar';
        $snapshot = (array) ($invoice->profile_snapshot ?? []);
        $profile = (array) ($snapshot['profile'] ?? []);
        $workspace = ClientWorkspace::query()->find($invoice->client_workspace_id);

        return View::make('invoices.document', [
            'invoice' => $invoice,
            'lang' => $lang,
            'dir' => $lang === 'ar' ? 'rtl' : 'ltr',
            'issuer' => (array) ($profile['issuer'] ?? []),
            'billTo' => (array) ($profile['bill_to'] ?? []) + ['name' => $workspace?->name],
            'payment' => (array) ($profile['payment_details'] ?? []),
            'notes' => $profile['notes'] ?? null,
            'isDraft' => in_array($invoice->status, ['draft', 'reviewed'], true),
            'isVoid' => $invoice->status === 'void',
            'status' => $invoice->displayStatus(),
        ])->render();
    }

    /** @return string the PDF bytes */
    public function render(Invoice $invoice, string $lang = 'ar'): string
    {
        if (! $this->available()) {
            throw new RuntimeException('PDF rendering is not enabled on this deployment.');
        }

        $dir = sys_get_temp_dir();
        $html = tempnam($dir, 'chinv_').'.html';
        $out = tempnam($dir, 'chinv_').'.pdf';
        file_put_contents($html, $this->html($invoice, $lang));

        $timeout = (int) config('reports.chromium.timeout_ms', 45000);
        $process = new Process(
            [config('reports.chromium.node_bin', 'node'), base_path('scripts/html-pdf.mjs'), json_encode([
                'html' => $html,
                'out' => $out,
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
            throw new RuntimeException('Invoice PDF render failed: '.Str::limit($err, 400));
        }

        $this->chromium->normalizeArabicTextLayer($out);
        $bytes = (string) file_get_contents($out);
        @unlink($out);

        return $bytes;
    }
}
