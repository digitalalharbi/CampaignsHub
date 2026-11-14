<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

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
    public const SUMMARY_HEAD = ['client', 'project', 'payment_method', 'approved_sar', 'spent_sar', 'remaining_sar', 'status', 'days_left', 'spend_up_to'];

    public const MOVEMENTS_HEAD = ['date', 'client', 'project', 'kind', 'amount', 'currency', 'amount_sar', 'rate', 'rate_date', 'reference', 'note', 'added_by', 'added_at'];

    /**
     * @param  list<list<mixed>>  $summary
     * @param  list<list<mixed>>  $movements
     */
    public function xlsx(array $summary, array $movements, string $period, string $asOf): string
    {
        $book = new Spreadsheet;
        $first = $book->getActiveSheet();
        $first->setTitle('summary');
        // The figures are as of today; the period narrows the movements only.
        $this->rows($first, [['as_of', $asOf], ['movements_period', $period], [], self::SUMMARY_HEAD, ...$summary]);

        $sheet = $book->createSheet();
        $sheet->setTitle('movements');
        $this->rows($sheet, [self::MOVEMENTS_HEAD, ...$movements]);

        $tmp = tempnam(sys_get_temp_dir(), 'funding');
        (new Xlsx($book))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
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
