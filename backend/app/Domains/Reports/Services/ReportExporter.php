<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Renders a generated report's snapshot into a real downloadable file (PDF / XLSX / CSV), stores it
 * on a private disk, and stamps the export row with a signed token + expiry for temporary downloads.
 */
final class ReportExporter
{
    private const DISK = 'local';

    /** Render a report to file bytes for a format, without persisting anything (used by public share). */
    public function render(Report $report, string $format): string
    {
        $data = $report->data ?? [];

        return match ($format) {
            'csv' => $this->csv($report, $data),
            'xlsx' => $this->xlsx($report, $data),
            'pdf' => $this->pdf($report, $data),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };
    }

    public function export(Report $report, ReportExport $export): void
    {
        $slug = Str::slug($report->name) ?: 'report';
        $ext = $export->format;
        $path = "reports/{$report->tenant_id}/{$report->id}/{$slug}-".now()->format('Ymd-His').".{$ext}";

        $content = $this->render($report, $export->format);

        Storage::disk(self::DISK)->put($path, $content);

        $export->update([
            'status' => 'completed',
            'disk' => self::DISK,
            'path' => $path,
            'size' => strlen($content),
            'signed_token' => Str::random(48),
            'expires_at' => Carbon::now()->addDays(7),
        ]);
    }

    private function csv(Report $report, array $data): string
    {
        $fh = fopen('php://temp', 'r+');
        $put = fn (array $row) => fputcsv($fh, $row, ',', '"', '');
        $put(["Report: {$report->name}", $report->type]);
        $put(['Period', ($data['period']['from'] ?? '').' → '.($data['period']['to'] ?? ''), 'Currency', $report->currency]);
        $put([]);
        $put(['KPIs']);
        foreach (($data['kpis'] ?? []) as $k => $v) {
            $put([$k, is_numeric($v) ? $v : ($v ?? '')]);
        }
        $put([]);
        $put(['Platforms', 'spend', 'revenue', 'conversions', 'roas', 'cpa', 'ctr', 'share']);
        foreach (($data['platforms'] ?? []) as $p) {
            $put([$p['provider'], $p['spend'], $p['revenue'], $p['conversions'], $p['roas'], $p['cpa'], $p['ctr'], $p['spend_share'] ?? '']);
        }
        $put([]);
        $put(['Campaigns', 'platform', 'spend', 'revenue', 'conversions', 'roas', 'cpa']);
        foreach (($data['campaigns'] ?? []) as $c) {
            $put([$c['campaign_name'] ?? '—', $c['provider'] ?? '', $c['spend'], $c['revenue'], $c['conversions'], $c['roas'], $c['cpa']]);
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);

        return (string) $out;
    }

    private function xlsx(Report $report, array $data): string
    {
        $book = new Spreadsheet;

        $kpi = $book->getActiveSheet();
        $kpi->setTitle('KPIs');
        $kpi->fromArray(['Metric', 'Value'], null, 'A1');
        $row = 2;
        foreach (($data['kpis'] ?? []) as $k => $v) {
            $kpi->setCellValue("A{$row}", $k);
            $kpi->setCellValue("B{$row}", is_numeric($v) ? $v : (string) ($v ?? ''));
            $row++;
        }

        $ps = $book->createSheet();
        $ps->setTitle('Platforms');
        $ps->fromArray(['Platform', 'Spend', 'Revenue', 'Conversions', 'ROAS', 'CPA', 'CTR', 'Share'], null, 'A1');
        $row = 2;
        foreach (($data['platforms'] ?? []) as $p) {
            $ps->fromArray([$p['provider'], $p['spend'], $p['revenue'], $p['conversions'], $p['roas'], $p['cpa'], $p['ctr'], $p['spend_share'] ?? null], null, "A{$row}");
            $row++;
        }

        $cs = $book->createSheet();
        $cs->setTitle('Campaigns');
        $cs->fromArray(['Campaign', 'Platform', 'Spend', 'Revenue', 'Conversions', 'ROAS', 'CPA'], null, 'A1');
        $row = 2;
        foreach (($data['campaigns'] ?? []) as $c) {
            $cs->fromArray([$c['campaign_name'] ?? '—', $c['provider'] ?? '', $c['spend'], $c['revenue'], $c['conversions'], $c['roas'], $c['cpa']], null, "A{$row}");
            $row++;
        }

        $writer = new Xlsx($book);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    private function pdf(Report $report, array $data): string
    {
        return Pdf::loadView('reports.document', [
            'report' => $report,
            'data' => $data,
        ])->setPaper('a4')->output();
    }
}
