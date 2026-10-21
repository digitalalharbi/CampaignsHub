<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * MATRIX-INTEGRITY-001 — a scripted edit cannot silently shift a requirement's columns.
 *
 * Two real corruptions motivated this. A `|` inside a cell (an Arabic filter list, a shell snippet, a
 * `grep "a|b"`) split the row, so everything after it moved one column to the right and the Status
 * column read a test name while the status sat under «Commit». And an append script that counted
 * columns from the wrong index wrote the status INTO the commit column of three rows (#785). The
 * vocabulary guard beside this one reads the status cell by position, so it could not see either.
 *
 * This reads every table in the matrix and the owner-defect ledger against its own header: the same
 * number of cells (a `\|` is content, a bare `|` is a column), the status column holding a bold
 * canonical token and nothing else before its qualifier, and no OTHER column holding a bare status
 * token — which is what an off-by-one looks like from the outside.
 */
final class MatrixTableIntegrityTest extends TestCase
{
    private const STATUSES = [
        'VERIFIED', 'PARTIAL', 'IMPLEMENTED_NOT_VERIFIED', 'DEMO', 'AWAITING_CREDENTIALS',
        'BLOCKED_EXTERNAL_CREDENTIALS', 'BLOCKED_OPERATIONAL_EVIDENCE', 'NOT_STARTED', 'IN_PROGRESS',
    ];

    public function test_every_matrix_row_has_exactly_its_tables_columns(): void
    {
        $this->assertSame([], $this->columnFaults($this->docPath('REQUIREMENTS_TRACEABILITY_MATRIX.md')));
    }

    public function test_every_owner_defect_row_has_exactly_its_tables_columns(): void
    {
        $this->assertSame([], $this->columnFaults($this->docPath('OWNER_OBSERVED_DEFECTS.md')));
    }

    public function test_a_requirement_rows_status_sits_in_the_status_column_and_nowhere_else(): void
    {
        $faults = [];
        foreach ($this->tables($this->docPath('REQUIREMENTS_TRACEABILITY_MATRIX.md')) as $table) {
            $statusIndex = $this->statusIndex($table['header']);
            if ($statusIndex === null) {
                continue;
            }
            foreach ($table['rows'] as [$line, $cells]) {
                if (preg_match('/^[A-Z0-9][A-Z0-9-]+-\d{3}/', $cells[0]) !== 1 || count($cells) !== count($table['header'])) {
                    continue; // counted by the test above
                }
                if (preg_match('/^\*\*('.implode('|', self::STATUSES).')\*\*(\s*\(.*\))?$/su', $cells[$statusIndex]) !== 1) {
                    $faults[] = "line {$line} ({$cells[0]}): status column reads «".mb_substr($cells[$statusIndex], 0, 60).'»';
                }
                foreach ($cells as $i => $cell) {
                    if ($i !== $statusIndex && preg_match('/^\*\*('.implode('|', self::STATUSES).')\*\*$/', $cell) === 1) {
                        $faults[] = "line {$line} ({$cells[0]}): column {$i} holds the bare status {$cell} — an off-by-one";
                    }
                }
            }
        }
        $this->assertSame([], $faults);
    }

    /** The parser itself: an escaped pipe is content, a bare one is a column. */
    public function test_an_escaped_pipe_is_content(): void
    {
        $this->assertSame(['a \| b', 'c'], $this->cells('| a \| b | c |'));
        $this->assertSame(['a', 'b', 'c'], $this->cells('| a | b | c |'));
    }

    /** @return list<string> */
    private function columnFaults(string $path): array
    {
        $faults = [];
        foreach ($this->tables($path) as $table) {
            $expected = count($table['header']);
            foreach ($table['rows'] as [$line, $cells]) {
                if (count($cells) !== $expected) {
                    $faults[] = "line {$line} ({$cells[0]}): ".count($cells)." cells, header has {$expected}";
                }
            }
        }

        return $faults;
    }

    /** @return list<array{header: list<string>, rows: list<array{0:int,1:list<string>}>}> */
    private function tables(string $path): array
    {
        $tables = [];
        $current = null;
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $n => $line) {
            if (! str_starts_with($line, '|')) {
                if ($current !== null) {
                    $tables[] = $current;
                    $current = null;
                }

                continue;
            }
            $cells = $this->cells($line);
            if (preg_match('/^[\s:\-]*$/', implode('', $cells)) === 1) {
                continue; // the ---|--- separator
            }
            if ($current === null) {
                $current = ['header' => $cells, 'rows' => []];

                continue;
            }
            $current['rows'][] = [$n + 1, $cells];
        }
        if ($current !== null) {
            $tables[] = $current;
        }

        return $tables;
    }

    /** @param  list<string>  $header */
    private function statusIndex(array $header): ?int
    {
        foreach ($header as $i => $h) {
            if (str_starts_with(strtolower($h), 'status')) {
                return $i;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function cells(string $line): array
    {
        $inner = preg_replace('/^\s*\|/', '', rtrim($line));
        $inner = preg_replace('/\|\s*$/', '', (string) $inner);

        return array_map('trim', preg_split('/(?<!\\\\)\|/', (string) $inner) ?: []);
    }

    private function docPath(string $file): string
    {
        $path = dirname(__DIR__, 3).'/docs/'.$file;
        $this->assertFileExists($path);

        return $path;
    }
}
