<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Models\ReportShare;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Renders a generated report's snapshot into a real downloadable file (PDF / XLSX / CSV), stores it
 * on a private disk, and stamps the export row with a signed token + expiry for temporary downloads.
 */
final class ReportExporter
{
    private const DISK = 'local';

    /** Bumped whenever the creative template changes so old exports are detected as stale on download. */
    public const TEMPLATE_VERSION = '2';

    /**
     * The metric columns a creative row exports, in one place so CSV and XLSX cannot drift apart.
     *
     * Delivery first, then money. A column whose figure the link withholds is emitted EMPTY rather
     * than omitted, so the two formats have the same shape whatever the share permits — a sheet
     * whose columns move depending on the recipient is a sheet nobody can build a template against.
     */
    private const CREATIVE_COLUMNS = [
        'impressions', 'clicks', 'ctr', 'conversions', 'spend', 'revenue', 'cpc', 'cpm', 'cpa', 'roas',
    ];

    public function __construct(
        private readonly ExportReadinessGate $gate,
        private readonly ChromiumPdfRenderer $chromium,
        private readonly ClientReportView $clientView,
        private readonly ClientReportContentValidator $contentValidator,
        private readonly NarrativeConsistencyValidator $narrativeValidator,
    ) {}

    /** Render a report to file bytes for a format, without persisting anything (used by public share). */
    public function render(Report $report, string $format, ?ReportShare $share = null): string
    {
        // No format may render until the snapshot passes the data-consistency gate.
        $this->gate->ensureReady($this->stored($report, $share));
        // SINGLE enforcement point: every export path (admin export, scheduled, email, share) is filtered
        // by the report's audience here — an authenticated admin can NEVER bypass client filtering.
        $data = $this->withoutHiddenSections($report, $this->audienceData($report));

        return match ($format) {
            'csv' => $this->csv($report, $data),
            'xlsx' => $this->xlsx($report, $data),
            'pdf' => $this->pdf($report, $data, $share),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };
    }

    /**
     * REPORT-OPTION-TRAVEL-001 — a section the reader turned off leaves the bytes too.
     *
     * `config.slides` carries a `visible` flag and BOTH renderers honour it: `InteractiveReport` and
     * `PrintReport` each filter on it before drawing. The file did not — the CSV is built from the
     * data keys and never looked at the slides — so a hidden section travelled to the web view and
     * the PDF and still shipped its figures in the spreadsheet.
     *
     * That is worse than an inconsistency, because this class already states the rule it broke: what
     * the client cannot see must not be in the bytes they were sent.
     *
     * Conservative on purpose. A slide type this map does not know leaves its data alone, and a
     * report with NO slide list keeps everything: absent is not hidden, and a report generated before
     * the flag existed must not quietly lose sections.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function withoutHiddenSections(Report $report, array $data): array
    {
        $slides = $report->config['slides'] ?? null;

        if (! is_array($slides) || $slides === []) {
            return $data;
        }

        /** @var array<string, list<string>> $keysOf — slide type → the data keys it draws from. */
        $keysOf = [
            'campaigns' => ['campaigns'],
            'funnel' => ['funnel'],
            'budget' => ['budget'],
            'ads' => ['ads', 'ads_roster', 'ads_groups', 'ads_platform_groups', 'creatives'],
            'next_steps' => ['next_steps'],
            'recommendations' => ['recommendations'],
            'observations' => ['findings'],
            'objective_performance' => ['objective_performance'],
            'platform_comparison' => ['platforms'],
            'comparison' => ['comparison'],
        ];

        /*
         * A section this document does not CONTAIN is not in its file — Owner defect row 96.
         *
         * This dropped a section's data only when its slide was present and explicitly
         * `visible => false`. An executive summary does not mark those slides invisible: the
         * template OMITS them, which is what makes it a shorter document. So nothing was dropped,
         * and the two forms exported the same file.
         *
         * Measured on a generated report with real figures — 38 KPIs, 4 platforms, 6 funnel stages —
         * the executive-summary CSV and the detailed CSV came out byte-identical apart from a
         * one-second difference in their own «generated at» stamp. 3411 bytes each, 61 lines each.
         * The operator's choice reached the slide list and stopped there, so the file a client
         * receives was the same document under two names. That is the placebo control the closure
         * brief rules out, on the copy the client keeps.
         *
         * An absent slide is therefore read the same way as a hidden one. The two states mean the
         * same thing about the finished document — this report has no funnel section — and only
         * differ in whether the composition never had it or an operator switched it off.
         *
         * Fail-safe is already above: a config with no slides at all returns untouched, so a report
         * generated before slide configs existed keeps every section rather than being emptied by a
         * rule it predates.
         */
        $present = [];
        foreach ($slides as $slide) {
            if (is_array($slide) && ($slide['visible'] ?? true) !== false) {
                $present[(string) ($slide['type'] ?? '')] = true;
            }
        }

        foreach ($keysOf as $type => $keys) {
            if (isset($present[$type])) {
                continue;
            }

            foreach ($keys as $key) {
                unset($data[$key]);
            }
        }

        return $data;
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

        // Narrative ↔ snapshot consistency for EVERY audience — a report may never claim "0 results"
        // while the snapshot shows 1,158, nor disagree between its prose and its tables.
        $narrative = $this->narrativeValidator->scan($data);
        abort_if(
            $narrative !== [],
            422,
            'Export blocked — narrative/data mismatch: '.implode(', ', array_unique(array_column($narrative, 'code'))),
        );

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
            // Provenance so a download can prove the file is current (else regenerate). PDF client/exec
            // exports are always chromium (fail-closed); other paths record what actually produced them.
            'renderer' => $export->format === 'pdf' ? $this->rendererFor($report) : 'tabular',
            'renderer_version' => (string) config('reports.chromium.renderer_version', 'chromium-1228'),
            'template_version' => self::TEMPLATE_VERSION,
            'snapshot_checksum' => (string) ($report->data['checksum'] ?? ''),
            'locale' => $this->localeFor($report),
            'layout_mode' => ($report->config['pdf_type'] ?? 'presentation') === 'document' ? 'document' : 'presentation',
            'validation_status' => 'passed', // reached here ⇒ every gate passed (else render() threw)
        ]);
    }

    private function rendererFor(Report $report): string
    {
        $audience = $report->audience ?? 'client';
        if ($this->chromium->isEnabled()) {
            return 'chromium';
        }

        // Client/exec never reach here (pdf() throws); only internal text reports fall back.
        return in_array($audience, ['client', 'executive'], true) ? 'blocked' : 'dompdf';
    }

    private function localeFor(Report $report): string
    {
        return $report->reportLocale();
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
            /*
             * A spreadsheet cell holds a scalar. `kpis` also carries ANNOTATIONS — the money-truth
             * counts, and since AGGREGATION-TRUTH-001 the coverage blocks — and a coverage block is a
             * nested array. `is_numeric()` is false for it, so the old fallback handed the array
             * straight to `fputcsv`, which is «Array to string conversion» and a broken export.
             *
             * Skipped rather than stringified: «Array» in a customer's CSV is worse than the row being
             * absent, and a coverage object flattened into a cell is unreadable either way. The export
             * states the figures; the surfaces that can render coverage properly do that.
             */
            if (is_array($v)) {
                continue;
            }

            $put([$k, is_numeric($v) ? $v : ($v ?? '')]);
        }
        $put([]);
        $put(['Platforms', 'spend', 'revenue', 'conversions', 'roas', 'cpa', 'ctr', 'share']);
        foreach (($data['platforms'] ?? []) as $p) {
            $put([$p['provider'] ?? '', $p['spend'] ?? '', $p['revenue'] ?? '', $p['conversions'] ?? '', $p['roas'] ?? '', $p['cpa'] ?? '', $p['ctr'] ?? '', $p['spend_share'] ?? '']);
        }
        /*
         * The objective split — the same spend, divided by what it was bought for.
         *
         * It is in every audience's sheet because it is the axis the money is judged on, and since
         * CLIENT-REPORT-ENTITY-BOUNDARY-001 it is what a client's export carries INSTEAD of the
         * campaign roster. Paths with no spend are skipped: a row of zeroes for a path nobody bought
         * is a line a reader has to rule out by hand.
         */
        $paths = array_filter(
            $data['objective_performance']['paths'] ?? [],
            fn ($p) => (float) ($p['spend'] ?? 0) > 0,
        );
        if ($paths !== []) {
            $put([]);
            $put(['Objectives', 'spend', 'impressions', 'clicks', 'results', 'cost per result']);
            foreach ($paths as $p) {
                $put([
                    $p['label_en'] ?? $p['path'] ?? '',
                    $p['spend'] ?? '',
                    $p['impressions'] ?? '',
                    $p['clicks'] ?? '',
                    $p['orders'] ?? '',
                    // A path never bought for a result has no cost per one — empty, not zero.
                    ($p['result_metrics_apply'] ?? false) ? ($p['cpa'] ?? '') : '',
                ]);
            }
        }

        /*
         * The campaign roster, for the audiences that still have one.
         *
         * `ClientReportView` empties this for a client and an executive export, so the section is
         * written only when there is something in it — an INTERNAL export keeps the whole hierarchy,
         * and a client's sheet does not print a heading over nothing, which reads as data that
         * failed to load rather than as a boundary being kept.
         */
        /*
         * The funnel and the budget — sections the Owner's Detailed list names and the file had not
         * carried at all.
         *
         * The CSV wrote KPIs, Platforms, Objectives, Campaigns and Creatives, so «funnel,
         * budget/pacing, detailed tables» reached the deck and never the spreadsheet. That is a gap
         * in the detailed product on its own, and it is also why the two forms could not differ on a
         * CLIENT file: the only form-sensitive section the writer had was Campaigns, which the client
         * boundary removes for that audience anyway. So an executive summary and a detailed report
         * exported byte-identical CSVs to a client — measured, 3411 bytes and 61 lines each.
         *
         * Both are written from the document's own data, which `withoutHiddenSections` has already
         * trimmed to the sections this form contains. A summary therefore has no funnel and no budget
         * to write, and the difference is a consequence of the composition rather than a second rule
         * about forms kept here.
         *
         * A stage nobody reported prints an EMPTY cell, never a zero — the same rule the creative
         * columns follow below, and for the same reason: a spreadsheet zero is a measurement, and
         * this one would be a measurement no platform made.
         */
        if (! empty($data['funnel'])) {
            $put([]);
            $put(['Funnel', 'count', 'step rate', 'cost per', 'exceeds previous']);
            foreach ($data['funnel'] as $stage) {
                $put([
                    $stage['label'] ?? $stage['stage'] ?? '',
                    ($stage['reported'] ?? false) ? ($stage['count'] ?? '') : '',
                    $stage['step_rate'] ?? '',
                    $stage['cost_per'] ?? '',
                    ($stage['exceeds_previous'] ?? false) ? 'yes' : '',
                ]);
            }
        }

        if (! empty($data['budget'])) {
            $put([]);
            $put(['Budget', 'budget', 'spent', 'remaining', 'consumed %', 'pace', 'expected to date', 'projected']);
            foreach ($data['budget'] as $line) {
                /*
                 * A line the money contract could not compare states its figures and NOT its
                 * verdicts. `pacing_basis` is the aggregator's own word for whether the spend and
                 * the budget are in one currency; where it is not `comparable`, a consumed
                 * percentage or a pace would be a ratio of two amounts that are not the same kind
                 * of thing.
                 */
                $comparable = ($line['pacing_basis'] ?? null) === 'comparable';
                $put([
                    $line['provider'] ?? '',
                    $line['budget'] ?? '',
                    $line['spent'] ?? '',
                    $comparable ? ($line['remaining'] ?? '') : '',
                    $comparable ? ($line['consumed_pct'] ?? '') : '',
                    $comparable ? ($line['pace'] ?? '') : '',
                    $comparable ? ($line['expected_to_date'] ?? '') : '',
                    $comparable ? ($line['projected_spend'] ?? '') : '',
                ]);
            }
        }

        if (! empty($data['campaigns'])) {
            $put([]);
            $put(['Campaigns', 'platform', 'spend', 'revenue', 'conversions', 'roas', 'cpa']);
            foreach ($data['campaigns'] as $c) {
                $put([$c['campaign_name'] ?? '—', $c['provider'] ?? '', $c['spend'] ?? '', $c['revenue'] ?? '', $c['conversions'] ?? '', $c['roas'] ?? '', $c['cpa'] ?? '']);
            }
        }
        /*
         * §15.12 — the creative rows, and ONLY when the link put them in `$data`.
         *
         * There is no fallback that reads them from the database here. An export runs on the payload
         * the share sanitised, so a creative the link excluded is not «filtered out of the sheet» —
         * it never reached this method, which is the only version of this that survives someone
         * adding a column later.
         */
        if (! empty($data['creatives'])) {
            $put([]);
            $put(['Creatives', 'platform', 'campaign', 'objective', 'path', 'type', ...self::CREATIVE_COLUMNS]);
            foreach ($data['creatives'] as $c) {
                $put([
                    $c['name'] ?? '—',
                    $c['provider'] ?? '',
                    $c['campaign_name'] ?? '',
                    $c['objective'] ?? '',
                    $c['path'] ?? '',
                    $c['preview']['kind'] ?? '',
                    ...array_map(
                        // A withheld metric is an EMPTY cell, never a zero: a spreadsheet zero is a
                        // measurement, and this one would be a measurement the operator hid.
                        static fn (string $key) => $c['metrics'][$key] ?? '',
                        self::CREATIVE_COLUMNS,
                    ),
                ]);
            }
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

        /*
         * BRAND-ATTRIBUTION-001 — a spreadsheet's brand surface is its PROPERTIES.
         *
         * There is nowhere in an xlsx to put a mark without turning a data file into a poster, and a
         * banner row would break every parser that reads row 1 as headers. What the format does
         * support is document metadata, so that is where the product signs its work: a file saved,
         * mailed on and opened months later still says what made it and who it was made for.
         *
         * The report's own title and owner stay primary here, exactly as they do on the cover.
         * CSV gets none of this on purpose — the format has no metadata at all, and a comment line
         * would corrupt the data for anything that parses it.
         */
        $book->getProperties()
            ->setCreator((string) config('brand.name', 'CampaignsHub'))
            ->setCompany((string) config('brand.name', 'CampaignsHub'))
            ->setTitle($report->name)
            ->setSubject($report->name)
            ->setDescription(sprintf(
                '%s — generated by %s (%s)',
                $report->name,
                (string) config('brand.name', 'CampaignsHub'),
                (string) config('brand.domain', 'campaignshub.io'),
            ));

        $kpi = $book->getActiveSheet();
        $kpi->setTitle('KPIs');
        $kpi->fromArray(['Metric', 'Value'], null, 'A1');
        $row = 2;
        foreach (($data['kpis'] ?? []) as $k => $v) {
            // Same reason as the CSV above: a coverage block is a nested array, not a cell value.
            if (is_array($v)) {
                continue;
            }

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

        // The objective split — what the money was bought for, and what that cost. Every audience.
        $this->xlsxSheet($book, 'Objectives', ['Objective', 'Spend', 'Impressions', 'Clicks', 'Results', 'Cost per result'], array_map(
            fn ($p) => [
                $p['label_en'] ?? $p['path'] ?? '',
                $p['spend'] ?? null,
                $p['impressions'] ?? null,
                $p['clicks'] ?? null,
                $p['orders'] ?? null,
                ($p['result_metrics_apply'] ?? false) ? ($p['cpa'] ?? null) : null,
            ],
            array_filter(
                $data['objective_performance']['paths'] ?? [],
                fn ($p) => (float) ($p['spend'] ?? 0) > 0,
            ),
        ));

        /*
         * A Campaigns sheet only where there are campaigns — CLIENT-REPORT-ENTITY-BOUNDARY-001.
         *
         * `ClientReportView` empties the roster for a client and an executive workbook, and an empty
         * sheet named «Campaigns» is worse than none: a reader opens it, finds one header row, and
         * concludes the export failed. An INTERNAL workbook still gets the whole hierarchy.
         */
        if (! empty($data['campaigns'])) {
            $cs = $book->createSheet();
            $cs->setTitle('Campaigns');
            $cs->fromArray(['Campaign', 'Platform', 'Spend', 'Revenue', 'Conversions', 'ROAS', 'CPA'], null, 'A1');
            $row = 2;
            foreach ($data['campaigns'] as $c) {
                $cs->fromArray([$c['campaign_name'] ?? '—', $c['provider'] ?? '', $c['spend'] ?? null, $c['revenue'] ?? null, $c['conversions'] ?? null, $c['roas'] ?? null, $c['cpa'] ?? null], null, "A{$row}");
                $row++;
            }
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
        $this->budgetSheet($add, $data);

        /*
         * §15.12 — a Creatives sheet, only when the link put creatives in `$data`.
         *
         * Created rather than created-and-hidden, for the same reason every other sheet here is: a
         * hidden sheet in a workbook is one right-click away from being visible, and the figures are
         * still in the file. What the client cannot see must not be in the bytes they were sent.
         */
        if (! empty($data['creatives'])) {
            $add(
                'Creatives',
                ['Creative', 'Platform', 'Campaign', 'Objective', 'Path', 'Type', ...self::CREATIVE_COLUMNS],
                array_map(static fn ($c) => [
                    $c['name'] ?? '—',
                    $c['provider'] ?? '',
                    $c['campaign_name'] ?? '',
                    $c['objective'] ?? '',
                    $c['path'] ?? '',
                    $c['preview']['kind'] ?? '',
                    // null, not 0 — a withheld or unreported figure leaves the cell empty.
                    ...array_map(static fn (string $key) => $c['metrics'][$key] ?? null, self::CREATIVE_COLUMNS),
                ], $data['creatives']),
            );
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
        /*
         * CLIENT-DIAGNOSTIC-SEPARATION-001 — the manifest says what covers THEIR period, not ours.
         *
         * «Data source: daily_metrics» is the name of one of our database tables, and it was written
         * into the manifest of every exported file including the client's own. A reader cannot act on
         * it, cannot ask anyone to change it, and `daily_metrics` is not a sentence in any language
         * they were sold. The same line was removed from the shared report page in this closure; this
         * is the copy they download and keep, which is the version that gets forwarded.
         *
         * The attribution window goes with it for the client: ATTRIBUTION-WINDOW-001 records that no
         * connector has ever set one, so the column carries the literal `default` and this row
         * printed «—» or a word that discloses nothing while looking authoritative.
         *
         * Both stay for an INTERNAL export, where they are exactly the point — an operator
         * reconciling a figure needs to know which table and which window produced it.
         */
        if (($report->audience ?? 'client') === 'internal') {
            $rows[] = ['Data source / مصدر البيانات', (string) $report->data_source];
            $rows[] = ['Attribution window / نافذة الإسناد', (string) ($report->attribution_window ?? '—')];
        }
        $rows[] = ['Currency / العملة', (string) $report->currency];
        $rows[] = ['Timezone / المنطقة الزمنية', (string) $report->timezone];
        $rows[] = ['Report mode / وضع التقرير', ($report->config['mode'] ?? 'snapshot') === 'live' ? 'Live' : 'Snapshot'];
        $rows[] = ['Generated at / تاريخ الإنشاء', (string) (optional($report->generated_at)->toDateTimeString() ?? now()->toDateTimeString())];
        $rows[] = ['File created / إنشاء الملف', now()->toDateTimeString()];

        return $rows;
    }

    /**
     * The report the readiness gate judges — the STORED snapshot, never a link's redacted copy.
     *
     * SHARED-PDF-HIDE-FLAGS-001. A link hiding spend hands this class a replica whose spend is null,
     * and the consistency rules read that as «results with zero spend» and refused the export: every
     * file of every link that hides spend answered 422. Whether a snapshot is consistent is a fact
     * about the snapshot; what a link may show of it is decided afterwards.
     */
    private function stored(Report $report, ?ReportShare $share): Report
    {
        if ($share === null) {
            return $report;
        }

        return Report::withoutGlobalScopes()->find($report->getKey()) ?? $report;
    }

    private function pdf(Report $report, array $data, ?ReportShare $share = null): string
    {
        // Creative Arabic reports render via headless Chromium over the print route (correct RTL, real
        // charts, fonts). A failure throws so the export is marked Failed — never a broken/partial file.
        if ($this->chromium->isEnabled()) {
            $type = ($report->config['pdf_type'] ?? 'presentation') === 'document' ? 'document' : 'presentation';

            // A shared link's file prints from that link's filtered document — SHARED-PDF-HIDE-FLAGS-001.
            return $this->chromium->render($report, $type, share: $share);
        }

        // FAIL-CLOSED for client-facing reports: the Dompdf fallback is text-first and cannot render the
        // creative RTL layout, charts, or a client-safe presentation. A client/executive PDF must never
        // silently degrade to it — that is exactly how a broken legacy file reaches a client. Only plain
        // INTERNAL text reports may use Dompdf, and only when Chromium is genuinely unavailable.
        $audience = $report->audience ?? 'client';
        if (in_array($audience, ['client', 'executive'], true)) {
            throw new RuntimeException(
                'Client PDF export requires the Chromium renderer, which is disabled '.
                '(set REPORTS_CHROMIUM_ENABLED=true). Refusing to ship a Dompdf fallback to a client.'
            );
        }

        // Fallback: the simple Dompdf document layout (INTERNAL text reports only).
        return Pdf::loadView('reports.document', [
            'report' => $report,
            'data' => $data,
        ])->setPaper('a4')->output();
    }

    /**
     * BUDGET-GOVERNANCE-001 — the sheet carries the same columns the table does.
     *
     * The budget rungs gained «expected to date», «daily average» and «over / under», and this kept
     * exporting five columns. An owner replacing a spreadsheet opens the export and finds fewer
     * figures than the page they exported it from — so the spreadsheet stays, which is the whole
     * thing the export exists to end.
     *
     * A refused figure exports as an EMPTY cell rather than a zero: the money contract's rule wearing
     * a spreadsheet's clothes, because `0` is a claim and blank is an absence.
     *
     * Extracted so it can be exercised directly — the parity test opens whole workbooks, which tells
     * you the two formats agree and nothing about which columns are in them.
     *
     * @param  callable(string, list<string>, list<list<mixed>>): void  $add
     * @param  array<string, mixed>  $data
     */
    private function budgetSheet(callable $add, array $data): void
    {
        if (empty($data['budget'])) {
            return;
        }

        // Per PLATFORM since CLIENT-REPORT-ENTITY-BOUNDARY-001; `provider` is what the rows carry.
        $add(
            'Budget',
            ['Platform', 'Budget', 'Spent', 'Remaining', 'Consumed', 'Expected To Date', 'Daily Average', 'Pace', 'Projected', 'Over / Under'],
            array_map(
                fn ($b) => [
                    $b['provider'] ?? '',
                    $b['budget'] ?? null,
                    $b['spent'] ?? null,
                    $b['remaining'] ?? null,
                    $b['consumed_pct'] ?? null,
                    $b['expected_to_date'] ?? null,
                    $b['daily_average'] ?? null,
                    $b['pace'] ?? null,
                    $b['projected_spend'] ?? null,
                    $b['over_under'] ?? null,
                ],
                $data['budget'],
            ),
        );
    }
}
