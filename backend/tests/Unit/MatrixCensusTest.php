<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The matrix's census: how many requirements exist and in which state.
 *
 * The census was reported wrong twice in a row — 965 rows and 632 VERIFIED, when the file holds 902
 * requirements and 574 VERIFIED. The count matched every bold status word anywhere on a line, so a
 * row whose NOTES quote another status («this was **PARTIAL** until …») was counted under both, and
 * the same requirement written into a closing ledger a second time was counted twice. Neither is a
 * rounding error; both make «how much is left» unanswerable, which is the one number the matrix is
 * for.
 *
 * So the census is defined here, once: a requirement is a row of a table whose header names a
 * `Status` column; its state is the content of THAT cell and nothing else; its id appears in exactly
 * one row. A later restatement belongs in the existing row's notes, not in a second row — the
 * acceptance criteria stay, the double count goes.
 *
 * A plain PHPUnit test: it reads a file and needs no application.
 */
final class MatrixCensusTest extends TestCase
{
    private const CANONICAL = [
        'NOT_STARTED',
        'IN_PROGRESS',
        'PARTIAL',
        'IMPLEMENTED_NOT_VERIFIED',
        'VERIFIED',
        'BLOCKED_EXTERNAL_CREDENTIALS',
        'BLOCKED_OPERATIONAL_EVIDENCE',
    ];

    public function test_every_requirement_id_appears_in_exactly_one_row(): void
    {
        $seen = [];

        foreach (self::census(self::matrix()) as [$id, , $line]) {
            $seen[$id][] = $line;
        }

        $duplicated = array_filter($seen, static fn (array $lines): bool => count($lines) > 1);

        $this->assertSame(
            [],
            array_map(static fn (array $lines): string => 'lines '.implode(', ', $lines), $duplicated),
            "A requirement counted twice is a requirement reported wrongly. Fold the later row into the first\n"
            .'row\'s notes («Also recorded under …») instead of keeping a second row.',
        );
    }

    public function test_the_status_cell_holds_the_status_and_nothing_else(): void
    {
        $offenders = [];

        foreach (self::census(self::matrix()) as [$id, $cell, $line]) {
            if (preg_match('/^\*\*('.implode('|', self::CANONICAL).')\*\*$/', $cell) !== 1) {
                $offenders[] = "{$id} (line {$line}) → {$cell}";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Evidence belongs in the notes. A status cell with a parenthesis after it filters as VERIFIED and\n"
            .'reads as something else.',
        );
    }

    public function test_the_counts_add_up_to_the_requirements(): void
    {
        $rows = self::census(self::matrix());
        $counts = self::countByStatus($rows);

        $this->assertSame(count($rows), array_sum($counts), 'Every requirement is counted once and only once.');
        $this->assertSame([], array_diff(array_keys($counts), self::CANONICAL));
        $this->assertGreaterThan(850, count($rows), 'The census reader has stopped seeing the matrix.');
    }

    public function test_a_status_quoted_in_the_notes_is_not_counted(): void
    {
        $rows = self::census(
            "| ID | Requirement | Status | Notes |\n"
            ."| --- | --- | --- | --- |\n"
            ."| A-1 | one | **VERIFIED** | It was **PARTIAL** until the sweep, then **IN_PROGRESS**. |\n",
        );

        $this->assertSame(['VERIFIED' => 1], self::countByStatus($rows));
    }

    public function test_a_row_restated_in_a_second_table_is_seen_twice(): void
    {
        $rows = self::census(
            "| ID | Requirement | Status |\n| --- | --- | --- |\n| A-1 | one | **VERIFIED** |\n\n"
            ."| ID | Detail | Status |\n| --- | --- | --- |\n| A-1 | one, again | **VERIFIED** |\n",
        );

        $this->assertSame(['A-1', 'A-1'], array_column($rows, 0));
    }

    public function test_a_table_without_a_status_column_is_not_a_requirement_table(): void
    {
        $rows = self::census(
            "| ID | Why | Fix |\n| --- | --- | --- |\n| A-1 | x | y |\n\n"
            ."| Defect | Why | Fix |\n| --- | --- | --- |\n| `thing` | z | w |\n",
        );

        $this->assertSame([], $rows);
    }

    /**
     * @return list<array{0: string, 1: string, 2: int}> [id, status cell, line number]
     */
    private static function census(string $markdown): array
    {
        $out = [];
        $width = $column = null;

        foreach (explode("\n", $markdown) as $n => $line) {
            if (! str_starts_with($line, '|')) {
                $width = $column = null;

                continue;
            }

            $cells = array_map(trim(...), preg_split('/(?<!\\\\)\|/', $line) ?: []);

            if (in_array('Status', $cells, true)) {
                $width = count($cells);
                $column = array_search('Status', $cells, true);

                continue;
            }

            if ($column === null || count($cells) !== $width) {
                continue;
            }

            if (preg_match('/^[A-Z][A-Za-z0-9._-]*$/', $cells[1]) !== 1) {
                continue;
            }

            $out[] = [$cells[1], $cells[$column], $n + 1];
        }

        return $out;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $rows
     * @return array<string, int>
     */
    private static function countByStatus(array $rows): array
    {
        $counts = [];

        foreach ($rows as [, $cell]) {
            $status = trim($cell, '*');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    private static function matrix(): string
    {
        $path = dirname(__DIR__, 3).'/docs/REQUIREMENTS_TRACEABILITY_MATRIX.md';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
