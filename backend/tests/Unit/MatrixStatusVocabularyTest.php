<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The matrix's status column admits SEVEN words, and this is what stops it admitting an eighth.
 *
 * Five private statuses had accumulated — `LIVE_VERIFIED`, `READY_FOR_CREDENTIALS`,
 * `BLOCKED_PRE_EXISTING_DEFECT`, `BLOCKED_PRODUCT_DECISION`, `NOT_A_DEFECT` — each invented in the
 * moment by somebody who found the canonical set too small for what they had just learned. Every one
 * of them was a reasonable thing to want to say. None of them was a status: a word only one row uses
 * cannot be filtered, counted or trusted, and a ledger whose vocabulary grows per author stops being
 * a ledger and becomes prose with a table around it.
 *
 * The fix is not to police the impulse but to redirect it: whatever the invented status was trying to
 * say belongs in the NOTES, where there is room to say it properly. That is what the twenty-one
 * normalised rows now do, and this test is what keeps the next one from taking the shortcut.
 *
 * A plain PHPUnit test, not a Laravel one: it reads a file and needs no application.
 */
final class MatrixStatusVocabularyTest extends TestCase
{
    /** The only statuses the matrix admits. Changing this list is a deliberate act, not a fix. */
    private const CANONICAL = [
        'NOT_STARTED',
        'IN_PROGRESS',
        'PARTIAL',
        'IMPLEMENTED_NOT_VERIFIED',
        'VERIFIED',
        'BLOCKED_EXTERNAL_CREDENTIALS',
        'BLOCKED_OPERATIONAL_EVIDENCE',
    ];

    public function test_every_row_states_a_canonical_status(): void
    {
        $offenders = [];

        foreach ($this->statusCells() as [$id, $status]) {
            if (! in_array($status, self::CANONICAL, true)) {
                $offenders[] = "{$id} → {$status}";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'The matrix admits only '.implode(' · ', self::CANONICAL).".\n"
            ."Whatever these were trying to say belongs in the notes:\n  ".implode("\n  ", $offenders),
        );
    }

    /**
     * The reader is checking something.
     *
     * A parser that silently matched nothing would make the test above pass forever — which is the
     * same failure mode as the vocabulary drift it exists to catch, one level up.
     */
    public function test_the_reader_actually_finds_the_statuses(): void
    {
        $this->assertGreaterThan(400, count($this->statusCells()));
    }

    /** @return list<array{0: string, 1: string}> id and status, for every row that states one */
    private function statusCells(): array
    {
        $path = dirname(__DIR__, 3).'/docs/REQUIREMENTS_TRACEABILITY_MATRIX.md';
        $this->assertFileExists($path);

        $out = [];

        foreach (file($path) as $line) {
            if (! str_starts_with($line, '| ')) {
                continue;
            }

            $cells = explode('|', $line);

            // A data row: id, area, requirement, code, review, test, status, commit, notes.
            if (count($cells) < 10) {
                continue;
            }

            if (preg_match('/^\*\*([A-Z_]+)\*\*$/', trim($cells[7]), $m) === 1) {
                $out[] = [trim($cells[1]), $m[1]];
            }
        }

        return $out;
    }
}
