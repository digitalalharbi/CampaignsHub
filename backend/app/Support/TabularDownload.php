<?php

declare(strict_types=1);

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A table as a CSV or XLSX download. A cell that starts like a formula is quoted as text: a UTM value or a
 * coupon code is typed by whoever built the link, and a spreadsheet would otherwise run it.
 */
final class TabularDownload
{
    /**
     * @param  list<string>  $head
     * @param  list<list<mixed>>  $rows
     */
    public static function stream(string $name, array $head, array $rows, string $format): StreamedResponse
    {
        $lines = array_map(static fn (array $row): array => array_map(
            static fn (mixed $v): mixed => is_string($v) && $v !== '' && str_contains('=+-@', $v[0]) ? "'".$v : $v,
            $row,
        ), $rows);

        if ($format === 'xlsx') {
            return response()->streamDownload(static function () use ($head, $lines): void {
                $book = new Spreadsheet;
                $book->getActiveSheet()->fromArray([$head, ...$lines], null, 'A1');
                (new Xlsx($book))->save('php://output');
            }, $name.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(static function () use ($head, $lines): void {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, $head, ',', '"', '');
            foreach ($lines as $line) {
                fputcsv($fh, $line, ',', '"', '');
            }
            fclose($fh);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
