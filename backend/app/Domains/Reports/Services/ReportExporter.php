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

    public function __construct(
        private readonly ExportReadinessGate $gate,
        private readonly ChromiumPdfRenderer $chromium,
        private readonly ClientReportView $clientView,
        private readonly ClientReportContentValidator $contentValidator,
    ) {}

    /** Render a report to file bytes for a format, without persisting anything (used by public share). */
    public function render(Report $report, string $format): string
    {
        // No format may render until the snapshot passes the data-consistency gate.
        $this->gate->ensureReady($report);
        // SINGLE enforcement point: every export path (admin export, scheduled, email, share) is filtered
        // by the report's audience here — an authenticated admin can NEVER bypass client filtering.
        $data = $this->audienceData($report);

        return match ($format) {
            'csv' => $this->csv($report, $data),
            'xlsx' => $this->xlsx($report, $data),
            'pdf' => $this->pdf($report, $data),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };
    }

    /**
     * The audience-correct snapshot for a report. Client/executive exports are filtered (approved recs,
     * client names, no internal fields) AND content-validated — a leaky client file is never produced.
     * Internal exports keep the full snapshot.
     *
     * @return array<string,mixed>
     */
    private function audienceData(Report $report): array
    {
        $data = $report->data ?? [];
        $audience = $report->audience ?? 'client';
        if (in_array($audience, ['client', 'executive'], true)) {
            $data = $audience === 'executive' ? $this->clientView->executive($data) : $this->clientView->filter($data);
            $violations = $this->contentValidator->scan($data);
            abort_if(
                $violations !== [],
                422,
                'Client export blocked — internal content detected: '.implode(', ', array_unique(array_column($violations, 'code'))),
            );
        }

        return $data;
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
            $put([$p['provider'] ?? '', $p['spend'] ?? '', $p['revenue'] ?? '', $p['conversions'] ?? '', $p['roas'] ?? '', $p['cpa'] ?? '', $p['ctr'] ?? '', $p['spend_share'] ?? '']);
        }
        $put([]);
        $put(['Campaigns', 'platform', 'spend', 'revenue', 'conversions', 'roas', 'cpa']);
        foreach (($data['campaigns'] ?? []) as $c) {
            $put([$c['campaign_name'] ?? '—', $c['provider'] ?? '', $c['spend'] ?? '', $c['revenue'] ?? '', $c['conversions'] ?? '', $c['roas'] ?? '', $c['cpa'] ?? '']);
        }
        // Methodology & metadata manifest appended once (never repeated per data row).
        $put([]);
        $put(['Methodology & Notes / المنهجية والملاحظات']);
        foreach ($this->methodologyRows($report, $data) as $row) {
            $put($row);
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
            $ps->fromArray([$p['provider'] ?? '', $p['spend'] ?? null, $p['revenue'] ?? null, $p['conversions'] ?? null, $p['roas'] ?? null, $p['cpa'] ?? null, $p['ctr'] ?? null, $p['spend_share'] ?? null], null, "A{$row}");
            $row++;
        }

        $cs = $book->createSheet();
        $cs->setTitle('Campaigns');
        $cs->fromArray(['Campaign', 'Platform', 'Spend', 'Revenue', 'Conversions', 'ROAS', 'CPA'], null, 'A1');
        $row = 2;
        foreach (($data['campaigns'] ?? []) as $c) {
            $cs->fromArray([$c['campaign_name'] ?? '—', $c['provider'] ?? '', $c['spend'] ?? null, $c['revenue'] ?? null, $c['conversions'] ?? null, $c['roas'] ?? null, $c['cpa'] ?? null], null, "A{$row}");
            $row++;
        }

        // Audience-specific sheets — CREATED per audience, never created-then-hidden. Client shows only
        // approved recommendations + next steps; internal adds findings + all recommendations + raw
        // metrics + data quality; executive stays lean.
        $audience = $report->audience ?? 'client';
        $add = fn (string $title, array $head, array $rows) => $this->xlsxSheet($book, $title, $head, $rows);

        if ($audience === 'client' || $audience === 'executive') {
            $add('Approved Recommendations', ['Action', 'Reason', 'Platform', 'Priority'], array_map(
                fn ($r) => [$r['title'] ?? '', $r['detail'] ?? '', $r['platform'] ?? '', $r['priority'] ?? 'normal'],
                array_filter($data['recommendations'] ?? [], fn ($r) => ($r['status'] ?? '') === 'approved'),
            ));
            $add('Next Steps', ['Action', 'Reason', 'Platform', 'Priority', 'Owner', 'Due'], array_map(
                fn ($s) => [$s['action'] ?? '', $s['reason'] ?? '', $s['platform'] ?? '', $s['priority'] ?? '', $s['owner'] ?? '', $s['due'] ?? ''],
                $data['next_steps'] ?? [],
            ));
        }
        if ($audience === 'internal') {
            $add('Findings', ['Title', 'Detail', 'Platform', 'Severity', 'Status'], array_map(
                fn ($f) => [$f['title'] ?? '', $f['detail'] ?? '', $f['platform'] ?? '', $f['severity'] ?? '', $f['status'] ?? ''],
                $data['findings'] ?? [],
            ));
            $add('All Recommendations', ['Title', 'Detail', 'Platform', 'Status', 'Priority', 'AI'], array_map(
                fn ($r) => [$r['title'] ?? '', $r['detail'] ?? '', $r['platform'] ?? '', $r['status'] ?? '', $r['priority'] ?? '', ! empty($r['is_ai_generated']) ? 'yes' : 'no'],
                $data['recommendations'] ?? [],
            ));
            $add('Data Quality', ['Check', 'Result'], $this->dataQualityRows($data));
            $add('Raw Metrics', ['Date', 'Spend', 'Revenue', 'Conversions', 'ROAS', 'CPA'], array_map(
                fn ($t) => [$t['date'] ?? '', $t['spend'] ?? null, $t['revenue'] ?? null, $t['conversions'] ?? null, $t['roas'] ?? null, $t['cpa'] ?? null],
                $data['timeseries'] ?? [],
            ));
        }
        if (! empty($data['funnel']) && $audience !== 'executive') {
            $add('Funnel', ['Stage', 'Count', 'Step Rate', 'Cost Per'], array_map(
                fn ($s) => [$s['label'] ?? '', $s['count'] ?? null, $s['step_rate'] ?? null, $s['cost_per'] ?? null],
                $data['funnel'],
            ));
        }
        if (! empty($data['budget'])) {
            $add('Budget', ['Campaign', 'Budget', 'Spent', 'Remaining', 'Consumed', 'Pace'], array_map(
                fn ($b) => [$b['campaign_name'] ?? '', $b['budget'] ?? null, $b['spent'] ?? null, $b['remaining'] ?? null, $b['consumed_pct'] ?? null, $b['pace'] ?? null],
                $data['budget'],
            ));
        }

        $notes = $book->createSheet();
        $notes->setTitle('Methodology & Notes');
        $notes->fromArray(['المنهجية والملاحظات', ''], null, 'A1');
        $row = 2;
        foreach ($this->methodologyRows($report, $data) as $pair) {
            $notes->fromArray([$pair[0] ?? '', $pair[1] ?? ''], null, "A{$row}");
            $row++;
        }
        $notes->getColumnDimension('A')->setWidth(28);
        $notes->getColumnDimension('B')->setWidth(90);
        $notes->getStyle('B1:B'.$row)->getAlignment()->setWrapText(true);

        $writer = new Xlsx($book);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * Append a titled sheet with a header row + data rows.
     *
     * @param  list<string>  $head
     * @param  list<array<int,mixed>>  $rows
     */
    private function xlsxSheet(Spreadsheet $book, string $title, array $head, array $rows): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle(substr($title, 0, 31)); // Excel sheet-name limit
        $sheet->fromArray($head, null, 'A1');
        $r = 2;
        foreach ($rows as $row) {
            $sheet->fromArray(array_values($row), null, "A{$r}");
            $r++;
        }
    }

    /**
     * Internal-only data-quality checks derived from the snapshot (no secrets/stack traces).
     *
     * @param  array<string,mixed>  $data
     * @return list<array{0:string,1:string}>
     */
    private function dataQualityRows(array $data): array
    {
        $k = $data['kpis'] ?? [];
        $platformSpend = array_sum(array_map(fn ($p) => (float) ($p['spend'] ?? 0), $data['platforms'] ?? []));
        $burners = count(array_filter($data['campaigns'] ?? [], fn ($c) => ($c['spend'] ?? 0) > 3000 && ($c['conversions'] ?? 0) < 2));

        return [
            ['Platform spend vs summary', abs($platformSpend - (float) ($k['spend'] ?? 0)) < 1 ? 'reconciled' : 'MISMATCH'],
            ['Campaigns with spend, no conversions', (string) $burners],
            ['Data source', (string) ($data['data_source'] ?? 'daily_metrics')],
            ['Attribution window', (string) ($data['attribution_window'] ?? 'default')],
            ['Report mode', (string) ($data['mode'] ?? 'snapshot')],
            ['Generated at', (string) ($data['generated_at'] ?? '')],
        ];
    }

    /**
     * Disclaimer/methodology + data-lineage rows shared by CSV & XLSX (single block, not per-row).
     *
     * @param  array<string,mixed>  $data
     * @return list<array{0:string,1:string}>
     */
    private function methodologyRows(Report $report, array $data): array
    {
        $disc = $data['disclaimer'] ?? [];
        $loc = $disc['locale_default'] ?? 'ar';
        $sec = $disc['sections'] ?? [];
        $enabled = fn (string $k): bool => ($disc['enabled'][$k] ?? true) === true;
        $txt = fn (string $k): ?string => data_get($sec, "{$k}.{$loc}") ?? data_get($sec, "{$k}.ar");

        $rows = [];
        if ($enabled('full') && $txt('full')) {
            $rows[] = ['Disclaimer / إخلاء المسؤولية', (string) $txt('full')];
        }
        if ($enabled('methodology') && $txt('methodology')) {
            $rows[] = ['Methodology / المنهجية', (string) $txt('methodology')];
        }
        if ($enabled('objectives') && ! empty($data['objective'])) {
            $ot = data_get($sec, "objectives.{$data['objective']}.{$loc}", data_get($sec, "objectives.{$data['objective']}.ar"));
            if ($ot) {
                $rows[] = ['Objective note / ملاحظة الهدف', (string) $ot];
            }
        }
        if ($enabled('freshness') && $txt('freshness')) {
            $rows[] = ['Data freshness / تحديث البيانات', (string) $txt('freshness')];
        }
        $rows[] = ['Data source / مصدر البيانات', (string) $report->data_source];
        $rows[] = ['Attribution window / نافذة الإسناد', (string) ($report->attribution_window ?? '—')];
        $rows[] = ['Currency / العملة', (string) $report->currency];
        $rows[] = ['Timezone / المنطقة الزمنية', (string) $report->timezone];
        $rows[] = ['Report mode / وضع التقرير', ($report->config['mode'] ?? 'snapshot') === 'live' ? 'Live' : 'Snapshot'];
        $rows[] = ['Generated at / تاريخ الإنشاء', (string) (optional($report->generated_at)->toDateTimeString() ?? now()->toDateTimeString())];
        $rows[] = ['File created / إنشاء الملف', now()->toDateTimeString()];

        return $rows;
    }

    private function pdf(Report $report, array $data): string
    {
        // Creative Arabic reports render via headless Chromium over the print route (correct RTL, real
        // charts, fonts). A failure throws so the export is marked Failed — never a broken/partial file.
        if ($this->chromium->isEnabled()) {
            $type = ($report->config['pdf_type'] ?? 'presentation') === 'document' ? 'document' : 'presentation';

            return $this->chromium->render($report, $type);
        }

        // Fallback: the simple Dompdf document layout (text-first; used only when Chromium is disabled).
        return Pdf::loadView('reports.document', [
            'report' => $report,
            'data' => $data,
        ])->setPaper('a4')->output();
    }
}
