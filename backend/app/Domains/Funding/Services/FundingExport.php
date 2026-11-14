<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Funding\Support\FundingDisclaimer;
use App\Domains\Funding\Support\FundingMoney;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * FIN-LOOP-001 part 3 — the budgets as a file: one row per project (the figures) and one row per
 * approved amount (date, reference, who added it). XLSX carries both as two sheets, every text cell an
 * explicit string; CSV carries the one asked for, with formula-like text prefixed so it stays text.
 */
final class FundingExport
{
    public const SUMMARY_HEAD = ['client', 'project', 'payment_method', 'approved_sar', 'spent_sar', 'remaining_sar', 'status', 'days_left', 'spend_up_to', 'period_start', 'period_end', 'platforms_usd', 'payment_mode', 'ceiling_sar', 'to_invoice_sar', 'this_month_sar', 'plan_vs_actual_usd'];

    /** BUDGET-MODES-001 — a credit budget's spend by month (what is invoiced from), and a planned budget's plan against actual. */
    public const INVOICE_HEAD = ['client', 'project', 'month', 'from', 'to', 'closed', 'spent_sar', 'unconverted_days', 'platforms_sar'];

    public const PLAN_HEAD = ['client', 'project', 'platform', 'planned_pct', 'planned_usd', 'spent_usd', 'share_used_pct', 'flag'];

    public const MOVEMENTS_HEAD = ['date', 'client', 'project', 'kind', 'amount', 'currency', 'amount_sar', 'rate', 'rate_date', 'reference', 'note', 'added_by', 'added_at', 'reason'];

    /**
     * @param  list<list<mixed>>  $summary
     * @param  list<list<mixed>>  $movements
     * @param  list<list<mixed>>  $invoice
     * @param  list<list<mixed>>  $plan
     * @param  array<string, mixed>  $statement  `FundingStatement::block()` — the identity rows that open the first sheet
     */
    public function xlsx(array $summary, array $movements, string $period, string $asOf, array $invoice = [], array $plan = [], array $statement = []): string
    {
        $book = new Spreadsheet;
        $first = $book->getActiveSheet();
        $first->setTitle('summary');
        // The figures are as of today; the period narrows the movements only.
        $lead = [...$this->identity($statement), ['as_of', $asOf], ['movements_period', $period], []];
        $this->rows($first, [...$lead, self::SUMMARY_HEAD, ...$summary]);
        $this->marks($first, self::SUMMARY_HEAD, count($lead));

        $sheet = $book->createSheet();
        $sheet->setTitle('movements');
        $this->rows($sheet, [self::MOVEMENTS_HEAD, ...$movements]);
        // The ledger row states its own currency (`currency` column); the SAR value carries the mark.
        $this->marks($sheet, self::MOVEMENTS_HEAD, 0);

        $byMonth = $book->createSheet();
        $byMonth->setTitle('invoice_months');
        $this->rows($byMonth, [self::INVOICE_HEAD, ...$invoice]);
        $this->marks($byMonth, self::INVOICE_HEAD, 0);

        // BUDGET-DISCLAIMER-001 — every sheet that carries money says it is not an accounting record.
        foreach ([$sheet, $byMonth] as $ws) {
            $ws->setCellValueExplicit('A'.($ws->getHighestRow() + 2), FundingDisclaimer::text((string) ($statement['lang'] ?? 'ar')), DataType::TYPE_STRING);
        }
        $planned = $book->createSheet();
        $planned->setTitle('plan_vs_actual');
        $this->rows($planned, [self::PLAN_HEAD, ...$plan]);
        $this->marks($planned, self::PLAN_HEAD, 0);

        $planned->setCellValueExplicit('A'.($planned->getHighestRow() + 2), FundingDisclaimer::text((string) ($statement['lang'] ?? 'ar')), DataType::TYPE_STRING);

        $tmp = tempnam(sys_get_temp_dir(), 'funding');
        (new Xlsx($book))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * BUDGET-CURRENCY-001 — a column whose header ends in `_sar` / `_usd` shows its mark after every number (a spreadsheet number
     * format, so the cell stays a number): ر.س for SAR, $ for USD.
     *
     * @param  list<string>  $head
     */
    private function marks(Worksheet $sheet, array $head, int $headRow): void
    {
        $last = $sheet->getHighestRow();
        foreach ($head as $c => $name) {
            if (preg_match('/_(sar|usd)$/', $name, $m) !== 1) {
                continue;
            }
            $col = Coordinate::stringFromColumnIndex($c + 1);
            $sheet->getStyle($col.($headRow + 1).':'.$col.$last)->getNumberFormat()->setFormatCode(FundingMoney::excelFormat(strtoupper($m[1])));
        }
    }

    /**
     * BUDGET-STATEMENT-001 — the rows that open the first sheet: the statement, who issued it, about whom, when, by whom, the
     * currency and the note of the disclaimer. Only fields that exist; an absent one is simply not a row.
     *
     * @param  array<string, mixed>  $s
     * @return list<list<mixed>>
     */
    private function identity(array $s): array
    {
        if ($s === []) {
            return [['note', FundingDisclaimer::text('ar')], []];
        }
        $rows = [['statement', $s['title']], ['organisation', $s['agency']['name']]];
        foreach ([['client', $s['client']], ['project', $s['project']], ['mode', $s['mode']]] as [$k, $v]) {
            if ($v !== null && $v !== '') {
                $rows[] = [$k, $v];
            }
        }
        $rows[] = ['statement_period', $s['period']];
        $rows[] = ['currency', $s['currency']];
        $rows[] = ['fx_note', $s['fx_note']];
        $rows[] = ['generated_at', $s['generated_at'].' Asia/Riyadh'];
        $rows[] = ['generated_by', $s['generated_by']];
        $rows[] = ['note', $s['disclaimer']];
        $rows[] = [];

        return $rows;
    }

    /**
     * Every text cell is written as an explicit string: Excel never evaluates it, and nothing has to be
     * prefixed (a prefix would show as a literal character in the cell).
     *
     * @param  list<list<mixed>>  $rows
     */
    private function rows(Worksheet $sheet, array $rows): void
    {
        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                $cell = Coordinate::stringFromColumnIndex($c + 1).($r + 1);
                if (is_int($value) || is_float($value)) {
                    $sheet->setCellValue($cell, $value);
                } elseif ($value !== null) {
                    $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                }
            }
        }
    }

    /**
     * @param  list<string>  $head
     * @param  list<list<mixed>>  $rows
     */
    public function csv(array $head, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // Excel reads Arabic CSV as UTF-8 only with the mark
        fputcsv($fh, $head, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($fh, array_map($this->cell(...), $row), ',', '"', '');
        }
        rewind($fh);
        $out = (string) stream_get_contents($fh);
        fclose($fh);

        return $out;
    }

    /** CSV only: text that a spreadsheet would read as a formula is prefixed so it stays text. */
    private function cell(mixed $v): mixed
    {
        if (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($v)) {
            return "'".$v;
        }

        return $v;
    }
}
