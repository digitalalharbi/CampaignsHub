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
 * say belongs in the NOTES, where there is room to say it properly.
 *
 * **This test used to have the hole it exists to close.** Its reader matched `^\*\*([A-Z_]+)\*\*$`,
 * so it only ever saw cells that were ALREADY canonical in shape. Seventeen rows reading
 * `**VERIFIED (Awaiting Credentials)**`, `**VERIFIED LOCAL**`, `**CLOSED — see §51**` and
 * `**OPEN — next defect**` matched nothing, were skipped in silence, and the suite stayed green
 * across every one of them. A drift check that can only see the values it approves of reports
 * "no drift" for the entire class of drift that actually happens — a canonical word with a qualifier
 * stapled on, which reads as canonical at a glance and is exactly what an author reaches for.
 *
 * The reader now recognises a status cell by its SHAPE — wholly bold, short, and claiming one of the
 * status words — and then insists it be exactly one of the seven. `test_the_reader_sees_a_status_it_
 * does_not_approve_of` is what keeps that honest.
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

        foreach (self::statusCells(file($this->matrixPath())) as [$id, $status, $trailing]) {
            if (! in_array($status, self::CANONICAL, true)) {
                $offenders[] = "{$id} → {$status}";

                continue;
            }

            /*
             * And a canonical word does not make the cell canonical: `**VERIFIED** — *Awaiting
             * Credentials*` qualifies the status from OUTSIDE the bold, which filters as VERIFIED
             * and reads as something else. Evidence may trail a status; a second opinion about it
             * may not.
             */
            if (preg_match('/\b(AWAITING|PENDING|LOCAL|READY|BLOCKED|PARTIAL|CLOSED|NOT VERIFIED)\b/i', $trailing) === 1) {
                $offenders[] = "{$id} → {$status} {$trailing}";
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
        $this->assertGreaterThan(400, count(self::statusCells(file($this->matrixPath()))));
    }

    /**
     * And it sees a status it does NOT approve of — the whole point, and the part that was missing.
     *
     * Fed against the real file this proves nothing, because the real file is clean by the time this
     * runs. So it is fed the four shapes that actually got through, in the two table widths the
     * document uses (nine columns, and the narrower five-column tables where the status sits in a
     * different place). Every one must come back named.
     */
    public function test_the_reader_sees_a_status_it_does_not_approve_of(): void
    {
        $drifted = [
            '| Req ID | Area | Requirement | Backend | Frontend | Tests | Status | Commit | Notes |',
            '|---|---|---|---|---|---|---|---|---|',
            '| A-001 | AREA | Requirement | ✓ | ✓ | tests | **VERIFIED (Awaiting Credentials)** | abc1234 | note |',
            '| A-002 | AREA | Requirement | ✓ | ✓ | tests | **VERIFIED LOCAL** | abc1234 | note |',
            '| A-003 | AREA | Requirement | — | — | — | **CLOSED — see §51** | abc1234 | note |',
            '| A-004 | AREA | Requirement | — | — | — | **OPEN — next defect** | — | note |',
            '',
            '| Req ID | Requirement | Status | Evidence | Notes |',
            '|---|---|---|---|---|',
            '| A-005 | A five-column table, where the status sits somewhere else | **READY_FOR_CREDENTIALS** | ev | note |',
        ];

        $found = self::statusCells($drifted);

        $this->assertSame(
            ['A-001', 'A-002', 'A-003', 'A-004', 'A-005'],
            array_column($found, 0),
            'a drifted status was skipped instead of reported — the reader is blind in exactly the way it was before',
        );

        foreach ($found as [$id, $status]) {
            $this->assertNotContains($status, self::CANONICAL, "{$id} was read as canonical when it is not");
        }
    }

    /** A status qualified from outside its bold is drift too, and reads as canonical to a filter. */
    public function test_a_qualifier_outside_the_bold_is_reported(): void
    {
        $rows = [
            '| Req ID | Requirement | Status | Notes |',
            '|---|---|---|---|',
            '| C-001 | Qualified from outside | **VERIFIED** — *Awaiting Credentials* | note |',
            '| C-002 | Evidence, not a qualifier | **VERIFIED** (13 tests) | note |',
        ];

        $found = array_column(self::statusCells($rows), 2, 0);

        $this->assertSame('— *Awaiting Credentials*', $found['C-001']);
        $this->assertSame('(13 tests)', $found['C-002']);
    }

    /** And it does not mistake a bold phrase inside a NOTE for a status. */
    public function test_a_bold_phrase_in_a_note_is_not_read_as_a_status(): void
    {
        $rows = [
            '| Req ID | Area | Requirement | Backend | Frontend | Tests | Status | Commit | Notes |',
            '|---|---|---|---|---|---|---|---|---|',
            '| B-001 | AREA | Requirement | ✓ | ✓ | tests | **VERIFIED** | abc1234 | '
                .'**Recorded as a question, not asserted as a defect.** The row above was verified; this one repeats '
                .'the word verified at length so that a reader-by-keyword would trip over it. |',
        ];

        $this->assertSame([['B-001', 'VERIFIED', '']], self::statusCells($rows));
    }

    /**
     * Status cells, located by the table's own header rather than by what the cell says.
     *
     * The previous reader matched `^\*\*([A-Z_]+)\*\*$` and therefore only ever saw cells that were
     * already canonical in shape; seventeen qualifier-stapled statuses were skipped in silence. The
     * obvious repair — look for cells CONTAINING a status word — is only half a repair, and its own
     * hole is visible in the fixture above: `READY_FOR_CREDENTIALS` shares no word with any of the
     * seven, so a keyword reader walks straight past it. An invented status is precisely the case
     * where you cannot predict the vocabulary.
     *
     * So the column is found from the header — every table in this document declares `Status` — and
     * whatever stands in that column is the status, whatever it happens to say.
     *
     * @param  list<string>  $lines
     * @return list<array{0: string, 1: string, 2: string}> id, status, and whatever the cell says after it
     */
    private static function statusCells(array $lines): array
    {
        $out = [];
        $width = null;
        $column = null;

        foreach ($lines as $line) {
            if (! str_starts_with($line, '| ')) {
                /*
                 * Anything that is not a table row ends the table, and with it the column it
                 * declared — a BLANK LINE included: §SIGNUP-006 puts a three-column `Portal | Account
                 * | Notes` table directly under a three-column status table, and a reader that
                 * carried the column across the gap read «Notes» as a status.
                 *
                 * The `|---|---|` separator is the one exception; it is part of the table, and
                 * forgetting that is how this reader first came back empty for every row.
                 */
                if (! str_starts_with(trim($line), '|')) {
                    $width = $column = null;
                }

                continue;
            }

            $cells = explode('|', $line);
            $trimmed = array_map(trim(...), $cells);

            if (in_array('Status', $trimmed, true)) {
                $width = count($cells);
                $column = array_search('Status', $trimmed, true);

                continue;
            }

            if ($column === null || count($cells) !== $width) {
                continue;
            }

            $id = $trimmed[1];

            if (preg_match('/^[A-Z][A-Za-z0-9._-]*$/', $id) !== 1) {
                continue;
            }

            $value = $trimmed[$column];

            if ($value === '' || $value === '—') {
                continue;
            }

            /*
             * Many rows write the status and then, in the same cell, the evidence for it:
             * `**VERIFIED** (13 tests)`. That is untidy, not drift — the status word itself is
             * canonical and a reader filtering on it finds the row. So the status is the leading
             * bold token, and what follows is returned separately for the caller to judge.
             */
            if (preg_match('/^\*\*(.+?)\*\*(.*)$/s', $value, $m) === 1) {
                $out[] = [$id, trim($m[1]), trim($m[2])];

                continue;
            }

            $out[] = [$id, $value, ''];
        }

        return $out;
    }

    private function matrixPath(): string
    {
        $path = dirname(__DIR__, 3).'/docs/REQUIREMENTS_TRACEABILITY_MATRIX.md';
        $this->assertFileExists($path);

        return $path;
    }
}
